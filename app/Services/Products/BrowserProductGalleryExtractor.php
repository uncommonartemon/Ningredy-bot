<?php

namespace App\Services\Products;

use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;
use Throwable;

class BrowserProductGalleryExtractor
{
    /** @return array<int, string> */
    public function extract(string $url, int $limit = 20): array
    {
        if (! config('product-images.browser_fallback.enabled', false) || $limit < 1) {
            return [];
        }

        $script = base_path((string) config(
            'product-images.browser_fallback.script',
            'scripts/extract-product-gallery.mjs',
        ));

        if (! is_file($script) || ! is_file(base_path('node_modules/playwright-core/package.json'))) {
            Log::debug('Browser product gallery extractor is unavailable.', [
                'script_exists' => is_file($script),
                'playwright_installed' => is_file(base_path('node_modules/playwright-core/package.json')),
            ]);

            return [];
        }

        try {
            $process = new Process([
                (string) config('product-images.browser_fallback.node_binary', 'node'),
                $script,
                $url,
                (string) min(60, $limit),
            ], base_path());
            $process->setTimeout((float) config('product-images.browser_fallback.timeout', 35));
            $process->run();

            if (! $process->isSuccessful()) {
                Log::debug('Browser product gallery extraction failed.', [
                    'host' => parse_url($url, PHP_URL_HOST),
                    'error' => mb_substr(trim($process->getErrorOutput()), 0, 1000),
                ]);

                return [];
            }

            $result = json_decode(trim($process->getOutput()), true);

            return collect($result['images'] ?? [])
                ->filter(fn (mixed $image): bool => is_string($image)
                    && filter_var($image, FILTER_VALIDATE_URL) !== false
                    && in_array(parse_url($image, PHP_URL_SCHEME), ['http', 'https'], true))
                ->unique()
                ->take($limit)
                ->values()
                ->all();
        } catch (Throwable $exception) {
            Log::debug('Browser product gallery extractor was unavailable.', [
                'host' => parse_url($url, PHP_URL_HOST),
                'error' => $exception->getMessage(),
            ]);

            return [];
        }
    }
}
