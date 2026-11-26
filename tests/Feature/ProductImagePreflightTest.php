<?php

namespace Tests\Feature;

use App\Models\ProductGalleryRecipe;
use App\Models\ProductSourceAttempt;
use App\Models\TelegramUpdate;
use App\Services\Playwright\BrowserRunner;
use App\Services\Playwright\BrowserRunResult;
use App\Services\Products\BrowserProductGalleryExtractor;
use App\Services\Products\HostReputation;
use App\Services\Products\ProductImageResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery;
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

    public function test_preflight_escalates_a_connection_timeout_to_a_browser_probe_too(): void
    {
        // Real case: cURL error 28 (connect/read timeout, 0 bytes received)
        // against hp.com's own product page. A timeout has no HTTP status
        // at all - it must not fall through the 401/403/429 status check
        // and quietly get browser_probe_required=false, or a slow-walking/
        // black-holing anti-bot response (as promising a Playwright
        // candidate as an explicit 403) is indistinguishable from a
        // genuinely irrelevant source.
        Http::fake([
            'https://93.184.216.34/product' => Http::failedConnection('cURL error 28: Operation timed out'),
        ]);

        $result = app(ProductImageResolver::class)->preflightSource([
            'url' => 'https://93.184.216.34/product',
        ]);

        $this->assertFalse($result['blocked']);
        $this->assertFalse($result['unavailable']);
        $this->assertTrue($result['browser_probe_required']);
        $this->assertSame('http_error', $result['reason']);
    }

    public function test_a_host_that_gated_the_browser_is_skipped_by_the_next_search_until_it_serves_a_page(): void
    {
        // Live report 2026-11-27 (#705): a server-memory reseller refused the quick check with
        // 403, the photo check ran twice on its page and the browser was served a gate
        // ("Playwright обнаружил защитную страницу: http_403"). A refusal was remembered for
        // twenty minutes, so every later request paid all of it again. The address keeps the
        // reseller's path on a literal address: the test resolves no name.
        config(['product-images.browser_fallback.enabled' => true, 'product-images.browser_fallback.host_visit_spacing_seconds' => 0,
            'product-images.browser_fallback.challenged_host_spacing_seconds' => 0]);
        $url = 'https://93.184.216.34/item/1.1v-16gb-1rx8-5600-cwm-ddr5-m321r2ga3eb0/samdrv_m321r2ga3eb0cwm.html';
        $runner = Mockery::mock(BrowserRunner::class);
        $runner->shouldReceive('run')->once()->andReturn(new BrowserRunResult([
            'images' => [], 'scout' => ['access_gate' => true, 'access_gate_reason' => 'http_403', 'title' => 'Just a moment...'],
        ], hasObservation: true));
        $this->app->instance(BrowserRunner::class, $runner);
        app(BrowserProductGalleryExtractor::class)->scout($url);
        $reputation = app(HostReputation::class);
        $this->assertSame(['count' => 1, 'reason' => 'http_403'], array_intersect_key($reputation->browserBlock($url), ['count' => 0, 'reason' => '']));

        // The next request, a day later: the short refusal is gone, the browser block is not.
        $this->travel(26)->hours();
        $this->assertNull($reputation->refusal($url));
        $update = TelegramUpdate::query()->create(['update_id' => 970706, 'telegram_user_id' => '1', 'chat_id' => '1', 'payload' => [],
            'status' => 'processing', 'text' => 'M321R2GA3EBO-CWMXJ']);
        $serves = false;
        Http::fake([$url => function () use (&$serves) {
            return $serves
                ? Http::response('<html><head><title>Samsung M321R2GA3EB0-CWM 16GB DDR5-5600 RDIMM</title></head><body><h1>Samsung M321R2GA3EB0-CWM</h1></body></html>', 200, ['Content-Type' => 'text/html'])
                : Http::response('Forbidden', 403);
        }]);

        $refused = app(ProductImageResolver::class)->preflightSource(['url' => $url], $update->id);

        $this->assertTrue($refused['blocked']);
        $this->assertFalse($refused['browser_probe_required']);
        $this->assertSame('remembered_browser_block', $refused['reason']);
        $row = ProductSourceAttempt::query()->where('telegram_update_id', $update->id)->where('action', 'check_source_access')->sole();
        $this->assertSame('remembered_browser_block', $row->decision);
        $this->assertStringContainsString('закрыл страницу от браузера (http_403, раз: 1', (string) $row->message);
        $this->assertSame('http_403', $row->output['quick_check']);

        // The first page the host serves is the first success: the memory goes.
        $serves = true;
        $served = app(ProductImageResolver::class)->preflightSource(['url' => $url], $update->id);

        $this->assertFalse($served['blocked']);
        $this->assertStringContainsString('M321R2GA3EB0-CWM', $served['identity_evidence']);
        $this->assertNull($reputation->browserBlock($url));
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

    public function test_recipe_on_another_path_is_only_a_known_domain_signal(): void
    {
        ProductGalleryRecipe::query()->create([
            'domain' => '93.184.216.34',
            'path_pattern' => '/notebooks/*',
            'status' => 'active',
            'recipe' => [],
        ]);
        Http::fake([
            'https://93.184.216.34/components/case-1312' => Http::response(
                '<html><body><h1>PC case</h1></body></html>',
                200,
                ['Content-Type' => 'text/html'],
            ),
        ]);

        $result = app(ProductImageResolver::class)->preflightSource([
            'url' => 'https://93.184.216.34/components/case-1312',
        ]);

        $this->assertFalse($result['active_recipe']);
        $this->assertTrue($result['known_recipe_domain']);
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
