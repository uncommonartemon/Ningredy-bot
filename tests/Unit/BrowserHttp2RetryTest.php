<?php

namespace Tests\Unit;

use App\Services\Products\BrowserProductGalleryExtractor;
use ReflectionMethod;
use Tests\TestCase;

/**
 * A server that negotiates HTTP/2 and then breaks the stream leaves Chromium
 * with no page at all. Live on hp.com: the HTTP fetch timed out, the browser
 * failed on the protocol, and a whole manufacturer was written off three
 * seconds into training having never loaded a page - reported to the operator
 * as "no technically suitable image", a verdict about photographs nobody saw.
 *
 * The remedy is cheap and general - the same request over HTTP/1.1 - so this
 * failure has to be told apart from every other navigation error, which has no
 * such remedy and should not cost a second run.
 */
class BrowserHttp2RetryTest extends TestCase
{
    public function test_a_broken_http2_stream_is_recognised_wherever_it_is_reported(): void
    {
        // The browser reports it in its JSON result when the script caught the
        // navigation error, and on stderr when the process died with it.
        $this->assertTrue($this->looksLikeHttp2Failure(
            ['error' => 'page.goto: net::ERR_HTTP2_PROTOCOL_ERROR at https://www.hp.com/us-en/shop/pdp/...'],
            '',
        ));
        $this->assertTrue($this->looksLikeHttp2Failure(null, 'Error: net::ERR_SPDY_PROTOCOL_ERROR at https://shop.example/p/1'));
    }

    public function test_other_navigation_failures_are_not_retried(): void
    {
        // Each of these is a fact about the site or the run, and repeating the
        // whole extraction would buy the same answer at twice the cost.
        foreach ([
            'page.goto: net::ERR_NAME_NOT_RESOLVED',
            'page.goto: Timeout 20000ms exceeded',
            'net::ERR_CERT_AUTHORITY_INVALID',
            'Playwright returned invalid JSON.',
        ] as $error) {
            $this->assertFalse(
                $this->looksLikeHttp2Failure(['error' => $error], ''),
                $error.' must not cost a second browser run.',
            );
        }

        $this->assertFalse($this->looksLikeHttp2Failure(null, ''));
        $this->assertFalse($this->looksLikeHttp2Failure([], ''));
    }

    /** @param array<string, mixed>|null $result */
    private function looksLikeHttp2Failure(?array $result, string $stderr): bool
    {
        $method = new ReflectionMethod(BrowserProductGalleryExtractor::class, 'looksLikeHttp2Failure');

        return $method->invoke(app(BrowserProductGalleryExtractor::class), $result, $stderr);
    }
}
