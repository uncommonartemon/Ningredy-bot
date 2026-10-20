<?php

namespace App\Services\Products;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Throwable;

class SafeProductHttp
{
    public function __construct(private readonly PublicProductUrl $urls) {}

    public function isPublicUrl(string $url): bool
    {
        try {
            $this->urls->resolve($url);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /** One hop only. The caller owns redirect policy and checks every next URL. */
    public function get(string $url, array $headers, int $maximumBytes, ?int $timeout = null): Response
    {
        if (! extension_loaded('curl')) {
            throw new RuntimeException('Safe product downloads require cURL DNS pinning.');
        }
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $target = $this->urls->resolve($url);
            $addresses = array_map(fn (string $ip): string => str_contains($ip, ':') ? '['.$ip.']' : $ip, $target['addresses']);
            $sink = new BoundedProductResponseStream($maximumBytes);
            try {
                $response = Http::withHeaders($headers)->withoutRedirecting()
                    ->withOptions([
                        // An ambient HTTP proxy would resolve independently, defeating
                        // CURLOPT_RESOLVE. These untrusted-source requests go direct.
                        'proxy' => '', 'sink' => $sink,
                        'curl' => [CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*']
                            + (! filter_var($target['host'], FILTER_VALIDATE_IP)
                                ? [CURLOPT_RESOLVE => [$target['host'].':'.$target['port'].':'.implode(',', $addresses)]] : []),
                        'on_headers' => function (ResponseInterface $response) use ($maximumBytes): void {
                            $length = $response->getHeaderLine('Content-Length');
                            if (ctype_digit($length) && (float) $length > $maximumBytes) {
                                throw new RuntimeException('Product source response exceeds_max_bytes.');
                            }
                        },
                        'progress' => function (int $downloadSize, int $downloaded) use ($maximumBytes): void {
                            if ($downloaded > $maximumBytes) {
                                throw new RuntimeException('Product source response exceeds_max_bytes.');
                            }
                        },
                    ])
                    ->connectTimeout((int) config('product-images.http.connect_timeout', 3))
                    ->timeout($timeout ?? (int) config('product-images.http.timeout', 7))
                    ->get($url);
                // Fakes/alternative handlers may not write to the sink. Real cURL
                // already enforces this during decompression, before body() exists.
                if (($response->toPsrResponse()->getBody()->getSize() ?? 0) > $maximumBytes) {
                    throw new RuntimeException('Product source response exceeds_max_bytes.');
                }
                $response->throw();

                return $response;
            } catch (Throwable $error) {
                $sink->close();
                $tooLarge = false;
                for ($cause = $error; $cause !== null; $cause = $cause->getPrevious()) {
                    $tooLarge = $tooLarge || str_contains($cause->getMessage(), 'exceeds_max_bytes');
                }
                if ($tooLarge) {
                    throw new RuntimeException('Product source response exceeds_max_bytes.', previous: $error);
                }
                if ($attempt === 1) {
                    throw $error;
                }
                usleep(250_000);
            }
        }
        throw new RuntimeException('Product source download failed.');
    }
}
