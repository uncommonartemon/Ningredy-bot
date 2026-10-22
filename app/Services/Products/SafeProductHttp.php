<?php

namespace App\Services\Products;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Pool;
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
        $this->requireCurl();
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $target = $this->urls->resolve($url);
            $sink = new BoundedProductResponseStream($maximumBytes);
            try {
                $response = $this->prepare(Http::withHeaders($headers), $target, $sink, $maximumBytes, $timeout)
                    ->withOptions([
                        'progress' => function (int $downloadSize, int $downloaded) use ($maximumBytes): void {
                            if ($downloaded > $maximumBytes) {
                                throw new RuntimeException('Product source response exceeds_max_bytes.');
                            }
                        },
                    ])
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
                if ($this->tooLarge($error)) {
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

    /**
     * get() for several URLs at once: the same one-hop guards, with the transfers
     * running concurrently. A failure takes its response's place instead of being
     * thrown, so one bad URL never costs the others their download.
     *
     * @param  array<array-key, array{url: string, headers: array<string, string>}>  $requests
     * @return array<array-key, Response|Throwable>
     */
    public function getMany(array $requests, int $maximumBytes, ?int $timeout = null): array
    {
        $this->requireCurl();
        $results = [];
        for ($attempt = 0; $attempt < 2 && $requests !== []; $attempt++) {
            if ($attempt > 0) {
                usleep(250_000);
            }
            $targets = [];
            foreach ($requests as $key => $request) {
                try {
                    $targets[$key] = $this->urls->resolve($request['url']);
                } catch (Throwable $error) {
                    $results[$key] = $error;
                    unset($requests[$key]);
                }
            }
            // A throwing sink or progress callback would escape the shared cURL loop
            // and take every transfer in it down; this sink only stops its own one.
            $sinks = array_map(fn (): BoundedProductResponseStream => new BoundedProductResponseStream($maximumBytes, abortQuietly: true), $requests);
            $responses = $requests === [] ? [] : Http::pool(function (Pool $pool) use ($requests, $targets, $sinks, $maximumBytes, $timeout): void {
                foreach ($requests as $key => $request) {
                    $this->prepare($pool->as((string) $key)->withHeaders($request['headers']), $targets[$key], $sinks[$key], $maximumBytes, $timeout)
                        ->get($request['url']);
                }
            });
            foreach ($requests as $key => $request) {
                $response = $responses[$key] ?? new RuntimeException('Product source download failed.');
                if ($sinks[$key]->exceeded || $this->tooLarge($response)
                    || ($response instanceof Response && ($this->transferError($response) === CURLE_FILESIZE_EXCEEDED
                        || ($response->toPsrResponse()->getBody()->getSize() ?? 0) > $maximumBytes))) {
                    $sinks[$key]->close();
                    $results[$key] = new RuntimeException('Product source response exceeds_max_bytes.');
                    unset($requests[$key]);
                } elseif ($response instanceof Response && ! $response->failed() && $this->transferError($response) === 0) {
                    $results[$key] = $response;
                    unset($requests[$key]);
                } else {
                    $sinks[$key]->close();
                    $results[$key] = match (true) {
                        ! $response instanceof Response => $response,
                        $response->failed() => $response->toException(),
                        default => new RuntimeException('Product source transfer was interrupted.'),
                    };
                }
            }
        }

        return $results;
    }

    private function prepare(PendingRequest $request, array $target, BoundedProductResponseStream $sink, int $maximumBytes, ?int $timeout): PendingRequest
    {
        $addresses = array_map(fn (string $ip): string => str_contains($ip, ':') ? '['.$ip.']' : $ip, $target['addresses']);

        return $request->withoutRedirecting()
            ->withOptions([
                // An ambient HTTP proxy would resolve independently, defeating
                // CURLOPT_RESOLVE. These untrusted-source requests go direct.
                'proxy' => '', 'sink' => $sink,
                // Bytes on the wire, which the sink never sees under compression.
                'curl' => [CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*', CURLOPT_MAXFILESIZE_LARGE => $maximumBytes]
                    + (! filter_var($target['host'], FILTER_VALIDATE_IP)
                        ? [CURLOPT_RESOLVE => [$target['host'].':'.$target['port'].':'.implode(',', $addresses)]] : []),
                'on_headers' => function (ResponseInterface $response) use ($maximumBytes, $sink): void {
                    $length = $response->getHeaderLine('Content-Length');
                    if (ctype_digit($length) && (float) $length > $maximumBytes) {
                        // Concurrently this error comes back as the response it stopped,
                        // so the sink keeps the verdict.
                        $sink->exceeded = true;
                        throw new RuntimeException('Product source response exceeds_max_bytes.');
                    }
                },
            ])
            ->connectTimeout((int) config('product-images.http.connect_timeout', 3))
            ->timeout($timeout ?? (int) config('product-images.http.timeout', 7));
    }

    private function tooLarge(mixed $error): bool
    {
        for ($cause = $error instanceof Throwable ? $error : null; $cause !== null; $cause = $cause->getPrevious()) {
            // cURL error 63 is CURLOPT_MAXFILESIZE_LARGE.
            if (str_contains($cause->getMessage(), 'exceeds_max_bytes') || str_contains($cause->getMessage(), 'cURL error 63:')) {
                return true;
            }
        }

        return false;
    }

    /**
     * A transfer cut off mid-body still arrives as a response when it runs
     * concurrently, with whatever part of the body came through - a truncated
     * JPEG that would decode into a half-grey photo. This is the cURL error it
     * ended with, 0 when it finished cleanly.
     */
    private function transferError(Response $response): int
    {
        $error = $response->transferStats?->getHandlerErrorData();

        return is_int($error) ? $error : ($error === null ? 0 : -1);
    }

    private function requireCurl(): void
    {
        if (! extension_loaded('curl')) {
            throw new RuntimeException('Safe product downloads require cURL DNS pinning.');
        }
    }
}
