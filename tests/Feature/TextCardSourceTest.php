<?php

namespace Tests\Feature;

use App\Ai\Agents\ProductImageDiscoveryAgent;
use App\Ai\Agents\ProductImageVisionAgent;
use App\Ai\Agents\ProductPhotoApplicabilityAgent;
use App\Ai\Agents\ProductSourceIdentityAgent;
use App\Models\AiRun;
use App\Models\AppSetting;
use App\Models\ProductDraft;
use App\Models\TelegramUpdate;
use App\Services\Products\ProductImageResolver;
use App\Services\Products\ProductImageStorage;
use App\Services\Products\ProductSpecificationReconciler;
use App\Services\Products\SpecificationReconciliationOutcome;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Request #94: the page research chose turned out to sell another product,
 * no photograph was found anywhere, and the operator got no card at all -
 * although research had found other pages for the same product.
 */
class TextCardSourceTest extends TestCase
{
    use RefreshDatabase;

    private const string CHOSEN = 'https://shop.example/products/stealth-009it';

    private const string NEXT = 'https://other.example/p/stealth-16-ai-009it';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Http::preventStrayRequests();
        config(['product-images.source_preflight' => false, 'product-images.fallback_discovery' => false]);
        AppSetting::put('ai.fallback_sources_enabled', '0');
        ProductImageDiscoveryAgent::fake()->preventStrayPrompts();
        ProductImageVisionAgent::fake()->preventStrayPrompts();
        ProductSourceIdentityAgent::fake(fn (string $prompt): array => [
            'match' => str_contains(json_decode($prompt, true)['evidence_title'] ?? '', '008US') ? 'conflicting' : 'uncertain',
            'confidence' => 0.95,
            'reason' => 'Fixture verdict on the opened page.',
        ])->preventStrayPrompts();
        // Source selection asks photo applicability about the opened page;
        // "conflicting" is its observation of a different physical product.
        ProductPhotoApplicabilityAgent::fake(fn (string $prompt): array => [
            'verdict' => str_contains($prompt, '008US') ? 'conflicting' : 'uncertain',
            'reason' => 'Fixture verdict on the opened page.', 'source_model' => '', 'differences' => [], 'evidence' => [],
            'plausible_target' => true, 'may_inspect_gallery' => ! str_contains($prompt, '008US'),
            'physical_checks' => array_fill_keys(['platform', 'revision', 'construction', 'dimensions', 'ports', 'color'],
                ['status' => 'unknown', 'evidence' => '']),
        ])->preventStrayPrompts();
    }

    public static function pagesBetween(): array
    {
        return ['next page follows directly' => [[]],
            // A page kept only for compatible photos sells another configuration.
            'compatible photo page in between' => [[['url' => 'https://photos.example/p/stealth-16-ai-007cz', 'type' => 'retailer',
                'title' => 'MSI Stealth 16 AI A2HWGG-007CZ', 'roles' => ['compatible_photo_candidate'],
                'applicability_reason' => 'Same chassis, different configuration.']]]];
    }

    #[DataProvider('pagesBetween')]
    public function test_a_text_card_is_built_from_the_next_research_page_when_the_chosen_one_sells_another_product(array $between): void
    {
        $draft = $this->draft($between);
        $resolver = $this->mock(ProductImageResolver::class);
        $resolver->shouldReceive('resolve')->andReturn([]);
        $resolver->shouldReceive('sourceContextForPage')->with(self::CHOSEN)->andReturn([
            'url' => 'https://shop.example/products/stealth-008us',
            'title' => 'MSI Stealth 16 AI A2HWGG-008US',
            '_preflight_identity_evidence' => 'This listing sells MSI Stealth 16 AI A2HWGG-008US.',
        ]);
        $resolver->shouldReceive('sourceContextForPage')->andReturn(null)->byDefault();
        $resolver->shouldReceive('sourceContextForImage')->andReturn(null)->byDefault();
        $resolver->shouldReceive('specificationTextForPage')->andReturn(null)->byDefault();
        $resolver->shouldReceive('isConfirmedGalleryImage')->andReturn(false)->byDefault();
        $resolver->shouldReceive('isPartialGalleryImage')->andReturn(false)->byDefault();
        $this->mock(ProductSpecificationReconciler::class)->shouldReceive('reconcile')->once()
            ->withArgs(fn (ProductDraft $draft, array $source): bool => $source['url'] === self::NEXT)
            ->andReturn(SpecificationReconciliationOutcome::reconciled(
                self::NEXT, 'MSI Stealth 16 AI A2HWGG-009IT', 'Stealth 16 AI A2HWGG-009IT', null,
                'An MSI Stealth 16 AI laptop.', [['key' => 'mpn', 'name' => 'MPN', 'value' => '9S7-15F515-009']], 'Built.',
            ));

        app(ProductImageStorage::class)->stage($draft);

        $draft->refresh();
        $this->assertSame(0, $draft->media()->count());
        $this->assertSame(self::NEXT, $draft->specifications_reconciled_source_url);
        $this->assertSame(self::NEXT, $draft->primary_source_url, 'the card names the page it was built from');
        $this->assertFalse($draft->reconciliationPending(), 'a finished text card, not "search not finished"');
    }

    private function draft(array $between = []): ProductDraft
    {
        $update = TelegramUpdate::query()->create([
            'update_id' => 900101, 'telegram_user_id' => '1', 'chat_id' => '1', 'payload' => [],
            'text' => 'MSI Stealth 16 AI A2HWGG-009IT, MPN 9S7-15F515-009', 'status' => 'completed',
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
            'primary_source_url' => self::CHOSEN,
            'sources' => [
                ['url' => self::CHOSEN, 'type' => 'retailer', 'title' => 'MSI Stealth 16 AI A2HWGG-009IT 9S7-15F515-009'],
                ...$between,
                ['url' => self::NEXT, 'type' => 'retailer', 'title' => 'MSI Stealth 16 AI A2HWGG-009IT'],
            ],
            'image_urls' => [],
        ]);
    }
}
