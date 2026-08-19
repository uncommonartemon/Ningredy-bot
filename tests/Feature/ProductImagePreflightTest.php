<?php

namespace Tests\Feature;

use App\Models\ProductGalleryRecipe;
use App\Services\Products\ProductImageResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ProductImagePreflightTest extends TestCase
{
    use RefreshDatabase;

    public function test_preflight_escalates_http_403_to_a_browser_probe_instead_of_blocking(): void
    {
        Http::fake([
            'https://93.184.216.34/product' => Http::response('Forbidden', 403),
        ]);

        $result = app(ProductImageResolver::class)->preflightSource([
            'url' => 'https://93.184.216.34/product',
        ]);

        // A single 403 disables only the cheap static path; Playwright may
        // still open the real product page, so the whole source is not
        // blocked - that state is reserved for a confirmed domain block.
        $this->assertFalse($result['blocked']);
        $this->assertTrue($result['browser_probe_required']);
        $this->assertSame('http_403', $result['reason']);
    }

    public function test_preflight_reports_static_images_and_an_active_domain_recipe(): void
    {
        ProductGalleryRecipe::query()->create([
            'domain' => '93.184.216.34',
            'path_pattern' => '*',
            'status' => 'active',
            'recipe' => [],
        ]);
        Http::fake([
            'https://93.184.216.34/product' => Http::response(
                '<meta property="og:image" content="/images/full.webp">',
                200,
                ['Content-Type' => 'text/html'],
            ),
        ]);

        $result = app(ProductImageResolver::class)->preflightSource([
            'url' => 'https://93.184.216.34/product',
        ]);

        $this->assertFalse($result['blocked']);
        $this->assertTrue($result['active_recipe']);
        $this->assertSame(['https://93.184.216.34/images/full.webp'], $result['static_image_urls']);
    }

    public function test_preflight_returns_identity_from_the_actual_page_html(): void
    {
        Http::fake([
            'https://93.184.216.34/product' => Http::response(
                '<html><head><title>Razer Blade 14 RTX 5070 (RZ09-05306ES3-R3B1)</title>'
                .'<meta property="og:title" content="Razer Blade 14"></head>'
                .'<body><h1>Razer Blade 14 Gaming Laptop</h1>'
                .'<script type="application/ld+json">{"sku":"RZ09-05306ES3-R3B1"}</script></body></html>',
                200,
                ['Content-Type' => 'text/html'],
            ),
        ]);

        $result = app(ProductImageResolver::class)->preflightSource([
            'url' => 'https://93.184.216.34/product',
        ]);

        $this->assertSame('https://93.184.216.34/product', $result['final_url']);
        $this->assertStringContainsString('Razer Blade 14', $result['identity_evidence']);
        $this->assertStringContainsString('RZ09-05306ES3-R3B1', $result['identity_evidence']);
    }
}
