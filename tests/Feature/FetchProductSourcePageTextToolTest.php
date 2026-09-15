<?php

namespace Tests\Feature;

use App\Ai\Tools\FetchProductSourcePageText;
use App\Models\AiRun;
use App\Models\ProductDraft;
use App\Models\ProductSourcePageEvidence;
use App\Models\TelegramUpdate;
use App\Services\Ai\ProductSearchCostBudget;
use App\Services\Products\BrowserProductGalleryExtractor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Tools\Request as ToolRequest;
use Mockery\MockInterface;
use Tests\TestCase;

class FetchProductSourcePageTextToolTest extends TestCase
{
    use RefreshDatabase;

    private function seedDraft(): ProductDraft
    {
        $updateId = TelegramUpdate::query()->create([
            'update_id' => random_int(1_000_000, 9_000_000),
            'telegram_user_id' => '12345',
            'chat_id' => '12345',
            'text' => 'test',
            'payload' => ['update_id' => 1],
            'status' => 'received',
        ])->id;
        $run = AiRun::query()->create([
            'telegram_update_id' => $updateId,
            'provider' => 'fake',
            'model' => 'fake',
            'status' => 'completed',
            'prompt' => 'test',
            'started_at' => now(),
            'completed_at' => now(),
        ]);

        return ProductDraft::query()->create([
            'telegram_update_id' => $updateId,
            'ai_run_id' => $run->id,
            'requested_by_telegram_user_id' => '12345',
            'title' => 'Test Product',
            'model' => 'Test Product',
            'description' => 'A test product description.',
            'specifications' => [],
            'sources' => [],
            'image_urls' => [],
        ]);
    }

    /**
     * lastProductPageUrl()/lastObservedControls()/lastClickOutcome()/
     * lastProductIdentity() are same-call side channels read unconditionally
     * on every browser path - every test mocking scout()/scoutAfterOpening()
     * needs them stubbed too, or Mockery's strict mock throws on the
     * unexpected call. Defaults describe "nothing extra observed, and the
     * page never left the requested host" - the neutral, most common case.
     */
    private function mockBrowser(): MockInterface
    {
        $browser = $this->mock(BrowserProductGalleryExtractor::class);
        $browser->shouldReceive('lastProductPageUrl')->andReturn(null)->byDefault();
        $browser->shouldReceive('lastObservedControls')->andReturn([])->byDefault();
        $browser->shouldReceive('lastClickOutcome')->andReturn(null)->byDefault();
        $browser->shouldReceive('lastProductIdentity')->andReturn(null)->byDefault();

        return $browser;
    }

    public function test_it_refuses_a_url_on_a_different_host_than_the_allowed_source(): void
    {
        Http::fake(['*' => Http::response('should never be requested', 200, ['Content-Type' => 'text/html'])]);

        $result = json_decode((string) (new FetchProductSourcePageText('shop.example'))->handle(new ToolRequest([
            'url' => 'https://attacker.example/steal-this',
        ])), true, flags: JSON_THROW_ON_ERROR);

        $this->assertFalse($result['fetched']);
        Http::assertNothingSent();
    }

    public function test_it_fetches_and_returns_specification_text_from_an_allowed_same_host_url(): void
    {
        // isPublicUrl() does a real DNS lookup for a hostname - an IP
        // literal is what the rest of this suite already uses to avoid
        // depending on any name actually resolving in the test environment.
        Http::fake(['https://93.184.216.34/specs' => Http::response(
            '<html><body><table><tr><td>RAM</td><td>32 GB</td></tr></table></body></html>',
            200,
            ['Content-Type' => 'text/html'],
        )]);

        $result = json_decode((string) (new FetchProductSourcePageText('93.184.216.34'))->handle(new ToolRequest([
            'url' => 'https://93.184.216.34/specs',
        ])), true, flags: JSON_THROW_ON_ERROR);

        $this->assertTrue($result['fetched']);
        $this->assertStringContainsString('32 GB', $result['specification_text']);
    }

    public function test_a_subdomain_is_not_treated_as_the_same_host(): void
    {
        // Same-host, not same-site: a real safety boundary, not a loose one.
        Http::fake(['*' => Http::response('should never be requested', 200, ['Content-Type' => 'text/html'])]);

        $result = json_decode((string) (new FetchProductSourcePageText('shop.example'))->handle(new ToolRequest([
            'url' => 'https://evil.shop.example/specs',
        ])), true, flags: JSON_THROW_ON_ERROR);

        $this->assertFalse($result['fetched']);
        Http::assertNothingSent();
    }

    public function test_a_page_that_cannot_be_read_returns_fetched_false_not_an_error(): void
    {
        Http::fake(['https://shop.example/gone' => Http::response('', 404)]);

        $result = json_decode((string) (new FetchProductSourcePageText('shop.example'))->handle(new ToolRequest([
            'url' => 'https://shop.example/gone',
        ])), true, flags: JSON_THROW_ON_ERROR);

        $this->assertFalse($result['fetched']);
        $this->assertSame('', $result['specification_text']);
    }

    public function test_it_falls_back_to_a_browser_session_when_plain_http_finds_nothing_usable(): void
    {
        // An empty body over plain HTTP is not proof the page has nothing on
        // it - it is the normal signature of a Playwright-only source. The
        // tool must not leave the agent with permanent emptiness just
        // because the first, cheaper attempt saw an empty shell.
        Http::fake(['https://93.184.216.40/specs' => Http::response(
            '<html><body></body></html>',
            200,
            ['Content-Type' => 'text/html'],
        )]);
        $browser = $this->mockBrowser();
        // scout(string $url, ?callable $debug = null, ?int $telegramUpdateId = null, array $context = [], ?string $confineNavigationToHost = null) -
        // the caller passes telegramUpdateId and confineNavigationToHost by
        // name, so PHP resolves the call up through the highest position
        // actually named, filling every skipped position with its own
        // default (the unfilled $context default included).
        $browser->shouldReceive('scout')->once()
            ->with('https://93.184.216.40/specs', null, null, [], '93.184.216.40')
            ->andReturn([]);
        $browser->shouldReceive('lastSpecificationText')->andReturn('RAM: 64 GB (captured by browser)');

        $result = json_decode((string) (new FetchProductSourcePageText('93.184.216.40'))->handle(new ToolRequest([
            'url' => 'https://93.184.216.40/specs',
        ])), true, flags: JSON_THROW_ON_ERROR);

        $this->assertTrue($result['fetched']);
        $this->assertStringContainsString('64 GB', $result['specification_text']);
    }

    public function test_open_selector_clicks_before_reading_and_skips_plain_http_entirely(): void
    {
        // The specifications are behind an in-page tab, not a different URL
        // - open_selector must go straight to a real browser session and
        // click it; a plain fetch can never reach content behind a click.
        Http::fake(['*' => Http::response('should never be requested', 200, ['Content-Type' => 'text/html'])]);
        $browser = $this->mockBrowser();
        $browser->shouldReceive('scoutAfterOpening')->once()
            ->with('https://93.184.216.42/product', '.tab-characteristics', null, null, [], '93.184.216.42')
            ->andReturn([]);
        $browser->shouldReceive('lastSpecificationText')->andReturn('GPU: RTX 4060 (revealed by tab)');

        $result = json_decode((string) (new FetchProductSourcePageText('93.184.216.42'))->handle(new ToolRequest([
            'url' => 'https://93.184.216.42/product',
            'open_selector' => '.tab-characteristics',
        ])), true, flags: JSON_THROW_ON_ERROR);

        $this->assertTrue($result['fetched']);
        $this->assertStringContainsString('RTX 4060', $result['specification_text']);
        Http::assertNothingSent();
    }

    public function test_force_browser_skips_a_non_empty_plain_text_result_the_agent_judges_too_thin(): void
    {
        // A plain fetch returning some text (e.g. only a title) must not be
        // silently trusted as "sufficient" - only the agent asking is
        // positioned to judge that, via force_browser.
        Http::fake(['https://93.184.216.43/product' => Http::response(
            '<html><body><h1>Just A Title</h1></body></html>',
            200,
            ['Content-Type' => 'text/html'],
        )]);
        $browser = $this->mockBrowser();
        $browser->shouldReceive('scout')->once()
            ->with('https://93.184.216.43/product', null, null, [], '93.184.216.43')
            ->andReturn([]);
        $browser->shouldReceive('lastSpecificationText')->andReturn('RAM: 32 GB (full page via browser)');

        $result = json_decode((string) (new FetchProductSourcePageText('93.184.216.43'))->handle(new ToolRequest([
            'url' => 'https://93.184.216.43/product',
            'force_browser' => true,
        ])), true, flags: JSON_THROW_ON_ERROR);

        $this->assertTrue($result['fetched']);
        $this->assertStringContainsString('32 GB', $result['specification_text']);
        Http::assertNothingSent();
    }

    public function test_the_telegram_update_id_is_threaded_into_the_browser_call(): void
    {
        // The browser's deadline must come from the same shared search-time
        // budget as everything else in this request, not an unrelated
        // default - passing telegramUpdateId through is how that happens.
        Http::fake(['https://93.184.216.44/product' => Http::response(
            '<html><body></body></html>',
            200,
            ['Content-Type' => 'text/html'],
        )]);
        $browser = $this->mockBrowser();
        $browser->shouldReceive('scout')->once()
            ->with('https://93.184.216.44/product', null, 777, [], '93.184.216.44')
            ->andReturn([]);
        $browser->shouldReceive('lastSpecificationText')->andReturn('');

        (new FetchProductSourcePageText('93.184.216.44', 777))->handle(new ToolRequest([
            'url' => 'https://93.184.216.44/product',
        ]));
    }

    public function test_it_refuses_to_fetch_once_the_shared_search_budget_is_exhausted(): void
    {
        // The underlying fetch can launch a real browser session - not a
        // free per-call tool, so it must answer to the same shared search
        // budget as the reconciliation call that spawned it.
        Http::fake(['*' => Http::response('should never be requested', 200, ['Content-Type' => 'text/html'])]);
        $this->mock(ProductSearchCostBudget::class)->shouldReceive('exceeded')->andReturn(true);

        $result = json_decode((string) (new FetchProductSourcePageText('93.184.216.41', 999))->handle(new ToolRequest([
            'url' => 'https://93.184.216.41/specs',
        ])), true, flags: JSON_THROW_ON_ERROR);

        $this->assertFalse($result['fetched']);
        $this->assertSame('search budget exhausted', $result['reason']);
        Http::assertNothingSent();
    }

    public function test_a_plain_http_redirect_to_a_different_host_is_refused(): void
    {
        // Same domain was validated on the REQUESTED url only - a redirect
        // (every hop, not just the first) can still land somewhere else, and
        // that page's content must never be handed over as if it were this
        // one's.
        Http::fake([
            'https://93.184.216.50/product' => Http::response('', 302, ['Location' => 'https://93.184.216.51/product']),
            'https://93.184.216.51/product' => Http::response(
                '<html><body><table><tr><td>RAM</td><td>999 GB</td></tr></table></body></html>',
                200,
                ['Content-Type' => 'text/html'],
            ),
        ]);

        $result = json_decode((string) (new FetchProductSourcePageText('93.184.216.50'))->handle(new ToolRequest([
            'url' => 'https://93.184.216.50/product',
        ])), true, flags: JSON_THROW_ON_ERROR);

        $this->assertFalse($result['fetched']);
        $this->assertStringContainsString('93.184.216.51', $result['reason']);
        $this->assertArrayNotHasKey('specification_text', $result);
    }

    public function test_a_redirect_that_bounces_off_host_and_back_is_still_refused(): void
    {
        // Regression for the exact gap found: comparing only the requested
        // host against the FINAL host would accept allowed -> other ->
        // back-to-allowed, three real requests to a host that was never
        // approved, none of them visible in a start-vs-end comparison. Each
        // hop must be checked before it is made, not just the outcome.
        Http::fake([
            'https://93.184.216.56/product' => Http::response('', 302, ['Location' => 'https://93.184.216.57/bounce']),
            'https://93.184.216.57/bounce' => Http::response('', 302, ['Location' => 'https://93.184.216.56/product']),
        ]);

        $result = json_decode((string) (new FetchProductSourcePageText('93.184.216.56'))->handle(new ToolRequest([
            'url' => 'https://93.184.216.56/product',
        ])), true, flags: JSON_THROW_ON_ERROR);

        $this->assertFalse($result['fetched']);
        $this->assertStringContainsString('93.184.216.57', $result['reason']);
        // The second hop (back to the allowed host) must never have been
        // requested - the drift was refused before it was reached.
        Http::assertSentCount(1);
    }

    public function test_a_click_that_navigates_off_host_is_refused_even_though_content_came_back(): void
    {
        // The same safety property for the browser path: a click can
        // trigger real navigation, and landing off-host is exactly as
        // untrustworthy there as an HTTP redirect off-host.
        Http::fake(['*' => Http::response('should never be requested', 200, ['Content-Type' => 'text/html'])]);
        $browser = $this->mockBrowser();
        $browser->shouldReceive('scoutAfterOpening')->once()->andReturn([]);
        $browser->shouldReceive('lastProductPageUrl')->andReturn('https://93.184.216.53/unrelated-product');
        $browser->shouldReceive('lastSpecificationText')->andReturn('This reads like a completely different product.');

        $result = json_decode((string) (new FetchProductSourcePageText('93.184.216.52'))->handle(new ToolRequest([
            'url' => 'https://93.184.216.52/product',
            'open_selector' => '.tab',
        ])), true, flags: JSON_THROW_ON_ERROR);

        $this->assertFalse($result['fetched']);
        $this->assertStringContainsString('93.184.216.53', $result['reason']);
    }

    public function test_a_successful_browser_fetch_persists_evidence_for_the_draft(): void
    {
        // Point 2 of the follow-up audit: what the tool finds must be saved
        // against this draft, not rediscovered on a later attempt.
        Http::fake(['*' => Http::response('should never be requested', 200, ['Content-Type' => 'text/html'])]);
        $draft = $this->seedDraft();
        $browser = $this->mockBrowser();
        $browser->shouldReceive('scoutAfterOpening')->once()->andReturn([]);
        $browser->shouldReceive('lastSpecificationText')->andReturn('GPU: RTX 4070');
        $browser->shouldReceive('lastObservedControls')
            ->andReturn([['selector' => '#specs', 'text' => 'Характеристики']]);
        $browser->shouldReceive('lastClickOutcome')->andReturn([
            'selector' => '#specs', 'clicked' => true, 'changed' => true,
            'selector_missing' => false, 'navigated_away' => false, 'navigated_away_reason' => null,
        ]);

        $result = json_decode((string) (new FetchProductSourcePageText('93.184.216.54', null, $draft->id))->handle(
            new ToolRequest(['url' => 'https://93.184.216.54/product', 'open_selector' => '#specs']),
        ), true, flags: JSON_THROW_ON_ERROR);

        $this->assertTrue($result['fetched']);
        $this->assertSame([['selector' => '#specs', 'text' => 'Характеристики']], $result['observed_controls']);
        $this->assertTrue($result['click_outcome']['clicked']);

        $evidence = ProductSourcePageEvidence::query()->where('product_draft_id', $draft->id)->first();
        $this->assertNotNull($evidence);
        $this->assertSame('GPU: RTX 4070', $evidence->specification_text);
        $this->assertSame('#specs', $evidence->open_selector);
        $this->assertSame('browser', $evidence->captured_via);
        $this->assertTrue($evidence->click_outcome['clicked']);
    }

    public function test_two_different_tabs_opened_on_the_same_url_are_both_kept_not_overwritten(): void
    {
        // Regression for the exact gap found: the upsert key was the url
        // alone, so opening "Характеристики" then "Отзывы" on the same
        // address made the second overwrite the first instead of the draft
        // keeping both observations.
        Http::fake(['*' => Http::response('should never be requested', 200, ['Content-Type' => 'text/html'])]);
        $draft = $this->seedDraft();
        $browser = $this->mockBrowser();
        $browser->shouldReceive('scoutAfterOpening')->twice()->andReturn([]);
        $browser->shouldReceive('lastSpecificationText')
            ->andReturn('GPU: RTX 4070', 'Отзывы покупателей: 4.8 из 5.');

        $tool = new FetchProductSourcePageText('93.184.216.58', null, $draft->id);
        $tool->handle(new ToolRequest(['url' => 'https://93.184.216.58/product', 'open_selector' => '#tab-specs']));
        $tool->handle(new ToolRequest(['url' => 'https://93.184.216.58/product', 'open_selector' => '#tab-reviews']));

        $rows = ProductSourcePageEvidence::query()->where('product_draft_id', $draft->id)->get();
        $this->assertCount(2, $rows);
        $bySelector = $rows->keyBy('open_selector');
        $this->assertSame('GPU: RTX 4070', $bySelector['#tab-specs']->specification_text);
        $this->assertSame('Отзывы покупателей: 4.8 из 5.', $bySelector['#tab-reviews']->specification_text);
    }

    public function test_identity_evidence_is_persisted_alongside_the_text(): void
    {
        // Regression: identity_evidence was returned to the agent in the
        // same response but never saved, so a later attempt had the text
        // back but no way to tell whether it ever belonged to this product.
        Http::fake(['https://93.184.216.59/product' => Http::response(
            '<html><head><title>Test Laptop 15</title></head><body>'
                .'<table><tr><td>RAM</td><td>32 GB</td></tr></table></body></html>',
            200,
            ['Content-Type' => 'text/html'],
        )]);
        $draft = $this->seedDraft();

        (new FetchProductSourcePageText('93.184.216.59', null, $draft->id))->handle(new ToolRequest([
            'url' => 'https://93.184.216.59/product',
        ]));

        $evidence = ProductSourcePageEvidence::query()->where('product_draft_id', $draft->id)->first();
        $this->assertNotNull($evidence);
        $this->assertStringContainsString('Test Laptop 15', (string) $evidence->identity_evidence);
    }

    public function test_an_unreadable_page_does_not_persist_evidence(): void
    {
        Http::fake(['https://93.184.216.55/gone' => Http::response('', 404)]);
        $draft = $this->seedDraft();

        (new FetchProductSourcePageText('93.184.216.55', null, $draft->id))->handle(new ToolRequest([
            'url' => 'https://93.184.216.55/gone',
        ]));

        $this->assertSame(0, ProductSourcePageEvidence::query()->where('product_draft_id', $draft->id)->count());
    }
}
