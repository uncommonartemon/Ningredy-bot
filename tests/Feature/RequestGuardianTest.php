<?php

namespace Tests\Feature;

use App\Ai\Agents\SearchProgressAgent;
use App\Jobs\DeliverTelegramAssistantReply;
use App\Jobs\ProcessTelegramMessage;
use App\Models\AiOperation;
use App\Models\AiRun;
use App\Models\ProductDraft;
use App\Models\ProductSourceAttempt;
use App\Models\TelegramUpdate;
use App\Services\ProductSearch\Recovery\RequestGuardian;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class RequestGuardianTest extends TestCase
{
    use RefreshDatabase;

    private int $sequence = 66000;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Queue::fake();
        Storage::fake('public');
        Http::preventStrayRequests();
        config(['services.telegram.bot_token' => 'test-token', 'services.telegram.webhook_secret' => 'test-secret',
            'services.telegram.allowed_user_ids' => ['123']]);
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 700]])]);
    }

    public function test_a_request_waiting_behind_another_search_hears_once_that_it_is_queued(): void
    {
        $running = $this->update('processing', 6);
        $this->job($running, reservedMinutesAgo: 5, createdMinutesAgo: 6);
        $waiting = $this->update('received', 2);
        $this->job($waiting, reservedMinutesAgo: null, createdMinutesAgo: 2);

        $this->sweep();
        $this->sweep();

        $notices = Http::recorded(fn (Request $request): bool => str_contains((string) ($request['text'] ?? ''), 'ждёт очереди'));
        $this->assertCount(1, $notices);
        $this->assertStringContainsString('идёт 5 мин', $notices->first()[0]['text']);
        $this->assertSame("search:cancel:{$waiting->id}", data_get($notices->first()[0]->data(), 'reply_markup.inline_keyboard.0.0.callback_data'));
    }

    public function test_nothing_is_said_about_a_queue_with_nothing_running_ahead(): void
    {
        $waiting = $this->update('received', 3);
        $this->job($waiting, reservedMinutesAgo: null, createdMinutesAgo: 3);

        $this->sweep();

        Http::assertNothingSent();
    }

    public function test_a_stalled_step_with_saved_photos_is_shown_when_the_watcher_says_so(): void
    {
        [$update, $draft] = $this->stalledSearch(silentMinutes: 7, withDraft: true);
        SearchProgressAgent::fake([['decision' => 'deliver_now', 'reason' => 'Шаг молчит, а пять фото уже сохранены.']])->preventStrayPrompts();

        $this->sweep();

        $update->refresh();
        $this->assertNotNull($update->cancel_requested_at);
        $this->assertSame(['completed', true], [$update->status, $update->processed_at !== null]);
        $this->assertNotNull(data_get($update->payload, '_guard_delivered_at'));
        $this->assertSame('stalled', $draft->fresh()->gallery_search_stop_reason);
        $this->assertSentText('Поиск застрял на шаге «поиск карточки товара»');
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/sendPhoto'));
        $this->assertDatabaseHas('product_source_attempts', ['telegram_update_id' => $update->id, 'phase' => 'guardian', 'action' => 'show_found']);
    }

    public function test_a_slow_step_is_announced_once_and_the_saved_draft_is_shown_after_a_long_stall(): void
    {
        [$update, $draft] = $this->stalledSearch(silentMinutes: 7, withDraft: true);
        SearchProgressAgent::fake([['decision' => 'continue', 'reason' => 'Шаг может вернуться.']])->preventStrayPrompts();

        $this->sweep();
        $this->sweep();

        $notices = Http::recorded(fn (Request $request): bool => str_contains((string) ($request['text'] ?? ''), 'дольше обычного'));
        $this->assertCount(1, $notices);
        $buttons = json_encode(data_get($notices->first()[0]->data(), 'reply_markup.inline_keyboard'));
        foreach (["guard:show:{$update->id}", "guard:wait:{$update->id}", "search:cancel:{$update->id}"] as $button) {
            $this->assertStringContainsString($button, $buttons);
        }
        $this->assertNull($update->fresh()->processed_at);

        $this->age($update, 13);
        $this->sweep();

        $this->assertSame('completed', $update->fresh()->status);
        $this->assertSentText('Поиск завис на шаге');
        $this->assertSame('pending_review', $draft->fresh()->status);
    }

    public function test_a_stall_with_nothing_to_show_offers_only_cancel(): void
    {
        [$update] = $this->stalledSearch(silentMinutes: 7, withDraft: false);
        SearchProgressAgent::fake()->preventStrayPrompts();

        $this->sweep();
        $this->age($update, 13);
        $this->sweep();

        Http::assertNotSent(fn (Request $request): bool => str_contains(json_encode($request->data()), 'guard:show'));
        $this->assertSentText('Показать пока нечего');
        $this->assertNull($update->fresh()->processed_at);
    }

    public function test_the_guardians_and_watchers_own_rows_never_count_as_progress(): void
    {
        [$update, $draft] = $this->stalledSearch(silentMinutes: 7, withDraft: true);
        AiRun::query()->create(['telegram_update_id' => $update->id, 'provider' => 'openai', 'model' => 'mini', 'status' => 'completed',
            'prompt' => 'watch', 'started_at' => now(), 'activity' => ['stage' => 'search_progress_supervisor']]);
        ProductSourceAttempt::query()->create(['telegram_update_id' => $update->id, 'product_draft_id' => $draft->id, 'product_url' => '',
            'domain' => '', 'actor' => 'server', 'phase' => 'guardian', 'action' => 'stall_notice', 'status' => 'completed']);
        SearchProgressAgent::fake([['decision' => 'continue', 'reason' => 'Ждём.']])->preventStrayPrompts();

        $this->sweep();

        $this->assertSentText('дольше обычного');
    }

    public function test_an_abandoned_request_without_a_draft_is_closed_with_a_plain_message(): void
    {
        $update = $this->update('processing', 10, activityMinutesAgo: 5);
        $run = $this->aiRun($update, 'assistant', 'running', 10, 5);

        $this->sweep();

        $this->assertSentText('Обработка запроса прервалась');
        $this->assertSame(['failed', true], [$update->fresh()->status, $update->fresh()->processed_at !== null]);
        $this->assertSame('failed', $run->fresh()->status);
        Http::assertNotSent(fn (Request $request): bool => str_contains((string) ($request['text'] ?? ''), 'RuntimeException'));
    }

    public function test_an_abandoned_request_with_a_prepared_reply_gets_that_reply(): void
    {
        $update = $this->update('delivering', 10, activityMinutesAgo: 5);
        $checkpoint = AiOperation::query()->create(['telegram_update_id' => $update->id, 'telegram_user_id' => '123',
            'tool' => 'test', 'action' => 'deliver_assistant_reply', 'target_type' => 'assistant_reply', 'status' => 'running',
            'idempotency_key' => 'assistant-reply:test', 'payload' => ['chat_id' => '900']]);

        $this->sweep();

        Queue::assertPushed(DeliverTelegramAssistantReply::class, fn ($job) => $job->checkpointId === $checkpoint->id);
        Http::assertNothingSent();
    }

    public function test_an_undelivered_reply_is_delivered_again_at_most_every_five_minutes(): void
    {
        $update = $this->update('failed', 10);
        $checkpoint = AiOperation::query()->create(['telegram_update_id' => $update->id, 'telegram_user_id' => '123',
            'tool' => 'test', 'action' => 'deliver_assistant_reply', 'target_type' => 'assistant_reply', 'status' => 'failed',
            'idempotency_key' => 'assistant-reply:failed', 'payload' => ['chat_id' => '900']]);
        DB::table('ai_operations')->where('id', $checkpoint->id)->update(['updated_at' => now()->subMinutes(6)]);

        $this->sweep();
        $this->sweep();

        Queue::assertPushed(DeliverTelegramAssistantReply::class, 1);
    }

    public function test_the_wait_button_postpones_the_guardian_and_the_show_button_shows(): void
    {
        [$update] = $this->stalledSearch(silentMinutes: 7, withDraft: true);
        SearchProgressAgent::fake([['decision' => 'continue', 'reason' => 'Ждём.']])->preventStrayPrompts();
        $this->sweep();

        $this->press("guard:wait:{$update->id}", user: 999);
        $this->assertNull(Cache::get('request-guard:wait:'.$update->id));
        $this->press("guard:wait:{$update->id}");
        $this->age($update, 13);
        $this->sweep();
        $this->assertNull($update->fresh()->processed_at);

        $this->press("guard:show:{$update->id}");
        $this->assertSame('completed', $update->fresh()->status);
        $this->assertSentText('Показываю, что уже найдено, остальной поиск остановлен');
    }

    public function test_past_the_time_limit_the_notice_names_the_total_and_the_saved_draft_follows(): void
    {
        // Live #598: one photo moving slowly 33 minutes in; "1 мин без продвижения" misread it.
        [$update] = $this->stalledSearch(silentMinutes: 1, withDraft: true);
        DB::table('ai_runs')->where('telegram_update_id', $update->id)->update(['created_at' => now()->subMinutes(33)]);
        SearchProgressAgent::fake([['decision' => 'continue', 'reason' => 'Впереди источники.']])->preventStrayPrompts();

        $this->sweep();
        $this->assertSentText('Поиск идёт уже 33 мин');
        $this->sweep();

        $this->assertSentText('Поиск вышел за лимит времени (33 мин)');
        $this->assertSame('completed', $update->fresh()->status);
    }

    public function test_the_late_end_of_a_request_the_guardian_answered_is_not_reported_as_a_cancellation(): void
    {
        $update = $this->update('completed', 20);
        $update->update(['cancel_requested_at' => now(), 'processed_at' => now(),
            'payload' => ['_guard_delivered_at' => now()->toIso8601String()]]);

        (new ProcessTelegramMessage($update->id))->failed(new RuntimeException('provider closed the stream'));

        $this->assertSame('completed', $update->fresh()->status);
        Http::assertNothingSent();
    }

    private function sweep(): void
    {
        app(RequestGuardian::class)->sweep();
    }

    private function assertSentText(string $needle): void
    {
        Http::assertSent(fn (Request $request): bool => str_contains((string) ($request['text'] ?? $request['caption'] ?? ''), $needle));
    }

    /** @return array{TelegramUpdate, ?ProductDraft} */
    private function stalledSearch(int $silentMinutes, bool $withDraft): array
    {
        $update = $this->update('processing', 12, activityMinutesAgo: $silentMinutes);
        $this->job($update, reservedMinutesAgo: 12, createdMinutesAgo: 12);
        $this->aiRun($update, 'assistant', 'running', 12, $silentMinutes);
        $research = $this->aiRun($update, 'research', 'running', 11, $silentMinutes);
        $draft = null;
        if ($withDraft) {
            $draft = ProductDraft::query()->create(['telegram_update_id' => $update->id, 'ai_run_id' => $research->id, 'requested_by_telegram_user_id' => '123',
                'title' => 'Lenovo Legion 5', 'status' => 'pending_review', 'category' => 'laptops', 'product_type' => 'laptop',
                'primary_source_url' => 'https://shop.example/legion', 'specifications_reconciled_source_url' => 'https://shop.example/legion',
                'images_staged_at' => now(), 'gallery_status' => 'complete', 'description' => 'Verified.',
                'specifications' => [], 'sources' => [], 'image_urls' => []]);
            $path = "drafts/{$draft->id}/1.jpg";
            Storage::disk('public')->put($path, 'photo');
            $draft->media()->create(['disk' => 'public', 'path' => $path, 'checksum' => hash('sha256', 'photo'),
                'width' => 1200, 'height' => 900, 'verification_status' => 'verified', 'is_primary' => true, 'sort_order' => 1]);
        }

        return [$update, $draft];
    }

    private function update(string $status, int $createdMinutesAgo, ?int $activityMinutesAgo = null): TelegramUpdate
    {
        $update = TelegramUpdate::query()->create(['update_id' => ++$this->sequence, 'telegram_user_id' => '123', 'chat_id' => '900',
            'text' => 'Lenovo Legion 5', 'payload' => [], 'status' => $status]);
        DB::table('telegram_updates')->where('id', $update->id)->update([
            'created_at' => now()->subMinutes($createdMinutesAgo),
            'updated_at' => now()->subMinutes($activityMinutesAgo ?? $createdMinutesAgo),
        ]);

        return $update->fresh();
    }

    /** Ages every trace of the request, as if it had stayed silent this long. */
    private function age(TelegramUpdate $update, int $silentMinutes): void
    {
        DB::table('telegram_updates')->where('id', $update->id)->update(['updated_at' => now()->subMinutes($silentMinutes)]);
        DB::table('ai_runs')->where('telegram_update_id', $update->id)->where(fn ($query) => $query->whereNull('activity')
            ->orWhere('activity', 'not like', '%search_progress_supervisor%'))->update(['updated_at' => now()->subMinutes($silentMinutes)]);
        DB::table('product_source_attempts')->where('telegram_update_id', $update->id)->whereNotIn('phase', ['guardian', 'search_supervision'])
            ->update(['created_at' => now()->subMinutes($silentMinutes)]);
    }

    private function aiRun(TelegramUpdate $update, string $stage, string $status, int $createdMinutesAgo, int $updatedMinutesAgo): AiRun
    {
        $run = AiRun::query()->create(['telegram_update_id' => $update->id, 'provider' => 'openai', 'model' => 'test',
            'status' => $status, 'prompt' => $stage, 'started_at' => now()->subMinutes($createdMinutesAgo), 'activity' => ['stage' => $stage]]);
        DB::table('ai_runs')->where('id', $run->id)->update(['created_at' => now()->subMinutes($createdMinutesAgo),
            'updated_at' => now()->subMinutes($updatedMinutesAgo)]);

        return $run;
    }

    private function job(TelegramUpdate $update, ?int $reservedMinutesAgo, int $createdMinutesAgo, string $queue = 'assistant'): void
    {
        $command = 'O:31:"App\Jobs\ProcessTelegramMessage":2:{s:16:"telegramUpdateId";i:'.$update->id.';s:17:"freshConversation";b:0;}';
        DB::table('jobs')->insert(['queue' => $queue, 'payload' => json_encode(['data' => ['command' => $command]]), 'attempts' => 0,
            'reserved_at' => $reservedMinutesAgo === null ? null : now()->subMinutes($reservedMinutesAgo)->getTimestamp(),
            'available_at' => now()->subMinutes($createdMinutesAgo)->getTimestamp(), 'created_at' => now()->subMinutes($createdMinutesAgo)->getTimestamp()]);
    }

    private function press(string $data, int $user = 123): void
    {
        $id = ++$this->sequence;
        $this->postJson('/api/telegram/webhook', ['update_id' => $id, 'callback_query' => [
            'id' => "callback-{$id}", 'from' => ['id' => $user], 'data' => $data,
            'message' => ['message_id' => 701, 'chat' => ['id' => 900]],
        ]], ['X-Telegram-Bot-Api-Secret-Token' => 'test-secret'])->assertOk();
    }
}
