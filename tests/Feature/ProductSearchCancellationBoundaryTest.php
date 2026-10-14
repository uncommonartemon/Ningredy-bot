<?php

namespace Tests\Feature;

use App\Ai\Agents\GalleryFrameReviewAgent;
use App\Ai\Agents\ProductImageDiscoveryAgent;
use App\Ai\Agents\ProductImageVisionAgent;
use App\Exceptions\ProductSearchCancelled;
use App\Models\AiRun;
use App\Models\AppSetting;
use App\Models\ProductDraft;
use App\Models\ProductSourceAttempt;
use App\Models\TelegramUpdate;
use App\Services\Products\ProductCardAssembly;
use App\Services\Products\ProductImageResolver;
use App\Services\Products\ProductImageStorage;
use App\Services\Products\ProductImageVisionVerifier;
use App\Services\Products\ProductSpecificationReconciler;
use App\Services\Products\SpecificationReconciliationOutcome;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProductSearchCancellationBoundaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Http::preventStrayRequests();
        config(['product-images.source_preflight' => false, 'product-images.fallback_discovery' => false]);
        AppSetting::put('ai.fallback_sources_enabled', '1');
        ProductImageDiscoveryAgent::fake()->preventStrayPrompts();
        ProductImageVisionAgent::fake()->preventStrayPrompts();
    }

    public function test_cancellation_inside_resolver_stops_before_download_vision_and_the_next_source(): void
    {
        $draft = $this->draft();
        $messages = [];
        $countAtCancel = null;
        $resolver = $this->mock(ProductImageResolver::class);
        $resolver->shouldReceive('resolve')->once()->andReturnUsing(function () use ($draft, &$messages, &$countAtCancel): array {
            $draft->telegramUpdate()->update(['status' => 'cancelled', 'cancel_requested_at' => now(), 'processed_at' => now()]);
            $countAtCancel = count($messages);

            return ['https://cdn.example/just-discovered.jpg'];
        });
        $resolver->shouldNotReceive('download');
        $this->mock(ProductSpecificationReconciler::class)->shouldNotReceive('reconcile');

        try {
            app(ProductImageStorage::class)->stage($draft, function (string $message) use (&$messages): void {
                $messages[] = $message;
            });
            $this->fail('Cancellation must propagate out of the gallery pipeline.');
        } catch (ProductSearchCancelled) {
            $this->assertSame($countAtCancel, count($messages));
        }

        $finish = ProductSourceAttempt::query()->where('action', 'cycle_finished')->firstOrFail();
        $this->assertSame('cancelled', $finish->status);
        $this->assertSame('cancelled', $finish->decision);
        $this->assertSame(ProductSearchCancelled::class, $finish->output['exception_class']);
        $this->assertSame(0, $finish->output['saved_file_count']);
        $this->assertSame('pending_review', $draft->fresh()->status);
        ProductImageVisionAgent::assertNeverPrompted();
        ProductImageDiscoveryAgent::assertNeverPrompted();
        GalleryFrameReviewAgent::assertNeverPrompted();
        Http::assertNothingSent();
    }

    public function test_cancellation_inside_reconciliation_does_not_buy_a_second_attempt_or_lose_saved_photos(): void
    {
        $draft = $this->draft();
        $path = 'drafts/'.$draft->id.'/retained.jpg';
        Storage::disk('public')->put($path, 'existing-photo');
        $media = $draft->media()->create([
            'disk' => 'public', 'path' => $path, 'source_url' => 'https://cdn.example/retained.jpg',
            'verification_status' => 'verified', 'width' => 800, 'height' => 800,
            'checksum' => hash('sha256', 'existing-photo'),
        ]);
        $this->mock(ProductSpecificationReconciler::class)->shouldReceive('reconcile')->once()
            ->andReturnUsing(function () use ($draft): SpecificationReconciliationOutcome {
                $draft->telegramUpdate()->update(['cancel_requested_at' => now()]);

                return SpecificationReconciliationOutcome::unavailable('technical_error');
            });

        try {
            app(ProductCardAssembly::class)->complete($draft, $draft->sources[0], $draft->telegram_update_id);
            $this->fail('Assembly must propagate cancellation, not return or retry the failed verdict.');
        } catch (ProductSearchCancelled) {
            $this->assertSame(1, $draft->media()->count());
        }

        Storage::disk('public')->assertExists($path);
        $this->assertNotNull($media->fresh());
        $this->assertNull($draft->fresh()->specifications_reconciled_source_url);
        $this->assertFalse(ProductSourceAttempt::query()->where('phase', 'card_assembly')->exists());
        Http::assertNothingSent();
    }

    public function test_cancellation_during_full_content_vision_prevents_the_next_batch(): void
    {
        $draft = $this->draft();
        config(['product-images.vision_candidates' => 1, 'product-images.vision_max_batches' => 3]);
        $candidates = array_map(fn (int $index): array => [
            'source_url' => 'https://cdn.example/frame-'.$index.'.jpg',
        ], [1, 2, 3]);
        $this->mock(ProductImageVisionVerifier::class)->shouldReceive('select')->once()
            ->withArgs(fn (ProductDraft $working, array $batch): bool => $working->is($draft)
                && $batch === [$candidates[0]])
            ->andReturnUsing(function () use ($draft): array {
                $draft->telegramUpdate()->update(['cancel_requested_at' => now()]);

                return [];
            });

        // The actual batch orchestrator, not a duplicate of its loop. Only the
        // external vision boundary is mocked; a second batch violates once().
        $this->expectException(ProductSearchCancelled::class);
        (new \ReflectionMethod(ProductImageStorage::class, 'verify'))->invoke(
            app(ProductImageStorage::class), $draft, $candidates, 3, $draft->telegram_update_id,
        );
    }

    private function draft(): ProductDraft
    {
        $update = TelegramUpdate::query()->create([
            'update_id' => 987654, 'telegram_user_id' => '1', 'chat_id' => '1', 'payload' => [],
            'text' => 'MSI Stealth 16 AI A2HWGG-009IT, MPN 9S7-15F515-009', 'status' => 'processing',
        ]);
        $run = AiRun::query()->create([
            'telegram_update_id' => $update->id, 'provider' => 'fake', 'model' => 'fake',
            'status' => 'completed', 'prompt' => 'fixture research', 'started_at' => now(), 'completed_at' => now(),
        ]);

        return ProductDraft::query()->create([
            'telegram_update_id' => $update->id, 'ai_run_id' => $run->id, 'requested_by_telegram_user_id' => '1',
            'status' => 'pending_review', 'brand' => 'MSI', 'category' => 'laptops', 'product_type' => 'laptop',
            'title' => 'MSI Stealth 16 AI A2HWGG-009IT', 'model' => 'Stealth 16 AI A2HWGG-009IT',
            'specifications' => [['key' => 'mpn', 'name' => 'MPN', 'value' => '9S7-15F515-009']],
            'primary_source_url' => 'https://first.example/stealth-009it',
            'sources' => array_map(fn (string $host): array => [
                'url' => 'https://'.$host.'/stealth-009it', 'type' => 'retailer',
                'title' => 'MSI Stealth 16 AI A2HWGG-009IT 9S7-15F515-009', 'image_urls' => [],
            ], ['first.example', 'second.example']),
            'image_urls' => [],
        ]);
    }
}
