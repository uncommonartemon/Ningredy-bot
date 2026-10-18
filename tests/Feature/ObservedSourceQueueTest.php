<?php

namespace Tests\Feature;

use App\Models\ProductDraft;
use App\Models\ProductSourceAttempt;
use App\Services\Products\ProductImageResolver;
use App\Services\Products\ProductSourcePageMetadata;
use App\Services\ProductSearch\SourceQueueBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ObservedSourceQueueTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    public function test_preflight_identifies_the_service_page_without_borrowing_a_compatible_laptop(): void
    {
        $url = 'https://93.184.216.34/service';
        Http::fake([$url => Http::response(<<<'HTML'
            <html><title>3 anni di assistenza HP</title><h1>HP Care Pack</h1>
            <script type="application/ld+json">{
              "@type":"Product", "name":"HP Care Pack", "sku":"U1PS4E",
              "isRelatedTo":{"@type":"Product", "name":"HP OmniBook 3 17-dp0005sfx", "sku":"D91RYEA"}
            }</script>
            <script type="application/ld+json">{
              "@type":"ItemList", "itemListElement":[{"@type":"Product", "name":"HP OmniBook 3 17-dp0005sfx"}]
            }</script></html>
            HTML, 200, ['Content-Type' => 'text/html'])]);

        $result = app(ProductImageResolver::class)->preflightSource([
            'url' => $url, 'title' => 'HP OmniBook 3 17-dp0005sfx', 'type' => 'manufacturer',
        ]);

        $this->assertStringContainsString('U1PS4E', $result['identity_evidence']);
        $this->assertStringNotContainsString('17-dp0005sfx', $result['identity_evidence']);
        $this->assertStringNotContainsString('D91RYEA', $result['identity_evidence']);
        $this->assertSame('U1PS4E', $result['page_identity']['product']['sku']);
        // Full text is still available as context, but is not the main item's identity.
        $this->assertStringContainsString('17-dp0005sfx', $result['specification_text']);
    }

    public function test_reciprocal_locale_mirrors_are_reserved_after_independent_sources_without_being_deleted(): void
    {
        config(['product-images.source_preflight' => true, 'product-images.max_preflight_sources' => 4]);
        $cz = 'https://93.184.216.34/cz/lot-7131457572';
        $pl = 'https://93.184.216.35/pl/lot-7131457572';
        $other = 'https://93.184.216.36/laptop';
        Http::fake([
            $cz => Http::response($this->html($cz, [$pl], '7131457572', ['/cz-photo.jpg']), 200, ['Content-Type' => 'text/html']),
            $pl => Http::response($this->html($pl, [$cz], '7131457572', ['/pl-photo.jpg']), 200, ['Content-Type' => 'text/html']),
            $other => Http::response($this->html($other, [], 'other', []), 200, ['Content-Type' => 'text/html']),
        ]);
        $draft = new ProductDraft(['brand' => 'HP', 'model' => '17-dp0005sfx',
            'sources' => array_map(fn ($url) => ['url' => $url, 'type' => 'retailer'], [$cz, $pl, $other])]);

        [$queue, $cards] = app(SourceQueueBuilder::class)->buildSourceQueue($draft, [], [], true, 3, null, null);

        $this->assertSame([$cz, $other, $pl], $cards->pluck('url')->all());
        $this->assertSame([$cz, $other, $pl], $queue->pluck('url')->all());
        $this->assertSame($cz, $cards[2]['_mirror_of']);
        $this->assertFalse($cards[2]['_mirror_evidence']['same_observed_product_assets']);
        $this->assertDatabaseHas('product_source_attempts', ['product_url' => $pl, 'action' => 'defer_observed_mirror']);
        $this->assertSame(1, ProductSourceAttempt::query()->where('action', 'defer_observed_mirror')->count());
    }

    public function test_matching_sku_and_similar_host_names_alone_do_not_create_mirrors(): void
    {
        $a = $this->source('https://shop.cz/p/123', [], '123');
        $b = $this->source('https://shop.pl/p/123', [], '123');

        $this->assertNull(ProductSourcePageMetadata::mirrorEvidence($a, $b));
    }

    public function test_regional_identifiers_are_not_merged_even_with_reciprocal_alternate_links(): void
    {
        $aUrl = 'https://shop.cz/laptop';
        $bUrl = 'https://shop.pl/laptop';
        $a = $this->source($aUrl, [$bUrl], 'CZ-123');
        $b = $this->source($bUrl, [$aUrl], 'PL-123');

        $this->assertNull(ProductSourcePageMetadata::mirrorEvidence($a, $b));
    }

    public function test_missing_identifiers_do_not_reject_a_model_only_source_or_invent_a_mirror(): void
    {
        $a = $this->source('https://shop.cz/laptop', ['https://shop.pl/laptop'], '123');
        $b = $this->source('https://shop.pl/laptop', ['https://shop.cz/laptop'], null);
        $result = app(SourceQueueBuilder::class)->independentObservedSourcesFirst(collect([$a, $b]), new ProductDraft, null);

        $this->assertCount(2, $result);
        $this->assertArrayNotHasKey('_mirror_of', $result[1]);
    }

    public function test_same_asset_claim_requires_actual_observed_urls_not_translated_filename_suffixes(): void
    {
        $aUrl = 'https://shop.cz/p/123';
        $bUrl = 'https://shop.pl/p/123';
        $a = $this->source($aUrl, [$bUrl], '123', ['https://cdn.cz/photo-1.jpg', 'https://cdn.cz/photo-2.jpg']);
        $b = $this->source($bUrl, [$aUrl], '123', ['https://cdn.pl/photo-1.jpg', 'https://cdn.pl/photo-2.jpg']);
        $this->assertFalse(ProductSourcePageMetadata::mirrorEvidence($a, $b)['same_observed_product_assets']);

        $b['_preflight_page_identity']['product_image_urls'] = $a['_preflight_page_identity']['product_image_urls'];
        $this->assertTrue(ProductSourcePageMetadata::mirrorEvidence($a, $b)['same_observed_product_assets']);
    }

    public function test_an_unrelated_product_in_a_json_ld_graph_cannot_confirm_the_main_item(): void
    {
        $metadata = ProductSourcePageMetadata::fromHtml(<<<'HTML'
            <title>Warranty</title><link rel="canonical" href="/service">
            <script type="application/ld+json">{"@graph":[
              {"@type":"Product", "@id":"https://store.example/service#product", "name":"Warranty", "sku":"SERVICE"},
              {"@type":"Product", "url":"https://store.example/laptop", "name":"HP laptop", "sku":"LAPTOP"}
            ]}</script>
            HTML, 'https://store.example/service');

        $this->assertSame('SERVICE', $metadata['product']['sku']);
        $this->assertSame('https://store.example/service', $metadata['canonical_url']);
    }

    public function test_primary_microdata_identifiers_survive_without_collecting_nested_related_products(): void
    {
        $metadata = ProductSourcePageMetadata::fromHtml(<<<'HTML'
            <title>Notebook</title>
            <article itemscope itemtype="https://schema.org/Product">
              <meta itemprop="sku" content="CZ-REAL">
              <span itemprop="name">Actual laptop</span>
              <div itemprop="isRelatedTo" itemscope itemtype="https://schema.org/Product">
                <meta itemprop="sku" content="WRONG">
              </div>
            </article>
            HTML, 'https://store.example/laptop');

        $this->assertSame(['name' => 'Actual laptop', 'sku' => 'CZ-REAL'], $metadata['product']);
    }

    private function source(string $url, array $alternates, ?string $sku, array $images = []): array
    {
        return ['url' => $url, '_preflight_page_identity' => ProductSourcePageMetadata::fromHtml(
            $this->html($url, $alternates, $sku, $images), $url,
        )];
    }

    private function html(string $url, array $alternates, ?string $sku, array $images): string
    {
        $links = '<link rel="canonical" href="'.htmlspecialchars($url).'">';
        foreach ($alternates as $alternate) {
            $links .= '<link rel="alternate" hreflang="en" href="'.htmlspecialchars($alternate).'">';
        }
        $json = json_encode(['@type' => 'Product', 'name' => 'HP 17-dp0005sfx', 'sku' => $sku, 'image' => $images]);

        return '<html><head><title>HP 17-dp0005sfx</title>'.$links.'<script type="application/ld+json">'.$json.'</script></head></html>';
    }
}
