<?php

namespace App\Services\Products;

use App\Models\ProductGalleryRecipe;
use App\Services\Ai\AiSettings;
use App\Services\Ai\ProductSearchTimeBudget;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Throwable;

class BrowserProductGalleryExtractor
{
    /** @var array<string, true> */
    private array $confirmedGalleryImages = [];

    /** @var array<string, true> */
    private array $partialGalleryImages = [];

    public function __construct(
        private readonly AiSettings $settings,
        private readonly ProductSearchTimeBudget $timeBudget,
        private readonly ProductGalleryRecipeResultValidator $resultValidator,
        private readonly ProductSourceAttemptRecorder $attempts,
    ) {}

    /**
     * @param  null|callable(string, string): void  $debug
     * @return array<int, string>
     */
    public function extract(
        string $url,
        int $limit = 20,
        ?callable $debug = null,
        ?int $telegramUpdateId = null,
        array $context = [],
    ): array
    {
        if (! $this->available($limit, $debug)) {
            return [];
        }

        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $recipe = null;

        try {
            $recipe = ProductGalleryRecipe::query()
                ->where('domain', $host)
                ->where('path_pattern', '*')
                ->first();
        } catch (Throwable) {
            // The migration may not have been run yet.
        }

        if ($recipe?->status === 'disabled') {
            $debug?->__invoke('warning', "Playwright для {$host} отключён после подтверждённой CAPTCHA/WAF. Обычный HTML-поиск остаётся доступен.");

            return [];
        }

        if (
            $recipe?->status !== 'active'
            && $recipe?->retry_after?->isFuture()
        ) {
            $debug?->__invoke(
                'warning',
                "Playwright для {$host} на паузе до {$recipe->retry_after->format('H:i d.m')}; перехожу к следующему источнику.",
            );

            return [];
        }

        if ($recipe?->status === 'active') {
            $debug?->__invoke('step', "Playwright: применяю AI-рецепт для {$host}.");
            $result = $this->executeRecipe($url, $recipe->recipe ?? [], $limit, $debug, $telegramUpdateId);
            $this->recordBrowserResult($url, $result, $telegramUpdateId, 'active_recipe');
            $images = $result['images'] ?? [];
            $validation = $this->resultValidator->validate($recipe->recipe ?? [], $result);

            if ($validation['passed']) {
                $this->rememberConfirmedGalleryImages($images);
                $recipe->increment('success_count', 1, [
                    'last_success_at' => now(),
                    'last_error' => null,
                ]);
                $debug?->__invoke('done', 'Playwright получил фото: '.count($images).'.');

                return $images;
            }

            $debug?->__invoke(
                'warning',
                'Галерея неполная: получено '.$validation['extracted']
                    .' из '.$validation['expected'].'. Рецепт не засчитан.',
            );

            $recipe->increment('failure_count', 1, [
                'last_failure_at' => now(),
                'last_error' => $validation['reason'],
            ]);
            $debug?->__invoke('warning', 'Сохранённый рецепт перестал давать галерею; запускаю AI-переобучение.');
        } else {
            $debug?->__invoke('step', "Для {$host} ещё нет AI-рецепта; запускаю первичное обучение.");
        }

        $images = app(ProductGalleryRecipeTrainer::class)->train(
            $url,
            $recipe ? 'automatic_failure' : 'initial',
            $debug,
            telegramUpdateId: $telegramUpdateId,
            context: $context,
        );

        $trainedRecipe = ProductGalleryRecipe::query()
            ->where('domain', $host)
            ->where('path_pattern', '*')
            ->first();
        $latestVersion = $trainedRecipe?->versions()->latest('id')->first();

        if ($latestVersion?->status === 'partial') {
            $this->rememberPartialGalleryImages($images);
        } elseif ($trainedRecipe?->status === 'active') {
            $validation = $this->resultValidator->validate(
                $trainedRecipe->recipe ?? [],
                ['images' => $images],
            );

            if ($validation['passed']) {
                $this->rememberConfirmedGalleryImages($images);
            }
        } elseif ($images !== [] && $latestVersion?->status !== 'skipped') {
            $this->rememberPartialGalleryImages($images);
        }

        return $images;
    }

    public function isConfirmedGalleryImage(string $url): bool
    {
        return isset($this->confirmedGalleryImages[hash('sha256', $url)]);
    }

    public function isPartialGalleryImage(string $url): bool
    {
        return isset($this->partialGalleryImages[hash('sha256', $url)]);
    }

    /** @return array<string, mixed> */
    public function scout(string $url, ?callable $debug = null, ?int $telegramUpdateId = null): array
    {
        return $this->runScript($url, [], 20, true, $debug, $telegramUpdateId);
    }

    /** @return array<string, mixed> */
    public function executeRecipe(
        string $url,
        array $recipe,
        int $limit = 20,
        ?callable $debug = null,
        ?int $telegramUpdateId = null,
    ): array {
        return $this->runScript($url, $recipe, $limit, false, $debug, $telegramUpdateId);
    }

    /** @return array<string, mixed> */
    private function runScript(
        string $url,
        array $recipe,
        int $limit,
        bool $scoutOnly,
        ?callable $debug,
        ?int $telegramUpdateId,
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
                'PRODUCT_GALLERY_DOM_WAIT_MS' => (string) config('product-images.browser_fallback.dom_wait_ms', 12000),
                'PRODUCT_GALLERY_PROBE_TIMEOUT_MS' => (string) config('product-images.browser_fallback.image_probe_timeout_ms', 5000),
                'PRODUCT_GALLERY_MINIMUM_SIDE' => (string) config('product-images.minimum_side', 500),
                'PRODUCT_GALLERY_CONFIRMED_MINIMUM_SIDE' => (string) config(
                    'product-images.browser_fallback.confirmed_gallery_minimum_side',
                    400,
                ),
            ]);
            $configuredTimeout = $scoutOnly
                ? $this->settings->browserScoutTimeoutSeconds()
                : $this->settings->browserTimeoutSeconds();
            $process->setTimeout((float) $this->timeBudget->timeoutFor($telegramUpdateId, $configuredTimeout));
            $process->run();

            if (! $process->isSuccessful()) {
                $error = mb_substr(trim($process->getErrorOutput()), 0, 1000);
                $debug?->__invoke('error', "Playwright упал: {$error}");
                Log::debug('Browser product gallery extraction failed.', [
                    'host' => parse_url($url, PHP_URL_HOST),
                    'error' => $error,
                ]);

                return ['images' => [], 'error' => $error, 'failure_kind' => 'browser_process'];
            }

            $result = json_decode(trim($process->getOutput()), true);

            if (! is_array($result)) {
                return ['images' => [], 'error' => 'Playwright returned invalid JSON.', 'failure_kind' => 'browser_protocol'];
            }

            $result['images'] = collect($result['images'] ?? [])
                ->filter(fn (mixed $image): bool => is_string($image)
                    && filter_var($image, FILTER_VALIDATE_URL) !== false
                    && in_array(parse_url($image, PHP_URL_SCHEME), ['http', 'https'], true))
                ->unique()->take($limit)->values()->all();

            return $result;
        } catch (ProcessTimedOutException $exception) {
            $debug?->__invoke('warning', 'Playwright превысил лимит времени; источник можно повторить позже.');
            Log::notice('Browser product gallery extraction timed out.', [
                'host' => parse_url($url, PHP_URL_HOST),
                'error' => $exception->getMessage(),
            ]);

            return [
                'images' => [],
                'error' => $exception->getMessage(),
                'failure_kind' => 'browser_timeout',
            ];
        } catch (Throwable $exception) {
            $debug?->__invoke('error', 'Playwright: '.$exception->getMessage());
            Log::debug('Browser product gallery extractor was unavailable.', [
                'host' => parse_url($url, PHP_URL_HOST),
                'error' => $exception->getMessage(),
            ]);

            return [
                'images' => [],
                'error' => $exception->getMessage(),
                'failure_kind' => 'browser_unavailable',
            ];
        }
    }

    private function available(int $limit, ?callable $debug): bool
    {
        if (
            ! config('product-images.browser_fallback.enabled', false)
            || $this->settings->galleryBrowserMode() === AiSettings::GALLERY_BROWSER_OFF
            || $limit < 1
        ) {
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

    private function recordBrowserResult(
        string $url,
        array $result,
        ?int $telegramUpdateId,
        string $phase,
    ): void {
        $this->attempts->record([
            'telegram_update_id' => $telegramUpdateId,
            'product_url' => $url,
            'actor' => 'playwright',
            'phase' => $phase,
            'action' => 'extract_gallery',
            'status' => ($result['images'] ?? []) !== [] ? 'completed' : 'failed',
            'decision' => ($result['failure_kind'] ?? null) ?: 'gallery_extracted',
            'output' => [
                'images' => $result['images'] ?? [],
                'diagnostics' => $result['diagnostics'] ?? [],
                'error' => $result['error'] ?? null,
            ],
        ]);

        foreach ($result['action_trace'] ?? [] as $action) {
            if (! is_array($action)) {
                continue;
            }

            $this->attempts->record([
                'telegram_update_id' => $telegramUpdateId,
                'product_url' => $url,
                'actor' => 'playwright',
                'phase' => $phase,
                'action' => (string) ($action['action'] ?? 'click'),
                'status' => ($action['clicked'] ?? false) ? 'completed' : 'skipped',
                'decision' => ($action['changed'] ?? false) ? 'dom_changed' : 'no_change',
                'input' => ['selector' => $action['selector'] ?? null],
                'output' => $action,
                'duration_ms' => isset($action['duration_ms']) ? (int) $action['duration_ms'] : null,
            ]);
        }
    }

    /** @param array<int, string> $images */
    private function rememberConfirmedGalleryImages(array $images): void
    {
        foreach ($images as $image) {
            if (is_string($image) && $image !== '') {
                $this->confirmedGalleryImages[hash('sha256', $image)] = true;
            }
        }
    }

    /** @param array<int, string> $images */
    private function rememberPartialGalleryImages(array $images): void
    {
        foreach ($images as $image) {
            if (is_string($image) && $image !== '') {
                $this->partialGalleryImages[hash('sha256', $image)] = true;
            }
        }
    }
}
