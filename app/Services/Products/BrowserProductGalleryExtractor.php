<?php

namespace App\Services\Products;

use App\Models\ProductGalleryRecipe;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;
use Throwable;

class BrowserProductGalleryExtractor
{
    /**
     * @param  null|callable(string, string): void  $debug
     * @return array<int, string>
     */
    public function extract(string $url, int $limit = 20, ?callable $debug = null): array
    {
        if (! $this->available($limit, $debug)) {
            return [];
        }

        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $recipe = null;

        try {
            $recipe = ProductGalleryRecipe::query()
                ->where('domain', $host)
                ->where('status', 'active')
                ->first();
        } catch (Throwable) {
            // The migration may not have been run yet.
        }

        if ($recipe) {
            $debug?->__invoke('step', "Playwright: применяю AI-рецепт для {$host}.");
            $result = $this->executeRecipe($url, $recipe->recipe ?? [], $limit, $debug);
            $images = $result['images'] ?? [];

            if (count($images) >= 2) {
                $recipe->increment('success_count', 1, [
                    'last_success_at' => now(),
                    'last_error' => null,
                ]);
                $debug?->__invoke('done', 'Playwright получил фото: '.count($images).'.');

                return $images;
            }

            $recipe->increment('failure_count', 1, [
                'last_failure_at' => now(),
                'last_error' => 'Active AI recipe returned fewer than two gallery images.',
            ]);
            $debug?->__invoke('warning', 'Сохранённый рецепт перестал давать галерею; запускаю AI-переобучение.');
        } else {
            $debug?->__invoke('step', "Для {$host} ещё нет AI-рецепта; запускаю первичное обучение.");
        }

        return app(ProductGalleryRecipeTrainer::class)->train(
            $url,
            $recipe ? 'automatic_failure' : 'initial',
            $debug,
        );
    }

    /** @return array<string, mixed> */
    public function scout(string $url, ?callable $debug = null): array
    {
        return $this->runScript($url, [], 20, true, $debug);
    }

    /** @return array<string, mixed> */
    public function executeRecipe(
        string $url,
        array $recipe,
        int $limit = 20,
        ?callable $debug = null,
    ): array {
        return $this->runScript($url, $recipe, $limit, false, $debug);
    }

    /** @return array<string, mixed> */
    private function runScript(
        string $url,
        array $recipe,
        int $limit,
        bool $scoutOnly,
        ?callable $debug,
    ): array {
        if (! $this->available($limit, $debug)) {
            return [];
        }

        $script = base_path((string) config('product-images.browser_fallback.script', 'scripts/extract-product-gallery.mjs'));

        try {
            $process = new Process([
                (string) config('product-images.browser_fallback.node_binary', 'node'),
                $script,
                $url,
                (string) min(60, $limit),
            ], base_path(), [
                'PRODUCT_GALLERY_RECIPE' => json_encode($recipe, JSON_UNESCAPED_SLASHES),
                'PRODUCT_GALLERY_SCOUT_ONLY' => $scoutOnly ? '1' : '0',
            ]);
            $process->setTimeout((float) ($scoutOnly
                ? config('product-images.browser_fallback.scout_timeout', 60)
                : config('product-images.browser_fallback.timeout', 45)));
            $process->run();

            if (! $process->isSuccessful()) {
                $error = mb_substr(trim($process->getErrorOutput()), 0, 1000);
                $debug?->__invoke('error', "Playwright упал: {$error}");
                Log::debug('Browser product gallery extraction failed.', [
                    'host' => parse_url($url, PHP_URL_HOST),
                    'error' => $error,
                ]);

                return ['images' => [], 'error' => $error];
            }

            $result = json_decode(trim($process->getOutput()), true);

            if (! is_array($result)) {
                return ['images' => [], 'error' => 'Playwright returned invalid JSON.'];
            }

            $result['images'] = collect($result['images'] ?? [])
                ->filter(fn (mixed $image): bool => is_string($image)
                    && filter_var($image, FILTER_VALIDATE_URL) !== false
                    && in_array(parse_url($image, PHP_URL_SCHEME), ['http', 'https'], true))
                ->unique()->take($limit)->values()->all();

            return $result;
        } catch (Throwable $exception) {
            $debug?->__invoke('error', 'Playwright: '.$exception->getMessage());
            Log::debug('Browser product gallery extractor was unavailable.', [
                'host' => parse_url($url, PHP_URL_HOST),
                'error' => $exception->getMessage(),
            ]);

            return ['images' => [], 'error' => $exception->getMessage()];
        }
    }

    private function available(int $limit, ?callable $debug): bool
    {
        if (! config('product-images.browser_fallback.enabled', false) || $limit < 1) {
            return false;
        }

        $script = base_path((string) config('product-images.browser_fallback.script', 'scripts/extract-product-gallery.mjs'));

        if (is_file($script) && is_file(base_path('node_modules/playwright-core/package.json'))) {
            return true;
        }

        $debug?->__invoke('error', 'Playwright недоступен: отсутствует скрипт или playwright-core.');
        Log::debug('Browser product gallery extractor is unavailable.');

        return false;
    }
}
