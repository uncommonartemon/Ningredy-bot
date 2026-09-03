<?php

namespace Tests\Feature;

use App\Models\ProductGalleryRecipe;
use App\Services\Products\ProductGalleryRecipeRouter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GalleryAccessGateBackoffTest extends TestCase
{
    use RefreshDatabase;

    public function test_one_blocked_path_does_not_take_playwright_away_from_the_whole_domain(): void
    {
        // Real case (2026-09-02): a single Dell product page on B&H met a
        // Cloudflare challenge and Playwright was switched off for every
        // bhphotovideo.com path, including three recipes that were training
        // normally at that moment.
        ProductGalleryRecipe::query()->create([
            'domain' => 'shop.example.com',
            'path_pattern' => '/c/product/111/*',
            'status' => 'disabled',
            'source_blocked' => true,
        ]);
        ProductGalleryRecipe::query()->create([
            'domain' => 'shop.example.com',
            'path_pattern' => '/c/product/222/*',
            'status' => 'active',
        ]);
        $router = app(ProductGalleryRecipeRouter::class);

        $this->assertTrue($router->domainIsBlocked('https://shop.example.com/c/product/111/thing.html'));
        $this->assertFalse($router->domainIsBlocked('https://shop.example.com/c/product/222/other.html'));
    }

    public function test_a_second_blocked_path_is_what_earns_the_domain_wide_verdict(): void
    {
        // A site that genuinely refuses the browser refuses more than one page.
        foreach (['/c/product/111/*', '/c/product/222/*'] as $path) {
            ProductGalleryRecipe::query()->create([
                'domain' => 'shop.example.com',
                'path_pattern' => $path,
                'status' => 'disabled',
                'source_blocked' => true,
            ]);
        }
        $router = app(ProductGalleryRecipeRouter::class);

        $this->assertTrue($router->domainIsBlocked('https://shop.example.com/c/product/333/never-seen.html'));
    }
}
