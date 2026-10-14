<?php

namespace Tests\Feature;

use App\Ai\Agents\ProductResearchAgent;
use App\Ai\Agents\ProductSpecificationReconciliationAgent;
use App\Ai\Tools\ResearchProduct;
use App\Models\AiRun;
use App\Models\ProductDraft;
use App\Models\ProductGalleryRecipe;
use App\Models\TelegramUpdate;
use App\Services\Products\ProductCardAssembly;
use App\Services\Products\ProductImageResolver;
use App\Services\Products\ProductImageStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Tools\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProductResearchTextEvidenceTest extends TestCase
{
    use RefreshDatabase;

    public static function scenarios(): array
    {
        return ['exact official, first response' => [false, false], 'exact official, corrected response' => [true, false],
            'generic official, first response' => [false, true], 'generic official, corrected response' => [true, true]];
    }

    #[DataProvider('scenarios')]
    public function test_research_keeps_conflicting_text_observations_separate_from_the_photo_queue(bool $correctFirst, bool $officialIsGeneric): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);
        ProductGalleryRecipe::create(['domain' => 'blocked.example', 'path_pattern' => '*', 'source_blocked' => true, 'status' => 'disabled', 'recipe' => []]);
        $update = TelegramUpdate::create(['update_id' => 921001, 'telegram_user_id' => '12345', 'chat_id' => '98765',
            'text' => 'MSI Titan 18 HX AI A2XW', 'payload' => [], 'status' => 'received']);
        $photoUrl = 'https://shop.example/a2xwjg-614';
        $official = ['key' => 'ram', 'name' => 'RAM', 'value' => '64 GB', 'source_url' => 'https://maker.example/exact.pdf',
            'source_excerpt' => 'A2XWJG-614 installed RAM: 64 GB', 'source_type' => 'manufacturer',
            'scope' => 'exact_configuration', 'applicability' => 'Explicit same SKU and regional revision.'];
        $retailer = [...$official, 'value' => '48 GB', 'source_url' => $photoUrl,
            'source_excerpt' => 'A2XWJG-614 installed RAM: 48 GB', 'source_type' => 'retailer',
            'applicability' => 'Same advertised SKU, but memory contradicts its exact official specification.'];
        if ($officialIsGeneric) {
            $official = [...$official, 'value' => 'up to 96 GB', 'source_excerpt' => 'A2XW family supports up to 96 GB',
                'scope' => 'product_family', 'applicability' => 'Family capability, not the installed configuration.'];
            $retailer = [...$retailer, 'value' => '64 GB', 'source_excerpt' => 'A2XWJG-614 installed RAM: 64 GB',
                'applicability' => 'Installed configuration of this exact offer.'];
        }
        $blocked = [...$official, 'source_url' => 'https://blocked.example/specs'];
        $evidence = [$official, $retailer];
        $card = ['status' => 'found', 'title' => 'MSI Titan 18 HX AI A2XWJG-614', 'brand' => 'MSI',
            'model' => 'A2XWJG-614', 'category' => 'laptops', 'product_type' => 'laptop', 'color' => 'Black',
            'description' => 'Gaming laptop.', 'research_notes' => null, 'official_source_url' => null,
            'specifications' => [['key' => 'sku', 'name' => 'SKU', 'value' => 'A2XWJG-614'],
                ['key' => 'ram', 'name' => 'RAM', 'value' => '64 GB']],
            'specification_evidence' => [...$evidence, $blocked],
            'sources' => [['title' => 'Exact retailer offer', 'url' => $photoUrl, 'type' => 'retailer']],
            'primary_source_url' => $photoUrl, 'image_urls' => [], 'confidence' => 0.95];
        $responses = $correctFirst ? [[...$card, 'specifications' => [
            ['key' => 'sku', 'name' => 'SKU', 'value' => 'A2XW family; variants include A2XWJG and A2XWIG'],
            ['key' => 'gpu', 'name' => 'GPU', 'value' => 'RTX 5090 or RTX 5080, varies by SKU'],
        ]], $card] : [$card];
        ProductResearchAgent::fake($responses)->preventStrayPrompts();
        $selected = $officialIsGeneric ? $retailer : $official;
        ProductSpecificationReconciliationAgent::fake(function (string $prompt) use ($evidence, $official, $photoUrl, $card, $selected, $officialIsGeneric): array {
            $payload = json_decode($prompt, true);
            $this->assertEquals($evidence, $payload['saved_text_evidence']);
            $this->assertEquals($evidence, $payload['differing_cited_observations']['ram']);

            // This is a test of information delivery and validation, not proof
            // that a live model will always make the correct source judgment.
            return ['overall_status' => 'reconciled', 'summary' => 'Exact configuration checked.',
                'title' => $card['title'], 'model' => $card['model'], 'color' => 'Black', 'description' => '64 GB installed.',
                'specifications' => [
                    ['key' => 'sku', 'name' => 'SKU', 'value' => 'A2XWJG-614', 'evidence' => 'confirmed',
                        'source_url' => $selected['source_url'], 'source_excerpt' => $selected['source_excerpt']],
                    [...$selected, 'evidence' => 'confirmed', 'considered_source_urls' => [$official['source_url'], $photoUrl],
                        'reason' => $officialIsGeneric ? 'Official family maximum is not installed RAM; the exact offer documents 64 GB.'
                            : 'The official document identifies this exact SKU and installed memory; the conflicting shop entry was reviewed.'],
                ]];
        })->preventStrayPrompts();
        $resolver = $this->mock(ProductImageResolver::class);
        $resolver->shouldNotReceive('resolve');
        $storage = $this->mock(ProductImageStorage::class);
        $storage->shouldReceive('stage')->once()->andReturnUsing(function (ProductDraft $draft) use ($evidence, $photoUrl): int {
            $this->assertEquals($evidence, $draft->fresh()->specification_evidence);
            $this->assertSame([$photoUrl], array_column($draft->sources, 'url'));
            $outcome = app(ProductCardAssembly::class)->complete($draft, ['url' => $photoUrl], $draft->telegram_update_id);
            $this->assertTrue($outcome->isReconciled(), $outcome->summary ?? '');
            $draft->update(['specifications_reconciled_source_url' => $photoUrl, 'specifications' => $outcome->specifications]);

            return 3;
        });

        $result = json_decode((new ResearchProduct($update, $resolver, imageStorage: $storage))
            ->handle(new Request(['query' => $update->text])), true);
        $this->assertSame('found', $result['status']);
        $this->assertSame($correctFirst ? 3 : 2, AiRun::count());
        $draft = ProductDraft::firstOrFail();
        $this->assertEquals($evidence, $draft->specification_evidence);
        $this->assertSame($selected['source_url'], collect($draft->specifications)->firstWhere('key', 'ram')['source_url']);
    }
}
