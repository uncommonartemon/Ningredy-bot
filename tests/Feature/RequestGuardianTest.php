<?php

namespace Tests\Feature;

use App\Ai\Agents\SearchProgressAgent;
use App\Jobs\ContinueDraftGallerySearch;
use App\Jobs\DeliverTelegramAssistantReply;
use App\Jobs\ProcessTelegramMessage;
use App\Models\AiOperation;
use App\Models\AiRun;
use App\Models\AppSetting;
use App\Models\ProductDraft;
use App\Models\ProductSourceAttempt;
use App\Models\TelegramUpdate;
use App\Services\Ai\ProductSearchCostBudget;
use App\Services\Products\ProductSourceAttemptRecorder;
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

    public function test_a_note_the_watcher_would_send_the_operator_wraps_the_search_up_instead(): void
    {
        // Operator, 2026-10-25 (#187): no messages or questions during a search - the
        // card is the answer. What the watcher would have asked is the decision itself.
        [$update] = $this->runningSearch(silentMinutes: 2, withPhotos: false);
        $prompts = 0;
        SearchProgressAgent::fake(function () use (&$prompts): array {
            $prompts++;

            return ['decision' => 'continue', 'reason' => 'Магазины дают только миниатюры.',
                'operator_note' => 'Десять минут без фото; оператор, возможно, предпочтёт имеющийся результат.'];
        })->preventStrayPrompts();

        $this->sweep();
        $this->sweep();

        $this->assertSame(1, $prompts);
        Http::assertNotSent(fn (Request $request): bool => str_contains((string) ($request['text'] ?? ''), 'предпочтёт'));
        $this->assertSame('Десять минут без фото; оператор, возможно, предпочтёт имеющийся результат.',
            app(SearchProgressSupervisor::class)->deliverNowReason($update->id));
        $this->assertDatabaseHas('product_source_attempts', ['telegram_update_id' => $update->id, 'phase' => 'guardian', 'action' => 'wrap_up']);
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

    public function test_an_orphaned_request_with_a_saved_draft_is_requeued_without_promising_a_fresh_start(): void
    {
        // Its retry goes on from the draft and says so itself: "searching again" would not be true.
        [$update] = $this->runningSearch(silentMinutes: 5);
        Process::fake(['*' => Process::result(now()->subMinutes(2)->getTimestamp().' php artisan queue:work --queue=assistant,default --sleep=2')]);

        $this->sweep();

        $this->assertNull(DB::table('jobs')->value('reserved_at'));
        Http::assertNotSent(fn (Request $request): bool => str_contains((string) ($request['text'] ?? ''), 'заново'));
        $this->assertDatabaseHas('product_source_attempts', ['telegram_update_id' => $update->id, 'phase' => 'guardian',
            'action' => 'retry_orphaned_job', 'message' => 'Задачу держал остановленный обработчик; она возвращена в очередь и продолжит поиск с сохранённого черновика.']);
    }

    public function test_a_vanished_search_with_a_saved_draft_and_money_left_goes_on_from_where_it_stopped(): void
    {
        AppSetting::put('ai.max_search_cost_usd', '1');
        $budget = $this->mock(ProductSearchCostBudget::class);
        $budget->shouldReceive('unmeasurable')->andReturn(false);
        $budget->shouldReceive('hasRemaining')->andReturn(true);
        $update = $this->update('processing', 12, activityMinutesAgo: 5);
        $run = $this->aiRun($update, 'research', 'running', 11, 5);
        $draft = ProductDraft::query()->create(['telegram_update_id' => $update->id, 'ai_run_id' => $run->id, 'requested_by_telegram_user_id' => '123',
            'title' => 'Lenovo Legion 5', 'status' => 'pending_review', 'product_type' => 'laptop', 'primary_source_url' => 'https://shop.example/legion',
            'specifications' => [], 'sources' => [], 'image_urls' => []]);

        $this->sweep();

        Queue::assertPushed(ContinueDraftGallerySearch::class, fn (ContinueDraftGallerySearch $job): bool => $job->draftId === $draft->id
            && $job->telegramUpdateId === $update->id);
        $this->assertSentText('Обработчик остановился во время поиска. Найденное сохранено: фото — 0. Продолжаю с места остановки');
        $this->assertSame(['processing', null], [$update->fresh()->status, $update->fresh()->processed_at]);
        $this->assertSame('failed', $run->fresh()->status);
        $this->assertDatabaseHas('product_source_attempts', ['telegram_update_id' => $update->id, 'phase' => 'guardian', 'action' => 'resume_abandoned']);
        Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/sendPhoto'));
    }

    public function test_the_page_in_work_reaches_the_watcher_and_its_leave_is_kept_for_that_page(): void
    {
        [$update, $draft] = $this->runningSearch(silentMinutes: 1);
        app(ProductSourceAttemptRecorder::class)->record(['telegram_update_id' => $update->id, 'product_draft_id' => $draft->id,
            'product_url' => 'https://shop.example/legion', 'actor' => 'ai', 'phase' => 'gallery_training', 'action' => 'propose_recipe',
            'status' => 'completed', 'decision' => 'execute_candidate', 'output' => ['images' => ['https://shop.example/1.jpg']]]);
        $prompts = [];
        SearchProgressAgent::fake(function (string $prompt) use (&$prompts): array {
            $prompts[] = json_decode($prompt, true);

            return ['decision' => 'leave_current_page', 'reason' => 'Пятый раунд те же кадры.', 'operator_note' => null, 'site' => null];
        })->preventStrayPrompts();

        $this->sweep();

        $this->assertSame(['https://shop.example/legion', 'shop.example'],
            [$prompts[0]['current_activity']['url'], $prompts[0]['current_activity']['host']]);
        $this->assertCount(1, $prompts[0]['timeline']);
        $supervisor = app(SearchProgressSupervisor::class);
        $this->assertNull($supervisor->takeLeaveVerdict($update->id, 'https://shop.example/another-page'));
        $this->assertSame('Пятый раунд те же кадры.', $supervisor->takeLeaveVerdict($update->id, 'https://shop.example/legion'));
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

    public function test_a_step_that_has_not_moved_for_ten_minutes_is_a_hang_and_what_exists_is_shown(): void
    {
        // Live #176 (14 min) and #177 (42 min): one call never returned, no look was
        // recorded, and the operator restarted the bot. Journal silence is the rule here,
        // whatever the watcher would say.
        [$update] = $this->runningSearch(silentMinutes: 11);
        SearchProgressAgent::fake()->preventStrayPrompts();

        $this->sweep();

        $this->assertNotNull($update->fresh()->cancel_requested_at);
        $this->assertSentText('Шаг поиска не отвечает 11 мин');
        $this->assertSentText('перезапустите его');
        $this->assertDatabaseHas('product_source_attempts', ['telegram_update_id' => $update->id, 'phase' => 'guardian', 'action' => 'hang_shown_found']);
        SearchProgressAgent::assertNeverPrompted();
    }

    public function test_at_the_time_limit_the_photo_search_is_wrapped_up_and_the_card_is_cut_only_after_the_assembly_overrun(): void
    {
        // Live #151: the card was cut while being assembled at the 29th minute; #161: cut
        // before assembly began. The photo search ends two minutes before the limit;
        // assembly keeps the overrun PROJECT_STRATEGY gives it (180 s past the limit).
        [$update] = $this->runningSearch(silentMinutes: 1, startedMinutesAgo: 29);

        $this->sweep();

        $this->assertSame('Время поиска фото вышло (29 мин); карточка собирается из найденного.',
            app(SearchProgressSupervisor::class)->deliverNowReason($update->id));
        $this->assertNull($update->fresh()->cancel_requested_at);
        $this->assertDatabaseHas('product_source_attempts', ['telegram_update_id' => $update->id, 'phase' => 'guardian', 'action' => 'overtime_wrap_up']);

        // Within the assembly's overrun the guardian waits for the card.
        $this->travel(3)->minutes();
        DB::table('telegram_updates')->where('id', $update->id)->update(['updated_at' => now()]);
        $this->sweep();
        $this->assertNull($update->fresh()->cancel_requested_at);
        $this->assertDatabaseMissing('product_source_attempts', ['telegram_update_id' => $update->id, 'action' => 'show_found']);

        // Past it, what exists is shown as it is.
        $this->travel(2)->minutes();
        DB::table('telegram_updates')->where('id', $update->id)->update(['updated_at' => now()]);
        $this->sweep();
        $this->assertNotNull($update->fresh()->cancel_requested_at);
        $this->assertSentText('Лимит времени поиска (34 мин)');
        $this->assertDatabaseHas('product_source_attempts', ['telegram_update_id' => $update->id, 'phase' => 'guardian', 'action' => 'show_found']);
    }

    /** @return array{TelegramUpdate, ?ProductDraft} */
    private function runningSearch(int $silentMinutes, bool $withDraft = true, bool $withPhotos = true, int $startedMinutesAgo = 12): array
    {
        $update = $this->update('processing', $startedMinutesAgo, activityMinutesAgo: $silentMinutes);
        $this->job($update, reservedMinutesAgo: $startedMinutesAgo, createdMinutesAgo: $startedMinutesAgo);
        $this->aiRun($update, 'assistant', 'running', $startedMinutesAgo, $silentMinutes);
        $research = $this->aiRun($update, 'research', 'running', $startedMinutesAgo - 1, $silentMinutes);
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
