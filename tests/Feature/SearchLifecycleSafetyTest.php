<?php

namespace Tests\Feature;

use App\Ai\Agents\ProductResearchAgent;
use App\Ai\Agents\ServerAssistantAgent;
use App\Ai\Tools\ResearchProduct;
use App\Exceptions\ProductSearchCancelled;
use App\Jobs\ContinueDraftGallerySearch;
use App\Jobs\ProcessTelegramMessage;
use App\Models\AiRun;
use App\Models\AppSetting;
use App\Models\ProductDraft;
use App\Models\TelegramUpdate;
use App\Services\Ai\AiErrorPresenter;
use App\Services\Ai\ProductSearchCancellation;
use App\Services\Ai\ProductSearchTimeBudget;
use App\Services\Products\ProductImageResolver;
use App\Services\Products\ProductImageStorage;
use App\Services\Telegram\DraftTelegramPresenter;
use App\Services\Telegram\TelegramClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Tools\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class SearchLifecycleSafetyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.telegram.bot_token' => 'test-token',
            'services.telegram.webhook_secret' => 'test-secret',
            'services.telegram.allowed_user_ids' => ['12345'],
        ]);
        Queue::fake();
        Http::preventStrayRequests();
        Http::fake(['https://api.telegram.org/*' => Http::response([
            'ok' => true, 'result' => ['message_id' => 999],
        ])]);
        AppSetting::put('ai.search_max_seconds', '1200');
        AppSetting::put('ai.search_reserve_seconds', '120');
        AppSetting::put('ai.specifications_reconciliation_enabled', '1');
    }

    public function test_no_ai_continuation_keeps_one_clock_across_jobs_and_cache_flush(): void
    {
        $this->travelTo(Carbon::parse('2026-09-23 12:00:00'));
        $update = $this->update();
        $update->update(['payload' => ['_progress_message_id' => 71]]);

        $this->assertSame(1080, app(ProductSearchTimeBudget::class)->remainingWorkingSeconds($update->id));
        $this->travel(7)->minutes();
        Cache::flush();
        $this->assertSame(660, app(ProductSearchTimeBudget::class)->remainingWorkingSeconds($update->id));
        $this->travel(60)->minutes();
        $this->assertSame(0, app(ProductSearchTimeBudget::class)->remainingWorkingSeconds($update->id));
        $this->assertSame(71, $update->fresh()->payload['_progress_message_id']);
        $this->assertDatabaseCount('ai_runs', 0);
    }

    public function test_first_ai_call_later_in_the_same_cycle_does_not_move_its_start(): void
    {
        $this->travelTo(Carbon::parse('2026-09-23 12:00:00'));
        $update = $this->update();
        $this->assertSame(1080, app(ProductSearchTimeBudget::class)->remainingWorkingSeconds($update->id));
        $this->travel(10)->minutes();
        $this->createRun($update);

        $this->assertSame(480, app(ProductSearchTimeBudget::class)->remainingWorkingSeconds($update->id));
    }

    public function test_explicit_restart_is_durable_without_refreshing_other_requests(): void
    {
        $this->travelTo(Carbon::parse('2026-09-23 12:00:00'));
        $update = $this->update();
        $budget = app(ProductSearchTimeBudget::class);
        $budget->remainingWorkingSeconds($update->id);
        $this->travel(60)->minutes();
        $this->assertSame(0, app(ProductSearchTimeBudget::class)->remainingWorkingSeconds($update->id));
        $budget->restartSession($update->id);
        Cache::flush();
        $this->travel(1)->minutes();

        $this->assertSame(1020, app(ProductSearchTimeBudget::class)->remainingWorkingSeconds($update->id));
        $this->assertSame(1080, app(ProductSearchTimeBudget::class)->remainingWorkingSeconds($this->update()->id));
    }

    public function test_cancellation_is_fresh_and_missing_or_unscoped_requests_are_not_invented_cancellations(): void
    {
        $cancellation = app(ProductSearchCancellation::class);
        $this->assertFalse($cancellation->isCancelled(null));
        $this->assertFalse($cancellation->isCancelled(999999));
        $cancellation->throwIfCancelled(null);
        $cancellation->throwIfCancelled(999999);
        $update = $this->update();
        $this->assertFalse($cancellation->isCancelled($update->id));
        $update->update(['cancel_requested_at' => now()]);
        $this->assertTrue($cancellation->isCancelled($update->id));
        $update->update(['cancel_requested_at' => null, 'status' => 'cancelled']);
        $this->assertTrue($cancellation->isCancelled($update->id));

        $this->expectException(ProductSearchCancelled::class);
        $cancellation->throwIfCancelled($update->id);
    }

    public function test_reset_during_continuation_cancels_the_active_callback_without_review_or_another_cycle(): void
    {
        $original = $this->update();
        $original->update(['status' => 'completed', 'processed_at' => now()]);
        $callback = $this->update();
        $callback->update(['status' => 'completed', 'processed_at' => now()]);
        $draft = ProductDraft::query()->create([
            'telegram_update_id' => $original->id, 'requested_by_telegram_user_id' => '12345',
            'ai_run_id' => $this->createRun($original)->id,
            'status' => 'pending_review', 'title' => 'Saved draft', 'specifications' => [],
            'sources' => [], 'image_urls' => [], 'confidence' => 0.9,
        ]);
        $outboundAtReset = 0;
        Cache::put("draft-gallery-continue:{$draft->id}:queued", true, 600);
        $images = $this->mock(ProductImageStorage::class);
        $images->shouldReceive('continueStage')->once()->andReturnUsing(
            function (ProductDraft $workingDraft, callable $progress) use ($callback, &$outboundAtReset): int {
                $this->assertSame('processing', $callback->fresh()->status);
                $this->assertNull($callback->fresh()->processed_at);
                $workingDraft->update(['gallery_status' => 'missing', 'gallery_search_stop_reason' => 'exhausted']);
                $this->resetChat();
                $outboundAtReset = Http::recorded()->count();
                $progress('An obsolete progress update');

                return 0;
            },
        );
        $presenter = $this->mock(DraftTelegramPresenter::class);
        $presenter->shouldNotReceive('sendReview');
        $job = new ContinueDraftGallerySearch($draft->id, '98765', $callback->id, $original->id);

        $job->handle($images, $presenter, app(TelegramClient::class));
        $job->failed(new ProductSearchCancelled);

        $this->assertCancelled($callback);
        $this->assertSame('completed', $original->fresh()->status);
        $this->assertSame('Saved draft', $draft->fresh()->title);
        $this->assertSame('pending_review', $draft->fresh()->status);
        $this->assertFalse(Cache::has("draft-gallery-continue:{$draft->id}:queued"));
        $this->assertSame($outboundAtReset, Http::recorded()->count());
        Queue::assertNothingPushed();
    }

    #[DataProvider('providerOutcomes')]
    public function test_reset_during_assistant_call_stays_cancelled_without_a_late_reply(bool $throws): void
    {
        $update = $this->update();
        $outboundAtReset = 0;
        ServerAssistantAgent::fake(function () use ($throws, &$outboundAtReset): array {
            $this->resetChat();
            $outboundAtReset = Http::recorded()->count();
            if ($throws) {
                throw new RuntimeException('Provider failed after reset.');
            }

            return [
                'response_type' => 'answer', 'message' => 'Obsolete answer',
                'draft_id' => null, 'product_ids' => [], 'operation_ids' => [],
            ];
        })->preventStrayPrompts();

        (new ProcessTelegramMessage($update->id))->handle(app(TelegramClient::class), app(AiErrorPresenter::class));

        $this->assertCancelled($update);
        $this->assertSame($outboundAtReset, Http::recorded()->count());
        $this->assertDatabaseMissing('telegram_chat_states', ['chat_id' => $update->chat_id]);
        $run = $update->aiRuns()->firstOrFail();
        $this->assertSame('completed', $run->status);
        $this->assertSame('cancelled', $run->response['status']);
        Queue::assertNothingPushed();
    }

    public function test_queue_failure_hook_does_not_overwrite_reset_or_notify_again(): void
    {
        $update = $this->update();
        $run = $this->createRun($update);
        $this->resetChat();
        $outboundAtReset = Http::recorded()->count();

        (new ProcessTelegramMessage($update->id))->failed(new RuntimeException('Worker stopped.'));

        $this->assertCancelled($update);
        $this->assertSame($outboundAtReset, Http::recorded()->count());
        $this->assertSame('completed', $run->fresh()->status);
        $this->assertSame('cancelled', $run->fresh()->response['status']);
    }

    public function test_queue_failure_hook_finalizes_a_soft_cancel_without_notifying(): void
    {
        $update = $this->update();
        $run = $this->createRun($update);
        $update->update(['cancel_requested_at' => now()]);

        (new ProcessTelegramMessage($update->id))->failed(new RuntimeException('Worker stopped.'));

        $this->assertCancelled($update);
        $this->assertSame('cancelled', $run->fresh()->response['status']);
        Http::assertNothingSent();
    }

    #[DataProvider('providerOutcomes')]
    public function test_reset_during_research_discards_success_and_error_without_creating_a_draft(bool $throws): void
    {
        $update = $this->update();
        $outboundAtReset = 0;
        ProductResearchAgent::fake(function () use ($throws, &$outboundAtReset): array {
            $this->resetChat();
            $outboundAtReset = Http::recorded()->count();
            if ($throws) {
                throw new RuntimeException('Research failed after reset.');
            }

            return $this->researchResult();
        })->preventStrayPrompts();
        $resolver = $this->mock(ProductImageResolver::class);
        $resolver->shouldNotReceive('resolve');
        $storage = $this->mock(ProductImageStorage::class);
        $storage->shouldNotReceive('stage');

        $result = json_decode((new ResearchProduct($update, $resolver, imageStorage: $storage))
            ->handle(new Request(['query' => 'MSI Titan 18 HX AI'])), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('cancelled', $result['status']);
        $this->assertCancelled($update);
        $this->assertDatabaseCount('product_drafts', 0);
        $this->assertSame($outboundAtReset, Http::recorded()->count());
        $this->assertSame('cancelled', $update->aiRuns()->firstOrFail()->response['status']);
        Queue::assertNothingPushed();
    }

    public function test_reset_during_configuration_correction_is_checked_again_after_that_call(): void
    {
        $update = $this->update();
        $calls = 0;
        $outboundAtReset = 0;
        ProductResearchAgent::fake(function () use (&$calls, &$outboundAtReset): array {
            $calls++;
            if ($calls === 1) {
                return [...$this->researchResult(), 'specifications' => [
                    ['key' => 'gpu', 'name' => 'GPU', 'value' => 'RTX 5090 or RTX 5080, varies by SKU'],
                ]];
            }
            $this->resetChat();
            $outboundAtReset = Http::recorded()->count();

            return $this->researchResult();
        })->preventStrayPrompts();
        $resolver = $this->mock(ProductImageResolver::class);
        $resolver->shouldNotReceive('resolve');
        $storage = $this->mock(ProductImageStorage::class);
        $storage->shouldNotReceive('stage');

        $result = json_decode((new ResearchProduct($update, $resolver, imageStorage: $storage))
            ->handle(new Request(['query' => 'MSI Titan 18 HX AI'])), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(2, $calls);
        $this->assertSame('cancelled', $result['status']);
        $this->assertDatabaseCount('product_drafts', 0);
        $this->assertSame($outboundAtReset, Http::recorded()->count());
        $this->assertSame(0, AiRun::query()->where('status', 'running')->count());
    }

    public static function providerOutcomes(): array
    {
        return ['response' => [false], 'provider error' => [true]];
    }

    private function resetChat(): void
    {
        $this->postJson('/api/telegram/webhook', [
            'update_id' => random_int(1000000, 2000000),
            'message' => [
                'message_id' => 100, 'from' => ['id' => 12345],
                'chat' => ['id' => 98765], 'text' => '/reset',
            ],
        ], ['X-Telegram-Bot-Api-Secret-Token' => 'test-secret'])->assertOk();
    }

    private function assertCancelled(TelegramUpdate $update): void
    {
        $this->assertSame('cancelled', $update->fresh()->status);
        $this->assertNotNull($update->fresh()->cancel_requested_at);
        $this->assertNotNull($update->fresh()->processed_at);
    }

    private function update(): TelegramUpdate
    {
        return TelegramUpdate::query()->create([
            'update_id' => random_int(2000, 90000), 'telegram_user_id' => '12345',
            'chat_id' => '98765', 'text' => 'MSI Titan 18 HX AI', 'payload' => [],
            'status' => 'processing',
        ]);
    }

    private function createRun(TelegramUpdate $update): AiRun
    {
        return AiRun::query()->create([
            'telegram_update_id' => $update->id, 'provider' => 'fake', 'model' => 'fake',
            'status' => 'running', 'prompt' => 'fixture', 'started_at' => now(),
        ]);
    }

    private function researchResult(): array
    {
        return [
            'status' => 'found', 'title' => 'MSI Titan 18 HX AI A2XWJG-614',
            'brand' => 'MSI', 'model' => 'A2XWJG-614', 'product_type' => 'laptop',
            'category' => 'laptops', 'color' => 'Black', 'description' => 'Gaming laptop.',
            'specifications' => [['key' => 'sku', 'name' => 'SKU', 'value' => 'A2XWJG-614']],
            'sources' => [['title' => 'Exact retailer offer', 'url' => 'https://shop.example/a2xwjg-614', 'type' => 'retailer', 'image_urls' => []]],
            'primary_source_url' => 'https://shop.example/a2xwjg-614',
            'official_source_url' => null, 'research_notes' => null,
            'image_urls' => [], 'confidence' => 0.95,
        ];
    }
}
