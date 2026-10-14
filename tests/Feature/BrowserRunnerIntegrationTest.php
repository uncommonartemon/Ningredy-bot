<?php

namespace Tests\Feature;

use App\Services\Ai\ProductSearchTimeBudget;
use App\Services\Playwright\BrowserRunner;
use App\Services\Playwright\BrowserRunRequest;
use App\Services\Playwright\BrowserRunResult;
use App\Services\Products\BrowserProductGalleryExtractor;
use App\Services\Products\HostReputation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class BrowserRunnerIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'product-images.browser_fallback.enabled' => true,
            'product-images.browser_fallback.host_visit_spacing_seconds' => 0,
            'product-images.browser_fallback.challenged_host_spacing_seconds' => 0,
        ]);
    }

    public function test_assembly_window_and_click_context_reach_runner_and_observation_is_imported(): void
    {
        $budget = Mockery::mock(ProductSearchTimeBudget::class);
        $budget->shouldReceive('assemblyTimeoutFor')->once()->with(91, Mockery::type('int'))->andReturn(60);
        $budget->shouldNotReceive('timeoutFor');
        $this->app->instance(ProductSearchTimeBudget::class, $budget);
        $runner = Mockery::mock(BrowserRunner::class);
        $runner->shouldReceive('run')->once()->andReturnUsing(function (BrowserRunRequest $request): BrowserRunResult {
            $this->assertSame(60, $request->timeoutSeconds);
            $this->assertSame(['pre_click_selectors' => ['#specs']], $request->recipe);
            $this->assertTrue($request->scoutOnly);
            $this->assertSame('shop.example', $request->confineNavigationToHost);
            $this->assertSame('#viewer', $request->context['observation_focus_selector']);

            return new BrowserRunResult([
                'images' => [],
                'product_page_url' => 'https://shop.example/item',
                'product_identity' => ['sku' => 'A123'],
                'scout' => [
                    'specification_text' => 'RAM 16GB',
                    'observed_controls' => [['selector' => '#specs', 'text' => 'Specifications']],
                ],
                'action_trace' => [['phase' => 'pre_click', 'selector' => '#specs', 'clicked' => true, 'changed' => true]],
            ], hasObservation: true);
        });
        $this->app->instance(BrowserRunner::class, $runner);
        $browser = app(BrowserProductGalleryExtractor::class);
        $browser->scoutAfterOpening('https://shop.example/item', '#specs', telegramUpdateId: 91,
            context: ['card_assembly' => true, 'observation_focus_selector' => '#viewer'], confineNavigationToHost: 'shop.example');
        $this->assertSame('RAM 16GB', $browser->lastSpecificationText());
        $this->assertSame(['sku' => 'A123'], $browser->lastProductIdentity());
        $this->assertSame('https://shop.example/item', $browser->lastProductPageUrl());
        $this->assertTrue($browser->lastClickOutcome()['clicked']);
    }

    public function test_failed_invocation_cannot_reuse_observation_from_previous_page(): void
    {
        $runner = Mockery::mock(BrowserRunner::class);
        $runner->shouldReceive('run')->twice()->andReturn(
            new BrowserRunResult(['images' => [], 'product_page_url' => 'https://shop.example/a',
                'product_identity' => ['sku' => 'A123'],
                'scout' => ['specification_text' => 'RAM 16GB', 'observed_controls' => [['selector' => '#tab', 'text' => 'Details']]]],
                hasObservation: true),
            new BrowserRunResult(['images' => [], 'error' => 'crashed', 'failure_kind' => 'browser_process']),
        );
        $this->app->instance(BrowserRunner::class, $runner);
        $browser = app(BrowserProductGalleryExtractor::class);
        $browser->scout('https://shop.example/a');
        $this->assertSame('RAM 16GB', $browser->lastSpecificationText());
        $browser->scout('https://shop.example/b');
        $this->assertNull($browser->lastSpecificationText());
        $this->assertNull($browser->lastProductIdentity());
        $this->assertNull($browser->lastProductPageUrl());
        $this->assertSame([], $browser->lastObservedControls());
        $this->assertNull($browser->lastClickOutcome());
    }

    public function test_http2_failure_retries_once_and_reuses_protocol_memory(): void
    {
        $flags = [];
        $runner = Mockery::mock(BrowserRunner::class);
        $runner->shouldReceive('run')->times(3)->andReturnUsing(function (BrowserRunRequest $request) use (&$flags): BrowserRunResult {
            $flags[] = $request->withoutHttp2;

            return count($flags) === 1
                ? new BrowserRunResult(['images' => [], 'failure_kind' => 'browser_process'], 'net::ERR_HTTP2_PROTOCOL_ERROR')
                : new BrowserRunResult(['images' => [], 'scout' => []], hasObservation: true);
        });
        $this->app->instance(BrowserRunner::class, $runner);
        $browser = app(BrowserProductGalleryExtractor::class);
        $browser->scout('https://protocol.example/a');
        $browser->scout('https://protocol.example/b');
        $this->assertSame([false, true, true], $flags);
    }

    public function test_process_timeout_does_not_become_a_host_refusal(): void
    {
        $runner = Mockery::mock(BrowserRunner::class);
        $runner->shouldReceive('run')->twice()->andReturn(new BrowserRunResult([
            'images' => [], 'failure_kind' => 'browser_timeout', 'error' => 'page.goto timeout during process shutdown',
        ]));
        $this->app->instance(BrowserRunner::class, $runner);
        $browser = app(BrowserProductGalleryExtractor::class);
        foreach ([1, 2] as $attempt) {
            $this->assertSame('browser_timeout', $browser->scout('https://timeout.example/item')['failure_kind']);
        }
        $this->assertNull(app(HostReputation::class)->refusal('https://timeout.example/item'));
    }
}
