<?php

namespace App\Services\Products;

use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;
use Throwable;

use App\Models\ProductGalleryRecipe;

class BrowserProductGalleryExtractor
{
    /**
     * @param  null|callable(string, string): void  $debug
     * @return array<int, string>
     */
    public function extract(string $url, int $limit = 20, ?callable $debug = null): array
    {
        if (! config('product-images.browser_fallback.enabled', false) || $limit < 1) {
            return [];
        }

        $script = base_path((string) config('product-images.browser_fallback.script', 'scripts/extract-product-gallery.mjs'));

        if (! is_file($script) || ! is_file(base_path('node_modules/playwright-core/package.json'))) {
            if ($debug) {
                $debug('error', 'Playwright недоступен: отсутствует скрипт или playwright-core.');
            }
            Log::debug('Browser product gallery extractor is unavailable.');

            return [];
        }

        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $recipe = null;

        try {
            $recipe = ProductGalleryRecipe::query()->where('domain', $host)->where('status', '!=', 'disabled')->first();
        } catch (Throwable) {
            // The migration may not have been run yet; generic extraction remains available.
        }

        if ($recipe?->last_failure_at?->isAfter(now()->subMinutes(10)) && $recipe->status === 'learning') {
            if ($debug) {
                $debug('warning', "Playwright: {$host} уже вернул пустую галерею; пропускаю повтор до окончания 10-минутного cooldown.");
            }

            return [];
        }

        try {
            if ($debug) {
                $debug('step', $recipe
                    ? "Playwright: применяю сохранённый сценарий для {$host}."
                    : "Playwright: изучаю структуру галереи {$host}.");
            }
            $process = new Process([
                (string) config('product-images.browser_fallback.node_binary', 'node'),
                $script,
                $url,
                (string) min(60, $limit),
            ], base_path(), [
                'PRODUCT_GALLERY_RECIPE' => json_encode($recipe?->recipe ?? [], JSON_UNESCAPED_SLASHES),
            ]);
            $process->setTimeout((float) config('product-images.browser_fallback.timeout', 45));
            $process->run();

            if (! $process->isSuccessful()) {
                $error = mb_substr(trim($process->getErrorOutput()), 0, 1000);
                if ($debug) {
                    $debug('error', "Playwright упал: {$error}");
                }
                $recipe?->update([
                    'failure_count' => $recipe->failure_count + 1,
                    'last_failure_at' => now(),
                    'last_error' => $error,
                ]);
                Log::debug('Browser product gallery extraction failed.', ['host' => $host, 'error' => $error]);

                return [];
            }

            $result = json_decode(trim($process->getOutput()), true);
            $images = collect($result['images'] ?? [])
                ->filter(fn (mixed $image): bool => is_string($image)
                    && filter_var($image, FILTER_VALIDATE_URL) !== false
                    && in_array(parse_url($image, PHP_URL_SCHEME), ['http', 'https'], true))
                ->unique()
                ->take($limit)
                ->values()
                ->all();
            $learned = is_array($result['learned_recipe'] ?? null) ? $result['learned_recipe'] : [];

            try {
                $stored = ProductGalleryRecipe::query()->firstOrCreate(
                    ['domain' => $host, 'path_pattern' => '*'],
                    ['status' => 'learning'],
                );
                $successful = count($images) >= 2;
                $stored->update([
                    'recipe' => $learned !== [] ? $learned : $stored->recipe,
                    'status' => $successful ? 'active' : 'learning',
                    'success_count' => $stored->success_count + ($successful ? 1 : 0),
                    'failure_count' => $stored->failure_count + ($successful ? 0 : 1),
                    'last_success_at' => $successful ? now() : $stored->last_success_at,
                    'last_failure_at' => $successful ? $stored->last_failure_at : now(),
                    'last_error' => $successful ? null : 'Playwright returned fewer than two gallery images.',
                ]);
            } catch (Throwable $exception) {
                Log::debug('Could not persist gallery recipe.', ['host' => $host, 'error' => $exception->getMessage()]);
            }

            if ($debug) {
                $debug('done', 'Playwright получил фото: '.count($images).'.');
            }

            return $images;
        } catch (Throwable $exception) {
            if ($debug) {
                $debug('error', 'Playwright: '.$exception->getMessage());
            }
            Log::debug('Browser product gallery extractor was unavailable.', [
                'host' => $host,
                'error' => $exception->getMessage(),
            ]);

            return [];
        }
    }
}
