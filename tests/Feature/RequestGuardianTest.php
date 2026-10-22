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
use App\Services\ProductSearch\Supervision\SearchProgressSupervisor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
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
        // Never this computer's real queue workers: by default none are reported.
        Process::preventStrayProcesses();
        Process::fake();
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

    public function test_the_watcher_woken_from_outside_reads_the_journal_and_its_show_now_is_carried_out(): void
    {
        [$update, $draft] = $this->runningSearch(silentMinutes: 7);
        $prompts = [];
        SearchProgressAgent::fake(function (string $prompt) use (&$prompts): array {
            $prompts[] = json_decode($prompt, true);

            return ['decision' => 'show_now', 'reason' => 'Поиск страницы магазина молчит семь минут, фото уже сохранено.', 'operator_note' => null];
        })->preventStrayPrompts();

        $this->sweep();

        // The guardian adds only what the journal cannot show; the rest the agent reads itself.
        $this->assertSame(['kind' => 'outside_check', 'minutes_since_last_step' => 7, 'operator_already_told' => false], $prompts[0]['current_activity']);
        $this->assertArrayHasKey('pages_opened', $prompts[0]);
        $update->refresh();
        $this->assertNotNull($update->cancel_requested_at);
        $this->assertSame(['completed', true], [$update->status, $update->processed_at !== null]);
        $this->assertNotNull(data_get($update->payload, '_guard_delivered_at'));
        $this->assertSame('stalled', $draft->fresh()->gallery_search_stop_reason);
        $this->assertSentText('Поиск страницы магазина молчит семь минут');
        $this->assertSentText('Сохранено фото: 1. Можно добавить в каталог');
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/sendPhoto'));
        $this->assertDatabaseHas('product_source_attempts', ['telegram_update_id' => $update->id, 'phase' => 'guardian', 'action' => 'show_found']);
    }

    public function test_the_watchers_note_reaches_the_operator_once_and_it_is_not_woken_again_before_its_pace(): void
    {
        [$update] = $this->runningSearch(silentMinutes: 2);
        $prompts = 0;
        SearchProgressAgent::fake(function () use (&$prompts): array {
            $prompts++;

            return ['decision' => 'continue', 'reason' => 'Магазин отвечает медленно.', 'operator_note' => 'Магазин отвечает медленно, жду его галерею.'];
        })->preventStrayPrompts();

        $this->sweep();
        $this->sweep();

        $this->assertSame(1, $prompts);
        $notes = Http::recorded(fn (Request $request): bool => str_contains((string) ($request['text'] ?? ''), 'жду его галерею'));
        $this->assertCount(1, $notes);
        $buttons = json_encode(data_get($notes->first()[0]->data(), 'reply_markup.inline_keyboard'));
        foreach (["guard:show:{$update->id}", "guard:wait:{$update->id}", "search:cancel:{$update->id}"] as $button) {
            $this->assertStringContainsString($button, $buttons);
        }
        $this->assertNull($update->fresh()->processed_at);
    }

    public function test_deliver_now_has_the_search_wrap_up_itself_and_is_answered_if_it_never_does(): void
    {
        // Live #611: busy every minute for 33 minutes without a single photo. The
        // decision to stop is the agent's; the card is still the search's to assemble.
        [$update, $draft] = $this->runningSearch(silentMinutes: 1, withPhotos: false);
        $draft->update(['specifications_reconciled_source_url' => null]);
        SearchProgressAgent::fake([
            ['decision' => 'deliver_now', 'reason' => 'Ни одного фото, оставшиеся магазины такие же.', 'operator_note' => null],
            ['decision' => 'show_now', 'reason' => 'Карточка так и не собралась за шесть минут.', 'operator_note' => null],
        ])->preventStrayPrompts();

        $this->sweep();

        $this->assertNotNull(app(SearchProgressSupervisor::class)->deliverNowReason($update->id));
        $this->assertNull($update->fresh()->processed_at);
        $this->assertDatabaseHas('product_source_attempts', ['telegram_update_id' => $update->id, 'phase' => 'guardian', 'action' => 'wrap_up']);

        $this->travel(6)->minutes();
        DB::table('telegram_updates')->where('id', $update->id)->update(['updated_at' => now()->subMinute()]);
        $this->sweep();

        $this->assertSame('completed', $update->fresh()->status);
        // Still running minutes later: the watcher decides again (live #650 was cut mid-assembly).
        $this->assertSentText('Карточка так и не собралась за шесть минут');
        $this->assertSentText('Фото не найдены, характеристики сохранены');
    }

    public function test_a_search_calling_its_own_watcher_is_not_woken_from_outside_as_well(): void
    {
        [$update, $draft] = $this->runningSearch(silentMinutes: 1);
        ProductSourceAttempt::query()->create(['telegram_update_id' => $update->id, 'product_draft_id' => $draft->id, 'product_url' => '',
            'domain' => '', 'actor' => 'ai', 'phase' => 'search_supervision', 'action' => 'review_progress', 'status' => 'completed']);
        SearchProgressAgent::fake()->preventStrayPrompts();

        $this->sweep();

        Http::assertNothingSent();
    }

    public function test_before_a_draft_exists_only_the_time_budget_applies(): void
    {
        [$update] = $this->runningSearch(silentMinutes: 7, withDraft: false);
        SearchProgressAgent::fake()->preventStrayPrompts();

        $this->sweep();

        Http::assertNothingSent();
        $this->assertNull($update->fresh()->processed_at);
    }

    public function test_the_guardians_and_watchers_own_rows_never_count_as_steps(): void
    {
        [$update, $draft] = $this->runningSearch(silentMinutes: 7);
        AiRun::query()->create(['telegram_update_id' => $update->id, 'provider' => 'openai', 'model' => 'mini', 'status' => 'completed',
            'prompt' => 'watch', 'started_at' => now(), 'activity' => ['stage' => 'search_progress_supervisor']]);
        ProductSourceAttempt::query()->create(['telegram_update_id' => $update->id, 'product_draft_id' => $draft->id, 'product_url' => '',
            'domain' => '', 'actor' => 'server', 'phase' => 'guardian', 'action' => 'watcher_note', 'status' => 'completed']);
        $prompts = [];
        SearchProgressAgent::fake(function (string $prompt) use (&$prompts): array {
            $prompts[] = json_decode($prompt, true);

            return ['decision' => 'continue', 'reason' => 'Ждём.', 'operator_note' => null];
        })->preventStrayPrompts();

        $this->sweep();

        $this->assertSame(7, $prompts[0]['current_activity']['minutes_since_last_step']);
    }

    public function test_the_wait_button_holds_the_guardian_back_and_the_show_button_shows(): void
    {
        [$update] = $this->runningSearch(silentMinutes: 3);
        SearchProgressAgent::fake(fn (): array => ['decision' => 'continue', 'reason' => 'Ждём.', 'operator_note' => 'Шаг идёт дольше обычного.'])
            ->preventStrayPrompts();
        $this->sweep();

        $this->press("guard:wait:{$update->id}", user: 999);
        $this->assertNull(Cache::get('request-guard:wait:'.$update->id));
        $this->press("guard:wait:{$update->id}");
        $this->travel(4)->minutes();
        $this->sweep();
        $this->assertSame(1, AiRun::query()->where('activity->stage', 'search_progress_supervisor')->count());
        $this->assertNull($update->fresh()->processed_at);

        $this->press("guard:show:{$update->id}");
        $this->assertSame('completed', $update->fresh()->status);
        $this->assertSentText('Показываю, что уже найдено, остальной поиск остановлен');
    }

    public function test_near_the_time_limit_the_operator_gets_what_exists_and_what_it_allows(): void
    {
        // Live #598 and #611 searched into the 33rd minute; an answer belongs inside 30.
        [$update] = $this->runningSearch(silentMinutes: 1);
        DB::table('ai_runs')->where('telegram_update_id', $update->id)->update(['created_at' => now()->subMinutes(29)]);
        SearchProgressAgent::fake()->preventStrayPrompts();

        $this->sweep();

        $this->assertSentText('Поиск подходит к лимиту времени (29 мин)');
        $this->assertSentText('Сохранено фото: 1. Можно добавить в каталог');
        $this->assertSame('completed', $update->fresh()->status);
    }

    public function test_near_the_time_limit_without_a_card_the_operator_is_told_once_and_can_cancel(): void
    {
        [$update] = $this->runningSearch(silentMinutes: 1, withDraft: false);
        DB::table('ai_runs')->where('telegram_update_id', $update->id)->update(['created_at' => now()->subMinutes(29)]);
        SearchProgressAgent::fake()->preventStrayPrompts();

        $this->sweep();
        $this->sweep();

        $this->assertCount(1, Http::recorded(fn (Request $request): bool => str_contains((string) ($request['text'] ?? ''), 'карточка товара ещё не найдена')));
        $this->assertNull($update->fresh()->processed_at);
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

    public function test_the_late_end_of_a_request_the_guardian_answered_is_not_reported_as_a_cancellation(): void
    {
        $update = $this->update('completed', 20);
        $update->update(['cancel_requested_at' => now(), 'processed_at' => now(),
            'payload' => ['_guard_delivered_at' => now()->toIso8601String()]]);

        (new ProcessTelegramMessage($update->id))->failed(new RuntimeException('provider closed the stream'));

        $this->assertSame('completed', $update->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_a_job_held_by_a_worker_that_is_gone_goes_back_to_the_queue_and_the_operator_is_told(): void
    {
        // Live #11: the bot was restarted under its research step; every worker now running is younger than the reservation.
        [$update] = $this->runningSearch(silentMinutes: 5, withDraft: false);
        // The killed attempt's overlap lock is still held; the retry would be dropped as a duplicate.
        $stand = new ProcessTelegramMessage($update->id);
        $lockKey = $stand->middleware()[0]->getLockKey($stand);
        Cache::lock($lockKey, 3600)->get();
        Process::fake(['*' => Process::result(now()->subMinutes(2)->getTimestamp().' php artisan queue:work --queue=assistant,default --sleep=2')]);

        $this->sweep();

        $job = DB::table('jobs')->first();
        $this->assertNull($job->reserved_at);
        $this->assertSentText('Запускаю запрос заново');
        $this->assertSame(0, AiRun::query()->where('telegram_update_id', $update->id)->where('status', 'running')->count());
        $this->assertSame('processing', $update->fresh()->status);
        $this->assertTrue(Cache::lock($lockKey, 10)->get(), 'The dead attempt lock was released.');

        // The released job is a fresh attempt now, not a second rescue.
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 701]])]);
        $this->sweep();
        Http::assertNothingSent();
    }

    public function test_a_job_held_by_a_worker_that_was_already_running_is_left_alone(): void
    {
        $this->runningSearch(silentMinutes: 5, withDraft: false);
        Process::fake(['*' => Process::result(now()->subHour()->getTimestamp().' php artisan queue:work --queue=assistant,default --sleep=2')]);

        $this->sweep();

        $this->assertNotNull(DB::table('jobs')->value('reserved_at'));
        Http::assertNotSent(fn (Request $request): bool => str_contains((string) ($request['text'] ?? ''), 'заново'));
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
    private function runningSearch(int $silentMinutes, bool $withDraft = true, bool $withPhotos = true): array
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
            if ($withPhotos) {
                $path = "drafts/{$draft->id}/1.jpg";
                Storage::disk('public')->put($path, 'photo');
                $draft->media()->create(['disk' => 'public', 'path' => $path, 'checksum' => hash('sha256', 'photo'),
                    'width' => 1200, 'height' => 900, 'verification_status' => 'verified', 'is_primary' => true, 'sort_order' => 1]);
            }
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
        DB::table('jobs')->insert(['queue' => $queue, 'payload' => json_encode(['displayName' => ProcessTelegramMessage::class, 'maxTries' => 2, 'data' => ['command' => $command]]), 'attempts' => 1,
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
