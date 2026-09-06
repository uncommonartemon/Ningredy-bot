<?php

namespace Tests\Unit;

use App\Services\Products\BrowserProductGalleryExtractor;
use App\Services\Products\HostReputation;
use Illuminate\Support\Facades\Cache;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Politeness where it is owed, and nowhere else.
 *
 * One training makes five to eight visits to the same host, so spacing every
 * shop at twenty-five seconds would spend minutes waiting on domains that
 * never objected to anything. A host that has actually pushed back - a robot
 * check, a 403, or a connection it accepts and never answers - is a different
 * matter: there the wait buys something.
 */
class ChallengedHostSpacingTest extends TestCase
{
    public function test_a_robot_check_is_remembered_against_that_host_alone(): void
    {
        $this->remember('https://shop.example/p/1', ['access_gate' => true, 'access_gate_reason' => 'captcha']);

        $reputation = app(HostReputation::class);

        $this->assertTrue($reputation->isChallenged('https://shop.example/p/1'));
        $this->assertSame('captcha', $reputation->refusal('https://shop.example/p/1')['reason']);
        $this->assertFalse($reputation->isChallenged('https://other.example/p/1'));
    }

    public function test_a_page_that_loaded_normally_clears_the_record(): void
    {
        $this->remember('https://shop.example/p/1', ['access_gate' => true, 'access_gate_reason' => 'captcha']);
        $this->remember('https://shop.example/p/2', ['access_gate' => false]);

        $this->assertFalse(app(HostReputation::class)->isChallenged('https://shop.example/p/3'));
    }

    public function test_an_ordinary_host_keeps_the_ordinary_pace(): void
    {
        config(['product-images.browser_fallback.host_visit_spacing_seconds' => 1]);
        Cache::put('gallery-browser-last-visit:calm.example', microtime(true));

        $waited = $this->timePause('https://calm.example/p/1');

        // One second plus up to four of jitter; the point is that it is not the
        // twenty-five a host that pushed back would get.
        $this->assertLessThan(9.0, $waited);
    }

    public function test_a_host_that_pushed_back_waits_longer_than_an_ordinary_one(): void
    {
        config([
            'product-images.browser_fallback.host_visit_spacing_seconds' => 0,
            'product-images.browser_fallback.challenged_host_spacing_seconds' => 3,
        ]);
        app(HostReputation::class)->noteRefusal('https://cross.example/p/1', HostReputation::REFUSAL_SILENCE);
        Cache::put('gallery-browser-last-visit:cross.example', microtime(true));

        $waited = $this->timePause('https://cross.example/p/2');

        $this->assertGreaterThanOrEqual(2.5, $waited);
    }

    /** @param array<string, mixed> $scout */
    private function remember(string $url, array $scout): void
    {
        $method = new ReflectionMethod(BrowserProductGalleryExtractor::class, 'rememberAccessChallenge');
        $method->invoke(app(BrowserProductGalleryExtractor::class), $url, $scout);
    }

    private function timePause(string $url): float
    {
        $method = new ReflectionMethod(BrowserProductGalleryExtractor::class, 'pauseBetweenVisits');
        $started = microtime(true);
        $method->invoke(app(BrowserProductGalleryExtractor::class), $url);

        return microtime(true) - $started;
    }
}
