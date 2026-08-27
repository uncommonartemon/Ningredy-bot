<?php

namespace App\Services\Products;

use App\Models\ProductGalleryRecipe;
use App\Services\Ai\AiSettings;
use App\Services\Ai\ProductSearchTimeBudget;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
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
        private readonly BrowserProductImageTransferStore $transfers,
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
        bool $forceInteractive = false,
        bool $activeRecipeOnly = false,
    ): array {
        if (! $this->available($limit, $debug)) {
            return [];
        }

        $minimumSuccessCount = max(1, min(
            AiSettings::GALLERY_MAX_IMAGE_COUNT,
            (int) ($context['minimum_verified_images'] ?? $this->settings->galleryMinSuccessCount()),
        ));

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
            $debug?->__invoke('warning', "Playwright для {$host} отключён после подтверждённой CAPTCHA/WAF. Обычный HTML-поиск остаётся доступен. · {$url}");

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

        $previousRecipeImages = null;

        if ($recipe?->status === 'active') {
            $debug?->__invoke('step', "Playwright: применяю AI-рецепт для {$host}.");
            $result = $this->executeRecipe($url, $recipe->recipe ?? [], $limit, $debug, $telegramUpdateId, $context);
            $observedLayoutFingerprint = app(ProductPageLayoutFingerprint::class)->make(
                is_array($result['scout'] ?? null) ? $result['scout'] : [],
            );
            if ($observedLayoutFingerprint !== null) {
                $recipe->update(['last_observed_layout_fingerprint' => $observedLayoutFingerprint]);
            }
            $this->recordBrowserResult($url, $result, $telegramUpdateId, 'active_recipe');
            $images = $result['images'] ?? [];
            $previousRecipeImages = $images;
            $validation = $this->resultValidator->validate(
                $recipe->recipe ?? [],
                $result,
                minimumSuccessCount: $minimumSuccessCount,
            );
            // A recipe is cached per domain, but the same domain can serve a
            // different page markup (a different regional storefront, an A/B
            // layout, ...) where the recipe's own selectors match nothing at
            // all. The runner then silently falls back to whatever raw
            // network traffic it happened to observe, which can still clear
            // the image-count validator without a single real click ever
            // happening - so that success is not trusted here even when the
            // count alone looks sufficient.
            $selectorsMismatched = $this->recipeSelectorsMismatchPage($recipe->recipe ?? [], $result);

            if ($validation['passed'] && ! $selectorsMismatched) {
                $this->rememberConfirmedGalleryImages($images);
                $recipe->increment('success_count', 1, [
                    'last_success_at' => now(),
                    'last_error' => null,
                ]);
                $debug?->__invoke('done', 'Playwright получил фото: '.count($images).'.');

                return $images;
            }

            if ($selectorsMismatched) {
                $layoutChanged = $recipe->layout_fingerprint !== null
                    && $observedLayoutFingerprint !== null
                    && $recipe->layout_fingerprint !== $observedLayoutFingerprint;
                $debug?->__invoke(
                    'warning',
                    "Сохранённый рецепт для {$host} не нашёл ни одного своего селектора на этой странице"
                        .($layoutChanged ? ' (layout fingerprint изменился)' : '')
                        .'; переобучаю специально под неё.',
                );
            } else {
                $debug?->__invoke(
                    'warning',
                    'Галерея неполная: получено '.$validation['extracted']
                        .' из '.$validation['expected'].'. Рецепт не засчитан.',
                );
            }

            $recipe->increment('failure_count', 1, [
                'last_failure_at' => now(),
                'last_error' => $selectorsMismatched
                    ? 'Recipe selectors matched nothing on this page (layout mismatch).'
                    : $validation['reason'],
            ]);
            if ($activeRecipeOnly) {
                $debug?->__invoke(
                    'warning',
                    'Готовый рецепт для '.$host.' не подтвердил полную галерею; в режиме Vision-first не переобучаю его, а передаю найденные кадры в Vision.',
                );

                return $images;
            }

            $debug?->__invoke('warning', 'Сохранённый рецепт перестал давать галерею; запускаю AI-переобучение.');
        } else {
            if ($activeRecipeOnly) {
                $debug?->__invoke('step', 'Для '.$host.' нет активного рецепта; режим Vision-first продолжает со статичными фотографиями без обучения Playwright. · '.$url);

                return [];
            }
            $debug?->__invoke('step', "Для {$host} ещё нет AI-рецепта; запускаю первичное обучение. · {$url}");
        }

        $images = app(ProductGalleryRecipeTrainer::class)->train(
            $url,
            $recipe ? 'automatic_failure' : 'initial',
            $debug,
            telegramUpdateId: $telegramUpdateId,
            context: $context,
            forceInteractive: $forceInteractive,
            previousRecipeImages: $previousRecipeImages,
        );

        $trainedRecipe = ProductGalleryRecipe::query()
            ->where('domain', $host)
            ->where('path_pattern', '*')
            ->first();
        $latestVersion = $trainedRecipe?->versions()->latest('id')->first();

        if ($latestVersion?->status === 'partial') {
            $this->rememberPartialGalleryImages($images);
        } elseif ($trainedRecipe?->status === 'active') {
            $latestResult = is_array($latestVersion?->result) ? $latestVersion->result : [];
            $validation = $this->resultValidator->validate(
                $trainedRecipe->recipe ?? [],
                [
                    'images' => $images,
                    'diagnostics' => is_array($latestResult['diagnostics'] ?? null)
                        ? $latestResult['diagnostics']
                        : [],
                    'action_trace' => is_array($latestResult['action_trace'] ?? null)
                        ? $latestResult['action_trace']
                        : [],
                    'failure_kind' => $latestResult['failure_kind'] ?? null,
                ],
                minimumSuccessCount: $minimumSuccessCount,
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
        return isset($this->confirmedGalleryImages[$this->galleryImageKey($url)]);
    }

    public function isPartialGalleryImage(string $url): bool
    {
        return isset($this->partialGalleryImages[$this->galleryImageKey($url)]);
    }

    /** @return array<string, mixed> */
    public function scout(
        string $url,
        ?callable $debug = null,
        ?int $telegramUpdateId = null,
        array $context = [],
    ): array {
        return $this->runScript($url, [], 20, true, $debug, $telegramUpdateId, $context);
    }

    /** @return array<string, mixed> */
    public function executeRecipe(
        string $url,
        array $recipe,
        int $limit = 20,
        ?callable $debug = null,
        ?int $telegramUpdateId = null,
        array $context = [],
    ): array {
        $recipe = $this->normalizeRecipeForExecution($recipe);

        return $this->runScript($url, $recipe, $limit, false, $debug, $telegramUpdateId, $context);
    }

    /** @param array<string, mixed> $recipe */
    private function normalizeRecipeForExecution(array $recipe): array
    {
        $collectSelectors = collect(is_array($recipe['collect_selectors'] ?? null)
            ? $recipe['collect_selectors']
            : [])
            ->filter(fn (mixed $selector): bool => is_string($selector) && trim($selector) !== '')
            ->map(fn (string $selector): string => trim($selector))
            ->unique()
            ->values();

        $recipe['collect_selectors'] = $collectSelectors
            ->reject(function (string $selector) use ($collectSelectors): bool {
                return $collectSelectors->contains(function (string $other) use ($selector): bool {
                    if ($other === $selector || ! str_ends_with($other, $selector)) {
                        return false;
                    }

                    $prefix = substr($other, 0, -strlen($selector));

                    return preg_match('/(?:\\s|>|\\+|~)\\s*$/', $prefix) === 1;
                });
            })
            ->values()
            ->all();

        return $recipe;
    }

    /** @return array<string, mixed> */
    private function runScript(
        string $url,
        array $recipe,
        int $limit,
        bool $scoutOnly,
        ?callable $debug,
        ?int $telegramUpdateId,
        array $context,
    ): array {
        if (! $this->available($limit, $debug)) {
            return [];
        }

        $script = base_path((string) config('product-images.browser_fallback.script', 'scripts/extract-product-gallery.mjs'));
        $transferDirectory = storage_path('framework/product-gallery-browser/'.Str::uuid());

        try {
            File::ensureDirectoryExists($transferDirectory);
            $configuredTimeout = $scoutOnly
                ? $this->settings->browserScoutTimeoutSeconds()
                : $this->settings->browserTimeoutSeconds();
            $timeoutSeconds = $this->timeBudget->timeoutFor($telegramUpdateId, $configuredTimeout);
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
                'PRODUCT_GALLERY_MINIMUM_WIDTH' => (string) max(100, min(4000, (int) (
                    $context['minimum_image_width'] ?? $this->settings->imageMinimumWidth()
                ))),
                'PRODUCT_GALLERY_MINIMUM_HEIGHT' => (string) max(0, min(4000, (int) (
                    $context['minimum_image_height'] ?? $this->settings->imageMinimumHeight()
                ))),
                // The script must finish - with whatever it already gathered -
                // before this process is killed at the timeout; on Windows that
                // kill is a hard TerminateProcess, so this reserve is the
                // script's only chance to serialize a partial result.
                'PRODUCT_GALLERY_DEADLINE_MS' => (string) max(10000, ($timeoutSeconds - 12) * 1000),
                'PRODUCT_GALLERY_TRANSFER_DIR' => $transferDirectory,
            ]);
            $process->setTimeout((float) $timeoutSeconds);
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

            $this->transfers->remember($result['transferred_images'] ?? [], $transferDirectory);
            unset($result['transferred_images']);

            $result['images'] = collect($result['images'] ?? [])
                ->filter(fn (mixed $image): bool => is_string($image)
                    && filter_var($image, FILTER_VALIDATE_URL) !== false
                    && in_array(parse_url($image, PHP_URL_SCHEME), ['http', 'https'], true))
                ->unique()->take($limit)->values()->all();

            if (! $scoutOnly && collect($result['action_trace'] ?? [])->contains(
                fn (mixed $action): bool => is_array($action)
                    && ($action['phase'] ?? null) === 'open_expanded_gallery'
                    && ($action['clicked'] ?? false) === true,
            )) {
                $debug?->__invoke(
                    'step',
                    'Playwright открыл увеличенный viewer и собирает полноразмерные кадры внутри него.',
                );
            }

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
        } finally {
            File::deleteDirectory($transferDirectory);
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
                'input' => [
                    'selector' => $action['selector'] ?? null,
                    'index' => $action['index'] ?? null,
                    'action_index' => $action['action_index'] ?? null,
                    'repetition' => $action['repetition'] ?? null,
                    'purpose' => $action['purpose'] ?? null,
                    'selector_match_count' => $action['selector_match_count'] ?? null,
                ],
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
                $this->confirmedGalleryImages[$this->galleryImageKey($image)] = true;
            }
        }
    }

    /** @param array<int, string> $images */
    private function rememberPartialGalleryImages(array $images): void
    {
        foreach ($images as $image) {
            if (is_string($image) && $image !== '') {
                $this->partialGalleryImages[$this->galleryImageKey($image)] = true;
            }
        }
    }

    /**
     * ProductImageResolver::download() looks these up by an already-
     * normalized URL (its caller runs every candidate through
     * ProductImageStorage::normalizeCandidateUrl() first), but Playwright
     * returns the raw URLs it saw on the page - hashing both sides through
     * the same normalization keeps a confirmed/partial gallery photo from
     * silently losing that flag just because its query string got rewritten
     * (Scene7 wid/hei upgraded, a $PRESET$ modifier stripped, ...) between
     * when it was remembered and when it's looked up.
     */
    private function galleryImageKey(string $url): string
    {
        return hash('sha256', ProductImageStorage::imageAssetKey($url));
    }

    /**
     * When applying a cached recipe, the runner only sends the recipe's own
     * collect/thumbnail selectors to the browser (see extract-product-gallery.mjs's
     * strictRecipe mode) - no generic fallback selectors are mixed in. So if
     * learned_recipe comes back with both lists empty while the recipe itself
     * configured at least one selector, every single one of them matched zero
     * elements on this exact page: the recipe belongs to a different markup
     * version of the domain, not a working-but-incomplete gallery.
     *
     * @param  array<string, mixed>  $recipe
     * @param  array<string, mixed>  $result
     */
    private function recipeSelectorsMismatchPage(array $recipe, array $result): bool
    {
        $configuredSelectors = [
            ...(is_array($recipe['collect_selectors'] ?? null) ? $recipe['collect_selectors'] : []),
            ...(is_array($recipe['thumbnail_selectors'] ?? null) ? $recipe['thumbnail_selectors'] : []),
            ...collect(is_array($recipe['actions'] ?? null) ? $recipe['actions'] : [])
                ->pluck('selector')
                ->filter(fn (mixed $selector): bool => is_string($selector) && $selector !== '')
                ->all(),
        ];

        if ($configuredSelectors === []) {
            return false;
        }

        // learned_recipe is captured before the action plan. A valid recipe
        // may collect from a lightbox that only exists after its opener, so
        // an empty pre-action snapshot cannot overrule stronger post-action
        // proof. Strict validation still requires recipe_dom provenance.
        if (data_get($result, 'diagnostics.strict_recipe') === true) {
            $imageCount = collect($result['images'] ?? [])
                ->filter(fn (mixed $url): bool => is_string($url) && $url !== '')
                ->unique(fn (string $url): string => ProductImageStorage::imageAssetKey($url))
                ->count();
            $recipeDomCount = collect(data_get($result, 'diagnostics.validated_image_evidence', []))
                ->filter(fn (mixed $item): bool => is_array($item)
                    && ($item['source'] ?? null) === 'recipe_dom'
                    && is_string($item['url'] ?? null)
                    && $item['url'] !== '')
                ->pluck('url')
                ->unique(fn (string $url): string => ProductImageStorage::imageAssetKey($url))
                ->count();

            if ($imageCount > 0 && $recipeDomCount >= $imageCount) {
                return false;
            }
        }

        // Absence of the field (an older script version, an unexpected
        // result shape) must not be read as evidence of a mismatch - only a
        // learned_recipe that was actually computed and came back empty
        // counts.
        if (! array_key_exists('learned_recipe', $result) || ! is_array($result['learned_recipe'])) {
            return false;
        }

        $learned = $result['learned_recipe'];

        $matchedCollect = is_array($learned['collect_selectors'] ?? null) ? $learned['collect_selectors'] : [];
        $matchedThumbnails = is_array($learned['thumbnail_selectors'] ?? null) ? $learned['thumbnail_selectors'] : [];
        $matchedActions = is_array($learned['actions'] ?? null) ? $learned['actions'] : [];

        return $matchedCollect === [] && $matchedThumbnails === [] && $matchedActions === [];
    }
}
