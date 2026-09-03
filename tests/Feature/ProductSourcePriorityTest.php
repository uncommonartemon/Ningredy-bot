<?php

namespace Tests\Feature;

use App\Models\ProductGalleryRecipe;
use App\Services\Products\ProductSourceMetrics;
use App\Services\Products\ProductSourcePriority;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductSourcePriorityTest extends TestCase
{
    use RefreshDatabase;

    public function test_unknown_sources_keep_input_order_regardless_of_source_type(): void
    {
        $sources = [
            ['url' => 'https://manufacturer.example/product', 'type' => 'manufacturer'],
            ['url' => 'https://marketplace.example/product', 'type' => 'marketplace'],
            ['url' => 'https://retailer.example/product', 'type' => 'retailer'],
        ];

        $this->assertSame(
            $sources,
            app(ProductSourcePriority::class)->sortSources($sources, 'Example'),
        );
    }

    public function test_only_verified_extraction_history_changes_source_order(): void
    {
        ProductGalleryRecipe::query()->create([
            'domain' => 'manufacturer.example',
            'path_pattern' => '*',
            'status' => 'active',
            'success_count' => 2,
            'failure_count' => 1,
            'recipe' => ['collect_selectors' => ['[data-gallery] img']],
        ]);
        ProductGalleryRecipe::query()->create([
            'domain' => 'marketplace.example',
            'path_pattern' => '*',
            'status' => 'active',
            'success_count' => 10,
            'failure_count' => 0,
            'recipe' => ['collect_selectors' => ['[data-gallery] img']],
        ]);

        $sorted = app(ProductSourcePriority::class)->sortSources([
            ['url' => 'https://unknown.example/product', 'type' => 'retailer'],
            ['url' => 'https://manufacturer.example/product', 'type' => 'manufacturer'],
            ['url' => 'https://marketplace.example/product', 'type' => 'marketplace'],
        ], 'Example');

        $this->assertSame([
            'https://marketplace.example/product',
            'https://manufacturer.example/product',
            'https://unknown.example/product',
        ], array_column($sorted, 'url'));
    }

    public function test_vision_accepted_gallery_history_outranks_extraction_only_and_failed_domains(): void
    {
        $metrics = app(ProductSourceMetrics::class);
        $metrics->recordExtraction('https://accepted.example/product', 5);
        $metrics->recordAcceptedGallery('https://accepted.example/product', 3);
        $metrics->recordExtraction('https://extracted.example/product', 4);
        $metrics->recordExtraction('https://failed.example/product', 0, 'access_gate');

        $sorted = app(ProductSourcePriority::class)->sortSources([
            ['url' => 'https://failed.example/product', 'type' => 'manufacturer'],
            ['url' => 'https://unknown.example/product', 'type' => 'marketplace'],
            ['url' => 'https://extracted.example/product', 'type' => 'retailer'],
            ['url' => 'https://accepted.example/product', 'type' => 'review'],
        ], 'Example');

        $this->assertSame([
            'https://accepted.example/product',
            'https://extracted.example/product',
            'https://unknown.example/product',
            'https://failed.example/product',
        ], array_column($sorted, 'url'));
    }

    public function test_disabled_playwright_domain_sorts_last_but_is_not_removed(): void
    {
        ProductGalleryRecipe::query()->create([
            'domain' => 'blocked.example',
            'path_pattern' => '*',
            'status' => 'disabled',
            'failure_count' => 2,
        ]);

        $sorted = app(ProductSourcePriority::class)->sortSources([
            ['url' => 'https://blocked.example/product', 'type' => 'marketplace'],
            ['url' => 'https://unknown.example/product', 'type' => 'manufacturer'],
        ], 'Example');

        $this->assertSame([
            'https://unknown.example/product',
            'https://blocked.example/product',
        ], array_column($sorted, 'url'));
    }

    public function test_an_image_hosted_on_an_unrelated_cdn_scores_by_its_source_page_domain(): void
    {
        // Manufacturer sites often host photos on a dedicated asset CDN (e.g.
        // HP's page is www.hp.com but its photos are on hp.widen.net) - the
        // CDN host itself never accumulates its own recipe/extraction history,
        // so scoring the raw image host would leave it stuck at neutral
        // forever even when it came from the very page with a proven recipe.
        ProductGalleryRecipe::query()->create([
            'domain' => 'manufacturer.example',
            'path_pattern' => '*',
            'status' => 'active',
            'success_count' => 5,
            'failure_count' => 0,
            'recipe' => ['collect_selectors' => ['[data-gallery] img']],
        ]);

        $sorted = app(ProductSourcePriority::class)->sortUrls(
            [
                'https://unrelated-cdn.example/photo.jpg',
                'https://cdn.manufacturer-assets.example/photo.jpg',
            ],
            'Example',
            [],
            ['https://cdn.manufacturer-assets.example/photo.jpg' => 'https://manufacturer.example/product'],
        );

        $this->assertSame([
            'https://cdn.manufacturer-assets.example/photo.jpg',
            'https://unrelated-cdn.example/photo.jpg',
        ], $sorted);
    }

    public function test_one_blocked_product_page_does_not_remove_the_whole_shop(): void
    {
        // The router lets a single blocked path stop only itself, but this list
        // was built from any one blocked row and removed the domain from every
        // ranking - so the source never reached the loop the router would have
        // allowed, and one refused product page took a whole catalogue out of
        // the search. A second blocked path is what makes it a domain.
        ProductGalleryRecipe::query()->create([
            'domain' => 'shop.example',
            'path_pattern' => '/p/one-product/*',
            'status' => 'disabled',
            'source_blocked' => true,
            'source_block_reason' => 'Confirmed WAF.',
        ]);

        $priority = app(ProductSourcePriority::class);

        $this->assertSame(['https://shop.example/p/other-product'], array_column($priority->sortSources([
            ['url' => 'https://shop.example/p/other-product', 'type' => 'retailer'],
        ], 'Example'), 'url'));

        ProductGalleryRecipe::query()->create([
            'domain' => 'shop.example',
            'path_pattern' => '/p/second-product/*',
            'status' => 'disabled',
            'source_blocked' => true,
            'source_block_reason' => 'Confirmed WAF.',
        ]);

        $this->assertSame([], app(ProductSourcePriority::class)->sortSources([
            ['url' => 'https://shop.example/p/other-product', 'type' => 'retailer'],
        ], 'Example'));
    }

    public function test_globally_blocked_source_is_removed_before_processing(): void
    {
        ProductGalleryRecipe::query()->create([
            'domain' => 'blocked.example',
            'path_pattern' => '*',
            'status' => 'disabled',
            'source_blocked' => true,
            'source_block_reason' => 'Confirmed WAF.',
        ]);

        $priority = app(ProductSourcePriority::class);
        $sources = $priority->sortSources([
            ['url' => 'https://shop.blocked.example/product', 'type' => 'marketplace'],
            ['url' => 'https://allowed.example/product', 'type' => 'retailer'],
        ], 'Example');

        $this->assertSame(['https://allowed.example/product'], array_column($sources, 'url'));
        $this->assertSame(['https://allowed.example/photo.jpg'], $priority->sortUrls([
            'https://cdn.blocked.example/photo.jpg',
            'https://allowed.example/photo.jpg',
        ], 'Example'));
    }
}
