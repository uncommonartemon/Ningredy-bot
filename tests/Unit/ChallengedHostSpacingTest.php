<?php

namespace Tests\Unit;

use App\Services\Products\BrowserProductGalleryExtractor;
use Illuminate\Support\Facades\Cache;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Politeness where it is owed, and nowhere else.
 *
 * One training makes five to eight visits to the same host, so spacing every
 * shop at twenty-five seconds would spend minutes waiting on domains that
 * never objected to anything. A host that has actually shown a robot check is
 * a different matter - there the wait buys something.
 */
class ChallengedHostSpacingTest extends TestCase
{
    public function test_a_robot_check_is_remembered_against_that_host_alone(): void
    {
        $this->remember('https://shop.example/p/1', ['access_gate' => true, 'access_gate_reason' => 'captcha']);

        $this->assertSame('captcha', Cache::get('gallery-browser-challenged:shop.example'));
        $this->assertNull(Cache::get('gallery-browser-challenged:other.example'));
    }

    public function test_a_page_that_loaded_normally_is_not_remembered(): void
    {
        $this->remember('https://shop.example/p/1', ['access_gate' => false]);
        $this->remember('https://shop.example/p/2', []);

        $this->assertNull(Cache::get('gallery-browser-challenged:shop.example'));
    }

    public function test_an_ordinary_host_keeps_the_ordinary_pace(): void
    {
        config(['product-images.browser_fallback.host_visit_spacing_seconds' => 4]);
        Cache::put('gallery-browser-last-visit:calm.example', microtime(true));

        $waited = $this->timePause('https://calm.example/p/1');

        // Four seconds plus up to four of jitter; the point is that it is not
        // the twenty-five a challenged host would get.
        $this->assertLessThan(9.0, $waited);
    }

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
