<?php

namespace Tests\Feature;

use App\Ai\Agents\SearchProgressAgent;
use App\Models\AiRun;
use App\Models\AppSetting;
use App\Models\ProductDraft;
use App\Models\ProductSourceAttempt;
use App\Models\TelegramUpdate;
use App\Services\Ai\ProductSearchCostBudget;
use App\Services\Products\ProductSourceAttemptRecorder;
use App\Services\ProductSearch\Supervision\SearchProgressSupervisor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class SearchProgressSupervisorTest extends TestCase
{
    use RefreshDatabase;

    private const PAGE = 'https://shop.example/products/legion-pro-7';

    private $budget;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        AppSetting::put('ai.max_search_cost_usd', '1');
        $this->budget = $this->mock(ProductSearchCostBudget::class);
        $this->budget->shouldReceive('exceeded')->andReturn(false);
        $this->budget->shouldReceive('unmeasurable')->andReturn(false);
        $this->budget->shouldReceive('spent')->andReturn(0.12);
        $this->budget->shouldReceive('spentFraction')->andReturn(0.12)->byDefault();
        $this->budget->shouldReceive('limit')->andReturn(1.0);
    }

    public function test_a_young_search_waits_while_an_age_gate_is_configured(): void
    {
        // The default is no gate: the watcher may look from the first checkpoint.
        config(['services.search_progress_supervisor.after_seconds' => 480]);
        $draft = $this->draft(minutesAgo: 2);
        SearchProgressAgent::fake()->preventStrayPrompts();

        $this->assertSame('continue', $this->review($draft)['decision']);
        $this->assertSame(0, AiRun::query()->where('activity->stage', 'search_progress_supervisor')->count());
    }

    public function test_a_young_search_that_has_spent_much_of_its_money_is_reviewed(): void
    {
        // Live #566 spent its dollar on empty discovery rounds in four minutes.
        $this->budget->shouldReceive('spentFraction')->andReturn(0.45);
        $draft = $this->draft(minutesAgo: 4);
        $prompts = [];
        SearchProgressAgent::fake(function (string $prompt) use (&$prompts): array {
            $prompts[] = json_decode($prompt, true);

            return ['decision' => 'deliver_now', 'reason' => 'Шесть пустых раундов подряд по этому коду.'];
        })->preventStrayPrompts();

        $verdict = app(SearchProgressSupervisor::class)->review($draft->telegram_update_id, $draft, [
            'kind' => 'fallback_discovery', 'round' => 7, 'empty_discovery_rounds_in_a_row' => 6,
        ]);

        $this->assertSame('deliver_now', $verdict['decision']);
        $this->assertSame(6, $prompts[0]['current_activity']['empty_discovery_rounds_in_a_row']);
    }

    public function test_a_stuck_page_is_left_with_the_agents_reason_recorded_and_the_next_review_waits(): void
    {
        $draft = $this->draft(minutesAgo: 10);
        $prompts = [];
        SearchProgressAgent::fake(function (string $prompt) use (&$prompts): array {
            $prompts[] = json_decode($prompt, true);

            return ['decision' => 'leave_current_page', 'reason' => 'Третий раунд подряд те же 4 кадра.'];
        })->preventStrayPrompts();
        $messages = [];

        $verdict = $this->review($draft, function (string $message) use (&$messages): void {
            $messages[] = $message;
        });

        $this->assertSame('leave_current_page', $verdict['decision']);
        $this->assertSame(3, $prompts[0]['current_activity']['rounds_with_same_frames']);
        $this->assertSame(0, $prompts[0]['photos_saved_for_card']);
        $this->assertStringContainsString('Третий раунд подряд', $messages[0]);
        $this->assertDatabaseHas('product_source_attempts', ['telegram_update_id' => $draft->telegram_update_id,
            'phase' => 'search_supervision', 'action' => 'review_progress', 'decision' => 'leave_current_page']);
        $this->assertDatabaseHas('ai_runs', ['telegram_update_id' => $draft->telegram_update_id, 'status' => 'completed']);
        // Throttled: a second look within the interval costs nothing.
        $this->assertSame('continue', $this->review($draft)['decision']);
        $this->assertCount(1, $prompts);
    }

    public function test_deliver_now_stands_for_the_rest_of_the_search_until_a_new_session(): void
    {
        $draft = $this->draft(minutesAgo: 12);
        SearchProgressAgent::fake([['decision' => 'deliver_now', 'reason' => 'Семь фото уже есть; остальные магазины те же.']])
            ->preventStrayPrompts();
        $supervisor = app(SearchProgressSupervisor::class);

        $this->assertSame('deliver_now', $this->review($draft)['decision']);
        $this->assertSame('deliver_now', $this->review($draft)['decision']);
        $this->assertSame('Семь фото уже есть; остальные магазины те же.', $supervisor->deliverNowReason($draft->telegram_update_id));

        $supervisor->beginSession($draft->telegram_update_id);
        $this->assertNull($supervisor->deliverNowReason($draft->telegram_update_id));
    }

    public function test_leave_said_from_outside_waits_for_the_page_in_training_and_is_carried_out_once(): void
    {
        // Live #58: the guardian's look said "leave" twice and the page trained on.
        $draft = $this->draft(minutesAgo: 9);
        SearchProgressAgent::fake([
            ['decision' => 'leave_current_page', 'reason' => 'Те же пять кадров восемь раундов подряд.'],
        ])->preventStrayPrompts();
        $supervisor = app(SearchProgressSupervisor::class);

        $supervisor->review($draft->telegram_update_id, $draft, ['kind' => 'outside_check', 'minutes_since_last_step' => 0,
            'url' => self::PAGE, 'host' => 'shop.example'], fromOutside: true);
        // Said about one page, it never ends another that started meanwhile.
        $this->assertNull($supervisor->takeLeaveVerdict($draft->telegram_update_id, 'https://shop.example/products/other'));
        $this->assertSame('Те же пять кадров восемь раундов подряд.', $supervisor->takeLeaveVerdict($draft->telegram_update_id, self::PAGE.'/'));
        $this->assertNull($supervisor->takeLeaveVerdict($draft->telegram_update_id, self::PAGE));

        // Inside the search its caller acts on the spot; nothing is left over for the next page.
        $this->review($draft);
        $this->assertNull($supervisor->takeLeaveVerdict($draft->telegram_update_id, self::PAGE));
    }

    public function test_the_watcher_reads_the_whole_story_and_drops_a_site_whose_pages_repeat_the_same_frames(): void
    {
        // Live #58: page two of de.msi.com read "the same frames two rounds" -
        // the same five frames page one had shown for seven rounds.
        $draft = $this->draft(minutesAgo: 14);
        foreach (['/vector/shopnow', '/vector/shopnow', '/vector/', '/vector/'] as $path) {
            $this->round($draft, 'https://de.msi.com'.$path, ['https://cdn.msi.com/a.webp', 'https://cdn.msi.com/b.webp']);
        }
        $prompts = [];
        SearchProgressAgent::fake(function (string $prompt) use (&$prompts): array {
            $prompts[] = json_decode($prompt, true);

            return ['decision' => 'skip_site', 'reason' => 'Те же два кадра на двух страницах MSI.', 'operator_note' => null, 'site' => 'msi.com'];
        })->preventStrayPrompts();
        $messages = [];

        $verdict = $this->review($draft, function (string $message) use (&$messages): void {
            $messages[] = $message;
        });

        $this->assertCount(4, $prompts[0]['timeline']);
        $this->assertStringContainsString('de.msi.com gallery_training.propose_recipe: execute_candidate · 2 frames [', $prompts[0]['timeline'][3]);
        $this->assertSame(['site' => 'de.msi.com', 'step' => 'gallery_training.propose_recipe', 'times' => 4, 'pages' => 2, 'photos_accepted_on_site' => 0],
            array_intersect_key($prompts[0]['repeats'][0], array_flip(['site', 'step', 'times', 'pages', 'photos_accepted_on_site'])));
        // "msi.com" for the one MSI host this search opened is that host.
        $this->assertSame(['skip_site', 'de.msi.com'], [$verdict['decision'], $verdict['site']]);
        $supervisor = app(SearchProgressSupervisor::class);
        $this->assertSame('Те же два кадра на двух страницах MSI.', $supervisor->siteDropReason($draft->telegram_update_id, 'https://www.de.msi.com/other-page'));
        $this->assertNull($supervisor->siteDropReason($draft->telegram_update_id, self::PAGE));
        $this->assertStringContainsString('больше не открываю страницы сайта de.msi.com', $messages[0]);
        $this->assertSame(['de.msi.com' => 'Те же два кадра на двух страницах MSI.'], $supervisor->droppedSites($draft->telegram_update_id));
    }

    public function test_a_site_the_search_never_opened_is_not_dropped_and_without_a_name_the_page_in_work_is_meant(): void
    {
        $draft = $this->draft(minutesAgo: 9);
        SearchProgressAgent::fake([
            ['decision' => 'skip_site', 'reason' => 'Этот магазин не отвечает.', 'operator_note' => null, 'site' => 'never-opened.example'],
            ['decision' => 'skip_site', 'reason' => 'Сайт отказывает в доступе.', 'operator_note' => null, 'site' => null],
        ])->preventStrayPrompts();
        $supervisor = app(SearchProgressSupervisor::class);

        $this->assertSame('leave_current_page', $this->review($draft)['decision']);
        $this->assertSame([], $supervisor->droppedSites($draft->telegram_update_id));

        $this->round($draft, self::PAGE, []);
        $this->travel(2)->minutes();
        $this->assertSame('skip_site', $this->review($draft)['decision']);
        $this->assertSame(['shop.example' => 'Сайт отказывает в доступе.'], $supervisor->droppedSites($draft->telegram_update_id));
    }

    public function test_inside_the_search_it_looks_again_only_after_a_new_step_and_a_pause(): void
    {
        $draft = $this->draft(minutesAgo: 9);
        $looks = 0;
        SearchProgressAgent::fake(function () use (&$looks): array {
            $looks++;

            return ['decision' => 'continue', 'reason' => 'Кадры собираются.', 'operator_note' => null, 'site' => null];
        })->preventStrayPrompts();

        $this->review($draft);
        $this->round($draft, self::PAGE, ['https://shop.example/1.jpg']);
        // Something new, but a moment ago.
        $this->review($draft);
        $this->travel(2)->minutes();
        $this->review($draft);
        // Nothing new since: the same picture is never paid for twice.
        $this->travel(2)->minutes();
        $this->review($draft);

        $this->assertSame(2, $looks);
    }

    public function test_from_outside_it_looks_each_minute_the_search_moved_and_after_minutes_of_silence(): void
    {
        $draft = $this->draft(minutesAgo: 9);
        $looks = 0;
        SearchProgressAgent::fake(function () use (&$looks): array {
            $looks++;

            return ['decision' => 'continue', 'reason' => 'Ждём.', 'operator_note' => null, 'site' => null];
        })->preventStrayPrompts();
        $outside = fn () => app(SearchProgressSupervisor::class)->review($draft->telegram_update_id, $draft,
            ['kind' => 'outside_check', 'minutes_since_last_step' => 0], fromOutside: true);

        $outside();
        $this->travel(61)->seconds();
        $outside();
        $this->round($draft, self::PAGE, ['https://shop.example/1.jpg']);
        $outside();
        $this->travel(2)->minutes();
        $outside();
        // Three minutes without a step are news of their own.
        $this->travel(61)->seconds();
        $outside();

        $this->assertSame(3, $looks);
    }

    public function test_frames_a_page_was_ended_on_stay_ruled_out_on_that_site_only(): void
    {
        $draft = $this->draft(minutesAgo: 9);
        $supervisor = app(SearchProgressSupervisor::class);

        $supervisor->ruleOutFrames($draft->telegram_update_id, 'de.msi.com', 'frames-abc', 'Наблюдатель поиска: те же пять кадров.');

        $this->assertSame('Наблюдатель поиска: те же пять кадров.', $supervisor->ruledOutReason($draft->telegram_update_id, 'DE.msi.com', 'frames-abc'));
        $this->assertNull($supervisor->ruledOutReason($draft->telegram_update_id, 'de.msi.com', 'other-frames'));
        $this->assertNull($supervisor->ruledOutReason($draft->telegram_update_id, 'shop.example', 'frames-abc'));
    }

    public function test_an_unavailable_agent_means_the_search_simply_continues(): void
    {
        $draft = $this->draft(minutesAgo: 10);
        SearchProgressAgent::fake(fn () => throw new RuntimeException('provider down'))->preventStrayPrompts();

        $this->assertSame('continue', $this->review($draft)['decision']);
        $this->assertSame('supervisor_unavailable', ProductSourceAttempt::query()
            ->where('phase', 'search_supervision')->value('decision'));
    }

    public function test_an_invalid_answer_is_not_followed(): void
    {
        $draft = $this->draft(minutesAgo: 10);
        SearchProgressAgent::fake([['decision' => 'delete_everything', 'reason' => 'x']])->preventStrayPrompts();

        $this->assertSame('continue', $this->review($draft)['decision']);
    }

    private function review(ProductDraft $draft, ?callable $progress = null): array
    {
        return app(SearchProgressSupervisor::class)->review($draft->telegram_update_id, $draft, [
            'kind' => 'gallery_training', 'url' => self::PAGE, 'host' => 'shop.example', 'round' => 4,
            'rounds_with_same_frames' => 3, 'rounds_without_progress' => 2, 'candidate_frames_on_this_page' => 4,
        ], $progress);
    }

    public function test_the_watcher_sees_the_phase_the_minimum_the_time_since_a_photo_and_a_site_of_thumbnails(): void
    {
        // Live #87 and #161: shops whose every frame was too small read as work in
        // progress; #168 and #173: "deliver_now" said from outside while the card
        // was already being assembled left a standing wrap-up for a finished search.
        $draft = $this->draft(minutesAgo: 12);
        $recorder = app(ProductSourceAttemptRecorder::class);
        $recorder->record(['telegram_update_id' => $draft->telegram_update_id, 'product_draft_id' => $draft->id,
            'product_url' => 'https://pcx.example/nitro-16', 'actor' => 'downloader', 'phase' => 'image_download',
            'action' => 'download_candidates', 'status' => 'completed', 'decision' => 'skip_source',
            'output' => ['downloaded_images' => 0, 'rejection_summary' => ['too_small' => 18, 'bad_aspect_ratio (1:7)' => 2]]]);
        $accepted = $recorder->record(['telegram_update_id' => $draft->telegram_update_id, 'product_draft_id' => $draft->id,
            'product_url' => self::PAGE, 'actor' => 'vision', 'phase' => 'image_verification',
            'action' => 'verify_downloaded_exact_source', 'status' => 'completed', 'output' => ['accepted_images' => 1]]);
        DB::table('product_source_attempts')->where('id', $accepted->id)->update(['created_at' => now()->subMinutes(6)]);
        AiRun::create(['telegram_update_id' => $draft->telegram_update_id, 'provider' => 'fake', 'model' => 'fake',
            'status' => 'running', 'prompt' => 'assemble', 'started_at' => now(), 'activity' => ['stage' => 'card_assembly']]);
        $prompts = [];
        SearchProgressAgent::fake(function (string $prompt) use (&$prompts): array {
            $prompts[] = json_decode($prompt, true);

            return ['decision' => 'deliver_now', 'reason' => 'Одно фото уже есть, этого достаточно.', 'operator_note' => null, 'site' => null];
        })->preventStrayPrompts();

        $verdict = app(SearchProgressSupervisor::class)->review($draft->telegram_update_id, $draft,
            ['kind' => 'outside_check', 'minutes_since_last_step' => 1, 'operator_already_told' => false], null, fromOutside: true);

        $this->assertSame('card_assembly', $prompts[0]['search_phase']);
        $this->assertSame(3, $prompts[0]['minimum_photos_for_card']);
        $this->assertEqualsWithDelta(6.0, $prompts[0]['minutes_since_last_accepted_photo'], 0.2);
        $thumbnails = collect($prompts[0]['pages_opened'])->firstWhere('host', 'pcx.example');
        $this->assertSame(['too_small' => 18, 'bad_aspect_ratio' => 2], $thumbnails['rejections']);
        // The photo search is over: nothing to wrap up, and no standing verdict is left behind.
        $this->assertSame('continue', $verdict['decision']);
        $this->assertStringStartsWith('Поиск фото уже завершён, идёт сборка карточки.', $verdict['reason']);
        $this->assertNull(app(SearchProgressSupervisor::class)->deliverNowReason($draft->telegram_update_id));
        $this->assertDatabaseHas('product_source_attempts', ['telegram_update_id' => $draft->telegram_update_id,
            'phase' => 'search_supervision', 'decision' => 'continue']);
    }

    private function round(ProductDraft $draft, string $page, array $images): void
    {
        app(ProductSourceAttemptRecorder::class)->record(['telegram_update_id' => $draft->telegram_update_id,
            'product_draft_id' => $draft->id, 'product_url' => $page, 'actor' => 'ai', 'phase' => 'gallery_training',
            'action' => 'propose_recipe', 'status' => 'completed', 'decision' => 'execute_candidate', 'output' => ['images' => $images]]);
    }

    private function draft(int $minutesAgo): ProductDraft
    {
        $update = TelegramUpdate::create(['update_id' => 990001, 'telegram_user_id' => '1', 'chat_id' => '1',
            'text' => 'Lenovo 83F500NKUS', 'status' => 'processing',
            'payload' => ['_product_search_started_at' => now()->subMinutes($minutesAgo)->toIso8601String()]]);
        $run = AiRun::create(['telegram_update_id' => $update->id, 'provider' => 'fake', 'model' => 'fake',
            'status' => 'completed', 'prompt' => 'test', 'started_at' => now()->subMinutes($minutesAgo), 'completed_at' => now()]);

        return ProductDraft::create(['telegram_update_id' => $update->id, 'ai_run_id' => $run->id,
            'requested_by_telegram_user_id' => '1', 'status' => 'pending_review', 'title' => 'Lenovo Legion Pro 7',
            'model' => '83F500NKUS', 'product_type' => 'laptop', 'primary_source_url' => self::PAGE,
            'specifications' => [], 'sources' => [], 'image_urls' => []]);
    }
}
