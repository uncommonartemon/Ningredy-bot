<?php

namespace Tests\Feature;

use App\Services\Ai\AiSettings;
use App\Services\Products\BrowserProductGalleryExtractor;
use App\Services\Products\HostReputation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Live on 2026-09-06: a host refused the first visit, and the search went on to
 * open three more of its pages and train on them - eight visits in three
 * minutes to a shop that returned nothing at all. Each one cost thirty seconds
 * and made the block it was walking into a little more permanent.
 */
class RefusingHostIsNotVisitedAgainTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'product-images.browser_fallback.enabled' => true,
            'product-images.browser_fallback.refusal_threshold' => 2,
        ]);
    }

    public function test_the_browser_is_not_started_for_a_host_that_has_refused_twice(): void
    {
        $reputation = app(HostReputation::class);
        $reputation->noteRefusal('https://shop.example/p/1', HostReputation::REFUSAL_SILENCE);
        $reputation->noteRefusal('https://shop.example/p/2', HostReputation::REFUSAL_SILENCE);

        $messages = [];
        $started = microtime(true);
        $result = app(BrowserProductGalleryExtractor::class)->scout(
            'https://shop.example/p/3',
            function (string $level, string $message) use (&$messages): void {
                $messages[] = $message;
            },
        );

        $this->assertSame('host_refusing', $result['failure_kind'] ?? null);
        $this->assertSame([], $result['images']);
        // No browser, and therefore no visit: the whole point is that the shop
        // never hears from us a third time.
        $this->assertLessThan(5.0, microtime(true) - $started);
        $this->assertNotEmpty(array_filter(
            $messages,
            fn (string $message): bool => str_contains($message, 'отказал 2 раз'),
        ));
    }

    public function test_a_host_that_has_refused_only_once_is_still_tried(): void
    {
        app(HostReputation::class)->noteRefusal('https://shop.example/p/1', HostReputation::REFUSAL_SILENCE);

        $this->app->instance(AiSettings::class, $settings = \Mockery::mock(AiSettings::class)->makePartial());
        $settings->shouldReceive('galleryBrowserMode')->andReturn(AiSettings::GALLERY_BROWSER_OFF);

        // Browser mode off is the cheapest way to prove the refusal guard let
        // the call through: it is the check immediately after it.
        $result = app(BrowserProductGalleryExtractor::class)->scout('https://shop.example/p/2');

        $this->assertNotSame('host_refusing', $result['failure_kind'] ?? null);
    }
}
