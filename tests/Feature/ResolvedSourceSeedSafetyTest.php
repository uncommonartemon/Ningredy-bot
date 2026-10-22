<?php

namespace Tests\Feature;

use App\Ai\Agents\ProductImageDiscoveryAgent;
use App\Ai\Agents\ProductImageVisionAgent;
use App\Ai\Agents\ProductPhotoApplicabilityAgent;
use App\Ai\Agents\ProductSourceIdentityAgent;
use App\Models\AiRun;
use App\Models\AppSetting;
use App\Models\ProductDraft;
use App\Models\ProductSourceAttempt;
use App\Models\TelegramUpdate;
use App\Services\Products\ProductImageResolver;
use App\Services\Products\ProductImageStorage;
use App\Services\Products\ProductSourceIdentityJudge;
use App\Services\Products\ProductSpecificationReconciler;
use App\Services\Products\SpecificationReconciliationOutcome;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ResolvedSourceSeedSafetyTest extends TestCase
{
    use RefreshDatabase;

    private const SOURCE_URL = 'https://shop.example/products/stealth-009it';

    private const SOURCE_SEED = 'https://cdn.example/product-source-photo.jpg';

    private const DRAFT_SEED = 'https://cdn.example/product-draft-photo.jpg';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Http::preventStrayRequests();
        config(['product-images.source_preflight' => false, 'product-images.fallback_discovery' => false]);
        AppSetting::put('ai.fallback_sources_enabled', '0');
        AppSetting::put('ai.source_identity_agent_enabled', '1');
        ProductImageDiscoveryAgent::fake()->preventStrayPrompts();
        ProductImageVisionAgent::fake()->preventStrayPrompts();
        ProductSourceIdentityAgent::fake(function (string $prompt): array {
            $page = json_decode($prompt, true);

            return [
                'match' => str_contains($page['evidence_title'] ?? '', '008US') ? 'conflicting' : 'uncertain',
                'confidence' => 0.95,
                'reason' => 'Fixture verdict based on the newly observed page, not old search seeds.',
            ];
        })->preventStrayPrompts();
        ProductPhotoApplicabilityAgent::fake(fn (string $prompt): array => [
            'verdict' => str_contains($prompt, '008US') ? 'conflicting' : 'uncertain',
            'reason' => 'Fixture verdict on the newly observed page.', 'source_model' => '', 'differences' => [], 'evidence' => [],
            'plausible_target' => true, 'may_inspect_gallery' => ! str_contains($prompt, '008US'),
            'physical_checks' => array_fill_keys(['platform', 'revision', 'construction', 'dimensions', 'ports', 'color'],
                ['status' => 'unknown', 'evidence' => '']),
        ])->preventStrayPrompts();
        $this->mock(ProductSpecificationReconciler::class)->shouldNotReceive('reconcile');
    }

    public static function entryPoints(): array
    {
        return [['stage'], ['top_up']];
    }

    #[DataProvider('entryPoints')]
    public function test_a_zero_image_result_with_a_conflicting_page_does_not_revive_old_seeds(string $entryPoint): void
    {
        $draft = $this->draft(explicitSku: true);
        $resolver = $this->resolver([
            'url' => 'https://shop.example/products/stealth-008us',
            'title' => 'MSI Stealth 16 AI A2HWGG-008US',
            '_preflight_identity_evidence' => 'This listing sells MSI Stealth 16 AI A2HWGG-008US.',
        ]);
        $resolver->shouldNotReceive('download');

        $result = $this->invokeEntryPoint($entryPoint, $draft);

        $this->assertSame(0, $result);
        // Top-up asks the identity judge; stage asks photo applicability about the same page.
        $this->assertTrue(ProductSourceAttempt::query()
            ->where('product_draft_id', $draft->id)
            ->where(fn ($query) => $query->where('decision', 'reject_runtime_identifier_mismatch')
                ->orWhere(fn ($query) => $query->where('action', 'assess_photo_applicability')->where('decision', 'conflicting')))
            ->exists());
        $this->assertSame(0, $draft->media()->count());
        $this->assertNull($draft->fresh()->specifications_reconciled_source_url);
        Http::assertNothingSent();
    }

    #[DataProvider('entryPoints')]
    public function test_a_conflict_already_observed_by_training_blocks_seeds_without_another_judge_call(string $entryPoint): void
    {
        $draft = $this->draft(explicitSku: true);
        $landedUrl = 'https://shop.example/products/stealth-family';
        $resolver = $this->resolver([
            'url' => $landedUrl,
            // The outer page snapshot is incomplete. Without the propagated
            // verdict this would ask the judge again or admit the old seeds.
            'title' => 'MSI Stealth 16 AI',
            '_preflight_identity_evidence' => 'Product family; exact configuration not shown.',
            '_observed_identity' => [
                'verdict' => 'conflicting',
                'source' => [
                    'url' => $landedUrl,
                    'title' => 'MSI Stealth 16 AI A2HWGG-008US',
                    '_preflight_identity_evidence' => 'The observed product heading identifies A2HWGG-008US.',
                ],
                'product_draft_id' => $draft->id,
                'telegram_update_id' => $draft->telegram_update_id,
            ],
        ]);
        $resolver->shouldNotReceive('download');
        // A second AI call may be unavailable in reality. The completed
        // verdict for this same draft/run/page must survive independently.
        $this->mock(ProductSourceIdentityJudge::class)->shouldNotReceive('judge');

        $result = $this->invokeEntryPoint($entryPoint, $draft);

        $this->assertSame(0, $result);
        $this->assertTrue(ProductSourceAttempt::query()
            ->where('product_draft_id', $draft->id)
            ->where('decision', 'reject_runtime_identifier_mismatch')->exists());
        $this->assertSame(0, $draft->media()->count());
        $this->assertNull($draft->fresh()->specifications_reconciled_source_url);
        Http::assertNothingSent();
    }

    #[DataProvider('entryPoints')]
    public function test_uncertain_page_evidence_for_a_broad_request_keeps_the_seed_candidates(string $entryPoint): void
    {
        $draft = $this->draft(explicitSku: false);
        $resolver = $this->resolver([
            'url' => 'https://shop.example/products/stealth-family',
            'title' => 'MSI Stealth 16 AI',
            '_preflight_identity_evidence' => 'Product family; exact configuration not shown.',
        ]);
        $downloaded = [];
        if ($entryPoint === 'stage') {
            // Zero photos still owes the operator a text card, assembled
            // against the page that was not rejected. Unlike the conflicting
            // cases above, an uncertain family page for a broad request is
            // that page; the outcome is left unreconciled here.
            $this->mock(ProductSpecificationReconciler::class)->shouldReceive('reconcile')->once()
                ->withArgs(fn (ProductDraft $draft, array $source): bool => $source['url'] === self::SOURCE_URL)
                ->andReturn(SpecificationReconciliationOutcome::unavailable('budget_exhausted'));
        }
        // Returning null keeps this a candidate-admission test, not a fake
        // proof of image quality or publication of an uncertain product.
        // Top-up visits its gallery once; stage also considers the draft seed.
        $this->downloadManyThroughDownload($resolver);
        $resolver->shouldReceive('download')->times($entryPoint === 'stage' ? 2 : 1)
            ->andReturnUsing(function (string $url) use (&$downloaded): ?array {
                $downloaded[] = $url;

                return null;
            });

        $result = $this->invokeEntryPoint($entryPoint, $draft);

        $this->assertSame(0, $result);
        $this->assertContains(self::SOURCE_SEED, $downloaded, json_encode(ProductSourceAttempt::query()
            ->where('product_draft_id', $draft->id)->get(['action', 'decision', 'output'])->toArray()));
        if ($entryPoint === 'stage') {
            $this->assertContains(self::DRAFT_SEED, $downloaded);
        }
        $this->assertFalse(ProductSourceAttempt::query()
            ->where('product_draft_id', $draft->id)
            ->where('decision', 'reject_runtime_identifier_mismatch')->exists());
        $this->assertNull($draft->fresh()->specifications_reconciled_source_url);
        Http::assertNothingSent();
    }

    private function resolver(array $pageContext): MockInterface
    {
        $resolver = $this->mock(ProductImageResolver::class);
        // Each public entry point must check even a zero-image result before
        // merging saved seeds. Top-up must not reopen the exhausted source.
        $resolver->shouldReceive('resolve')->once()->andReturn([]);
        $resolver->shouldReceive('sourceContextForPage')->with(self::SOURCE_URL)
            ->once()->andReturn($pageContext);
        $resolver->shouldReceive('sourceContextForImage')->andReturn(null)->byDefault();
        $resolver->shouldReceive('specificationTextForPage')->andReturn(null)->byDefault();
        $resolver->shouldReceive('isConfirmedGalleryImage')->andReturn(false)->byDefault();
        $resolver->shouldReceive('isPartialGalleryImage')->andReturn(false)->byDefault();

        return $resolver;
    }

    private function invokeEntryPoint(string $entryPoint, ProductDraft $draft): int
    {
        $storage = app(ProductImageStorage::class);

        return match ($entryPoint) {
            'stage' => $storage->stage($draft),
            'top_up' => $storage->topUpDraftMedia($draft),
        };
    }

    private function draft(bool $explicitSku): ProductDraft
    {
        $update = TelegramUpdate::query()->create([
            'update_id' => 900001, 'telegram_user_id' => '1', 'chat_id' => '1', 'payload' => [],
            'text' => $explicitSku
                ? 'MSI Stealth 16 AI A2HWGG-009IT, MPN 9S7-15F515-009'
                : 'MSI Stealth 16 AI laptop',
            'status' => 'completed',
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
            'primary_source_url' => self::SOURCE_URL,
            'sources' => [[
                'url' => self::SOURCE_URL, 'type' => 'retailer',
                // Exact initial evidence passes the cheap gate; only the later
                // browser observation reveals that the product changed.
                'title' => 'MSI Stealth 16 AI A2HWGG-009IT 9S7-15F515-009',
                'image_urls' => [self::SOURCE_SEED],
            ]],
            'image_urls' => [self::DRAFT_SEED],
        ]);
    }
}
