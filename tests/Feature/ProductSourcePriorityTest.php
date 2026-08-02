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
