<?php

namespace Tests\Feature;

use App\Services\Products\BoundedProductResponseStream;
use App\Services\Products\PublicProductUrl;
use App\Services\Products\SafeProductHttp;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class SafeProductHttpTest extends TestCase
{
    public function test_public_ip_policy_rejects_internal_and_alternate_addresses(): void
    {
        foreach (['127.0.0.1', '10.0.0.1', '100.64.0.1', '169.254.169.254', '172.16.0.1', '192.168.0.1',
            '192.0.2.1', '198.18.0.1', '198.51.100.1', '203.0.113.1', '224.0.0.1', '::1', 'fc00::1', 'fe80::1',
            '::ffff:127.0.0.1', '2001:db8::1', '2002:7f00:1::1'] as $ip) {
            $this->assertFalse(PublicProductUrl::isPublicIp($ip), $ip);
        }
        foreach (['8.8.8.8', '93.184.216.34', '192.0.78.1', '2606:4700:4700::1111'] as $ip) {
            $this->assertTrue(PublicProductUrl::isPublicIp($ip), $ip);
        }
    }

    public function test_mixed_public_and_private_dns_is_refused_before_http(): void
    {
        Http::fake();
        $resolver = new class extends PublicProductUrl
        {
            protected function lookup(string $host): array
            {
                return ['8.8.8.8', '::1'];
            }
        };
        $client = new SafeProductHttp($resolver);
        $this->assertFalse($client->isPublicUrl('https://mixed.example/image'));
        try {
            $client->get('https://mixed.example/image', [], 1024);
            $this->fail('Private DNS answer must be blocked.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('non-public', $error->getMessage());
        }
        Http::assertNothingSent();
    }

    public function test_noncanonical_urls_and_credentials_are_rejected_without_dns(): void
    {
        $resolver = new class extends PublicProductUrl
        {
            protected function lookup(string $host): array
            {
                throw new \LogicException('DNS must not run.');
            }
        };
        foreach (['http://127.1/', 'http://2130706433/', 'http://0x7f000001/', 'http://shop.example./',
            'http://user:password@shop.example/', 'file:///private'] as $url) {
            try {
                $resolver->resolve($url);
                $this->fail('Invalid URL accepted: '.$url);
            } catch (RuntimeException $error) {
                $this->assertStringContainsString('non-public', $error->getMessage());
            }
        }
    }

    public function test_sink_rejects_a_chunk_before_writing_past_the_limit(): void
    {
        $stream = new BoundedProductResponseStream(10);
        $stream->write('123456');
        try {
            $stream->write('78901');
            $this->fail('Overflow should be refused.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('exceeds_max_bytes', $error->getMessage());
        }
        $this->assertSame('123456', (string) $stream);
        $stream->close();
    }

    public function test_real_curl_uses_the_pinned_address_and_preserves_host(): void
    {
        $this->withCanary(function (SafeProductHttp $client, string $origin): void {
            $response = $client->get($origin.'/small', [], 1024);
            $this->assertSame(parse_url($origin, PHP_URL_HOST).':'.parse_url($origin, PHP_URL_PORT), $response->json('host'));
            $this->assertSame('/small', $response->json('path'));
        });
    }

    public function test_real_curl_bounds_headers_chunked_data_and_decompressed_gzip(): void
    {
        $this->withCanary(function (SafeProductHttp $client, string $origin): void {
            foreach (['oversized-header', 'chunked', 'gzip', 'gzip-wire'] as $path) {
                try {
                    $client->get($origin.'/'.$path, [], 1024);
                    $this->fail('Oversized '.$path.' response was accepted.');
                } catch (RuntimeException $error) {
                    $this->assertStringContainsString('exceeds_max_bytes', $error->getMessage(), $path);
                }
            }
        });
    }

    public function test_real_curl_fetches_many_side_by_side_and_bounds_each_one_alone(): void
    {
        $this->withCanary(function (SafeProductHttp $client, string $origin): void {
            $started = microtime(true);
            $slow = $client->getMany(array_map(fn (int $n): array => ['url' => $origin.'/slow/'.$n, 'headers' => []], range(1, 4)), 1024);
            $this->assertLessThan(3.0, microtime(true) - $started, 'Four one-second responses must overlap.');
            foreach ($slow as $index => $response) {
                $this->assertSame('/slow/'.($index + 1), $response->json('path'));
            }

            // One oversized answer stops only its own transfer.
            $paths = ['small', 'oversized-header', 'chunked', 'gzip', 'gzip-wire'];
            $mixed = $client->getMany(array_combine($paths, array_map(fn (string $path): array => ['url' => $origin.'/'.$path, 'headers' => []], $paths)), 1024);
            $this->assertSame('/small', $mixed['small']->json('path'));
            foreach (array_slice($paths, 1) as $path) {
                $this->assertInstanceOf(RuntimeException::class, $mixed[$path], $path);
                $this->assertStringContainsString('exceeds_max_bytes', $mixed[$path]->getMessage(), $path);
            }
        });
    }

    private function withCanary(callable $check): void
    {
        $process = new Process(['node', base_path('tests/Fixtures/public-http-canary.mjs')]);
        $process->start();
        try {
            $process->waitUntil(fn (): bool => str_contains($process->getOutput(), "\n"));
            $port = (int) trim($process->getOutput());
            $this->assertGreaterThan(0, $port);
            Http::allowStrayRequests(['http://fixture.invalid:'.$port.'/*']);
            // Test-only resolver substitution. The real resolver above never
            // admits loopback; no environment/config bypass exists in production.
            $resolver = new class($port) extends PublicProductUrl
            {
                public function __construct(private readonly int $port) {}

                public function resolve(string $url): array
                {
                    return ['host' => 'fixture.invalid', 'port' => $this->port, 'addresses' => ['127.0.0.1']];
                }
            };
            $check(new SafeProductHttp($resolver), 'http://fixture.invalid:'.$port);
        } finally {
            $process->stop(1);
        }
    }
}
