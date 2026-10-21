<?php

namespace Tests\Feature;

use App\Ai\Agents\SearchProgressAgent;
use App\Models\AiRun;
use App\Models\AppSetting;
use App\Models\ProductDraft;
use App\Models\ProductSourceAttempt;
use App\Models\TelegramUpdate;
use App\Services\Ai\ProductSearchCostBudget;
use App\Services\ProductSearch\Supervision\SearchProgressSupervisor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
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
