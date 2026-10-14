<?php

namespace Tests\Feature;

use App\Ai\Agents\ProductGalleryPreflightAgent;
use App\Ai\Agents\ProductGalleryRecipeTrainerAgent;
use App\Ai\Agents\ProductSourceIdentityAgent;
use App\Models\AiRun;
use App\Models\ProductDraft;
use App\Models\ProductGalleryRecipe;
use App\Models\ProductGalleryRecipeVersion;
use App\Models\TelegramUpdate;
use App\Services\Products\BrowserProductGalleryExtractor;
use App\Services\Products\ProductGalleryRecipeTrainer;
use App\Services\Products\ProductImageCandidateDiscovery;
use App\Services\Products\ProductImageResolver;
use App\Services\Products\ProductRequestContext;
use App\Services\Products\ProductSourceIdentityGate;
use App\Services\Products\ProductSourceIdentityJudge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BrowserSourceIdentityGateTest extends TestCase
{
    use RefreshDatabase;

    private const PAGE = 'https://shop.example/product/123';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        ProductGalleryRecipeTrainerAgent::fake()->preventStrayPrompts();
        ProductGalleryPreflightAgent::fake(fn (): array => [
            'decision' => 'no_gallery', 'page_kind' => 'product_card',
            'gallery_likely' => false, 'hidden_images_likely' => false,
            'interaction_required' => false, 'expected_image_count' => 0,
            'evidence' => [], 'confidence' => 0.8, 'reason' => 'No gallery in fixture.',
        ])->preventStrayPrompts();
    }

    public function test_browser_variant_conflict_stops_before_preflight_and_training_without_damaging_recipe(): void
    {
        $draft = $this->draft();
        $recipe = ProductGalleryRecipe::create(['domain' => 'shop.example', 'path_pattern' => '*',
            'status' => 'active', 'recipe' => ['collect_selectors' => ['.gallery img']], 'success_count' => 4]);
        $before = $recipe->fresh()->getAttributes();
        $this->scout('MSI Stealth 16 AI A2HWGG-008US', ['sku' => 'A2HWGG-008US']);
        $seen = [];
        ProductSourceIdentityAgent::fake(function (string $prompt) use (&$seen): array {
            $seen[] = json_decode($prompt, true);

            return ['match' => 'conflicting', 'confidence' => 1, 'reason' => 'Requested 009IT, observed 008US.'];
        })->preventStrayPrompts();
        $this->assertSame([], $this->train($draft));
        $this->assertCount(1, $seen);
        $this->assertSame('MSI Stealth 16 AI A2HWGG-009IT', $seen[0]['original_operator_request']);
        $this->assertStringContainsString('008US', $seen[0]['evidence_text']);
        ProductGalleryPreflightAgent::assertNeverPrompted();
        ProductGalleryRecipeTrainerAgent::assertNeverPrompted();
        $this->assertSame($before, $recipe->fresh()->getAttributes());
        $this->assertSame('skipped', ProductGalleryRecipeVersion::latest('id')->first()->status);
        $this->assertDatabaseHas('product_source_attempts', [
            'product_draft_id' => $draft->id, 'decision' => 'reject_conflicting_product_before_training',
        ]);
    }

    public function test_full_requested_model_without_retailer_sku_can_be_confirmed_before_training(): void
    {
        $draft = $this->draft();
        $this->scout('MSI Stealth 16 AI A2HWGG-009IT');
        ProductSourceIdentityAgent::fake(fn (): array => [
            'match' => 'confirmed', 'confidence' => 1, 'reason' => 'Full requested model is present.',
        ])->preventStrayPrompts();
        $this->train($draft);
        ProductSourceIdentityAgent::assertPrompted(fn (): bool => true);
        ProductGalleryPreflightAgent::assertPrompted(fn (): bool => true);
    }

    public function test_an_observed_conflict_cannot_be_downgraded_by_a_later_unavailable_check(): void
    {
        $draft = $this->draft();
        $this->mock(ProductSourceIdentityJudge::class)->shouldNotReceive('judge');
        $source = ['url' => self::PAGE, 'title' => 'MSI Stealth family'];
        $source['_observed_identity'] = [
            'verdict' => 'conflicting', 'product_draft_id' => $draft->id,
            'telegram_update_id' => $draft->telegram_update_id,
            'source' => ['url' => self::PAGE, 'title' => 'Observed wrong configuration'],
        ];
        $this->assertSame('conflicting', app(ProductSourceIdentityGate::class)->assess(
            $draft, $source, $draft->telegram_update_id,
        ));
    }

    public function test_an_observed_conflict_is_scoped_to_this_draft_run_and_page(): void
    {
        $draft = $this->draft();
        $this->mock(ProductSourceIdentityJudge::class)->shouldReceive('judge')->times(3)->andReturn('unavailable');
        $observed = [
            'verdict' => 'conflicting', 'product_draft_id' => $draft->id,
            'telegram_update_id' => $draft->telegram_update_id,
            'source' => ['url' => self::PAGE],
        ];
        foreach ([['product_draft_id' => $draft->id + 1],
            ['telegram_update_id' => $draft->telegram_update_id + 1],
            ['source' => ['url' => 'https://shop.example/another-page']]] as $difference) {
            $this->assertSame('unavailable', app(ProductSourceIdentityGate::class)->assess($draft, [
                'url' => self::PAGE, 'title' => 'MSI Stealth family',
                '_observed_identity' => [...$observed, ...$difference],
            ], $draft->telegram_update_id));
        }
    }

    public function test_missing_code_is_not_rejected_as_a_conflict_before_observing_gallery(): void
    {
        $draft = $this->draft();
        $this->scout('MSI Stealth 16 AI A2HWGG');
        $this->train($draft);
        ProductGalleryPreflightAgent::assertPrompted(fn (): bool => true);
        $this->assertDatabaseMissing('product_source_attempts', ['decision' => 'reject_conflicting_product_before_training']);
    }

    public function test_exact_mpn_requires_no_additional_identity_model_call(): void
    {
        $draft = $this->draft();
        $this->scout('MSI Stealth 16 AI A2HWGG-009IT', ['mpn' => '9S7-15F515-009']);
        $this->train($draft);
        ProductSourceIdentityAgent::assertNeverPrompted();
        ProductGalleryPreflightAgent::assertPrompted(fn (): bool => true);
    }

    public function test_fallback_runtime_family_match_does_not_hide_a_judged_variant_conflict(): void
    {
        $draft = $this->draft();
        $resolver = $this->mock(ProductImageResolver::class);
        $resolver->shouldReceive('sourceContextForPage')->andReturn(null);
        $resolver->shouldReceive('preflightSource')->once()->andReturn([]);
        $resolver->shouldReceive('resolve')->once()->andReturn(['https://cdn.example/laptop.jpg']);
        $resolver->shouldReceive('sourceContextForImage')->andReturn([
            'url' => self::PAGE, 'title' => 'MSI Stealth 16 AI A2HWGG-008US',
        ]);
        $resolver->shouldReceive('isConfirmedGalleryImage')->andReturn(true);
        ProductSourceIdentityAgent::fake(fn (): array => [
            'match' => 'conflicting', 'confidence' => 1, 'reason' => 'Different requested variant.',
        ])->preventStrayPrompts();
        $discovery = app(ProductImageCandidateDiscovery::class);
        $images = (new \ReflectionMethod($discovery, 'resolveSourcesIndividually'))
            ->invoke($discovery, [['url' => self::PAGE]], $draft);
        $this->assertSame([], $images);
        $this->assertDatabaseHas('product_source_attempts', [
            'product_draft_id' => $draft->id, 'action' => 'validate_discovery_runtime_identity',
            'decision' => 'reject_runtime_identifier_mismatch',
        ]);
    }

    public function test_actual_http_evidence_cannot_be_confirmed_by_a_stale_search_title(): void
    {
        $draft = $this->draft();
        $resolver = $this->mock(ProductImageResolver::class);
        $resolver->shouldReceive('preflightSource')->once()->andReturn([
            'final_url' => self::PAGE,
            'identity_evidence' => 'MSI Stealth 16 AI A2HWGG-008US',
        ]);
        $resolver->shouldNotReceive('resolve');
        ProductSourceIdentityAgent::fake(function (string $prompt): array {
            $payload = json_decode($prompt, true);
            $this->assertNull($payload['evidence_title']);
            $this->assertStringNotContainsString('009IT', $payload['evidence_text']);

            return ['match' => 'conflicting', 'confidence' => 1, 'reason' => 'Actual page sells another variant.'];
        })->preventStrayPrompts();
        $images = (new \ReflectionMethod(app(ProductImageCandidateDiscovery::class), 'resolveSourcesIndividually'))
            ->invoke(app(ProductImageCandidateDiscovery::class), [[
                'url' => self::PAGE,
                'title' => 'MSI Stealth 16 AI A2HWGG-009IT 9S7-15F515-009',
            ]], $draft);
        $this->assertSame([], $images);
        ProductSourceIdentityAgent::assertPrompted(fn (): bool => true);
    }

    private function scout(string $title, array $identifiers = []): void
    {
        $browser = $this->mock(BrowserProductGalleryExtractor::class);
        $browser->shouldReceive('rememberSourceIdentityAssessment')->byDefault();
        $browser->shouldReceive('scout')->once()->andReturn([
            'product_page_url' => self::PAGE,
            'product_identity' => ['name' => $title, 'identifiers' => $identifiers],
            'scout' => ['final_url' => self::PAGE, 'title' => $title,
                'product_headings' => [$title], 'fragments' => ['<div>Product</div>']],
        ]);
        $browser->shouldNotReceive('executeRecipe');
    }

    private function train(ProductDraft $draft): array
    {
        return app(ProductGalleryRecipeTrainer::class)->train(self::PAGE, force: true,
            telegramUpdateId: $draft->telegram_update_id,
            context: [ProductRequestContext::CONTEXT_KEY => $draft->id]);
    }

    private function draft(): ProductDraft
    {
        $update = TelegramUpdate::create(['update_id' => 981234, 'telegram_user_id' => '1',
            'chat_id' => '1', 'payload' => [], 'status' => 'processing',
            'text' => 'MSI Stealth 16 AI A2HWGG-009IT']);
        $run = AiRun::create(['telegram_update_id' => $update->id, 'provider' => 'fake', 'model' => 'fake',
            'status' => 'completed', 'prompt' => 'research', 'started_at' => now(), 'completed_at' => now()]);

        return ProductDraft::create(['telegram_update_id' => $update->id, 'ai_run_id' => $run->id,
            'requested_by_telegram_user_id' => '1', 'status' => 'pending_review',
            'title' => 'MSI Stealth 16 AI A2HWGG-009IT', 'model' => 'Stealth 16 AI A2HWGG-009IT',
            'brand' => 'MSI', 'category' => 'laptops', 'sources' => [], 'image_urls' => [],
            'specifications' => [['key' => 'mpn', 'value' => '9S7-15F515-009'],
                ['key' => 'sku', 'value' => 'BBU9285H32GXXDX11MA']]]);
    }
}
