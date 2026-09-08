<?php

namespace App\Services\Products;

use App\Services\Ai\AiSettings;
use App\Services\Ai\ProductSearchTimeBudget;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Throwable;

class BrowserProductGalleryExtractor
{
    private const MAX_COMPATIBLE_RECIPE_ATTEMPTS = 3;

    /** @var array<string, true> */
    private array $confirmedGalleryImages = [];

    /** @var array<string, true> */
    private array $partialGalleryImages = [];

    /**
     * Frames collected from a page whose identity could not be compared with
     * the product page - unknown, not wrong.
     *
     * They are kept, because the agent chose the control that led there and a
     * shop that publishes no metadata is not thereby a shop with no gallery.
     * What they do not get is the confirmed_gallery badge, which is what lets a
     * frame skip ahead of the checks that would have caught a wrong product.
     *
     * @var array<string, true>
     */
    private array $identityUnconfirmedImages = [];

    public function __construct(
        private readonly AiSettings $settings,
        private readonly ProductSearchTimeBudget $timeBudget,
        private readonly ProductGalleryRecipeResultValidator $resultValidator,
        private readonly ProductSourceAttemptRecorder $attempts,
        private readonly BrowserProductImageTransferStore $transfers,
        private readonly ProductGalleryRecipeRouter $recipeRouter,
        private readonly HostReputation $reputation,
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
        $domainBlocked = false;

        try {
            $domainBlocked = $this->recipeRouter->domainIsBlocked($url);
            $recipe = $this->recipeRouter->recipeForUrl($url);
        } catch (Throwable) {
            // The migration may not have been run yet.
        }

        if ($domainBlocked) {
            return [];
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
                countedOnThisPage: false,
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

                return $previousRecipeImages ?? $images;
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

            $compatibleAttempt = $this->tryCompatibleDomainRecipes(
                $url,
                $limit,
                $minimumSuccessCount,
                $recipe->id,
                $debug,
                $telegramUpdateId,
                $context,
            );
            if ($compatibleAttempt['passed']) {
                return $compatibleAttempt['images'];
            }
            if (count($compatibleAttempt['images']) > count($previousRecipeImages ?? [])) {
                $previousRecipeImages = $compatibleAttempt['images'];
            }

            if ($activeRecipeOnly) {
                $debug?->__invoke(
                    'warning',
                    'Готовый рецепт для '.$host.' не подтвердил полную галерею; в режиме Vision-first не переобучаю его, а передаю найденные кадры в Vision.',
                );

                return $previousRecipeImages ?? $images;
            }

            $debug?->__invoke('warning', 'Сохранённый рецепт перестал давать галерею; отправляю его агенту на ремонт.');
            $repairFrom = [
                'recipe' => $recipe->recipe ?? [],
                'reason' => $selectorsMismatched
                    ? 'None of the recipe selectors matched anything on this page.'
                    : ($validation['reason'] ?? 'unknown'),
                'action_trace' => $result['action_trace'] ?? [],
                'diagnostics' => $result['diagnostics'] ?? [],
                'images' => $images,
            ];
        } else {
            $compatibleAttempt = $this->tryCompatibleDomainRecipes(
                $url,
                $limit,
                $minimumSuccessCount,
                null,
                $debug,
                $telegramUpdateId,
                $context,
            );
            if ($compatibleAttempt['passed']) {
                return $compatibleAttempt['images'];
            }
            $previousRecipeImages = $compatibleAttempt['images'];

            // "This domain has no recipe yet" was printed even when the domain
            // had several - the line above it says their compatibility was just
            // being probed. A shop the bot already knows how to open, described
            // to the operator as one it has never seen, is the kind of report
            // that makes the whole log untrustworthy.
            $recipeSituation = $this->recipeSituationLabel(
                $host,
                $this->recipeRouter->compatibleCandidatesForUrl($url, null, 25)->count(),
            );

            if ($activeRecipeOnly) {
                $debug?->__invoke('step', $recipeSituation.'; режим Vision-first продолжает со статичными фотографиями без обучения Playwright. · '.$url);

                return $previousRecipeImages;
            }
            $debug?->__invoke('step', $recipeSituation.'; запускаю обучение под этот путь. · '.$url);
        }

        $repairFrom ??= [];
        $images = app(ProductGalleryRecipeTrainer::class)->train(
            $url,
            $recipe ? 'automatic_failure' : 'initial',
            $debug,
            telegramUpdateId: $telegramUpdateId,
            context: $context,
            forceInteractive: $forceInteractive,
            previousRecipeImages: $previousRecipeImages,
            repairFrom: $repairFrom,
        );

        $trainedRecipe = $this->recipeRouter->exactRecipeForUrl($url)
            ?? $this->recipeRouter->recipeForUrl($url);
        $latestVersion = $trainedRecipe?->versions()->latest('id')->first();

        if (in_array($latestVersion?->status, ['partial', 'deferred', 'interrupted'], true)) {
            // An older active recipe must not lend its trust to frames from
            // an unfinished repair. Provisional frames still need verification.
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

    /**
     * Before paying for AI training, try the most successful active recipes
     * already known on this domain. A failed hypothesis does not damage that
     * recipe's health because this path was never one of its confirmed scopes.
     *
     * @param  null|callable(string, string): void  $debug
     * @return array{passed: bool, images: array<int, string>}
     */
    /**
     * Training opens the same page once per round, so a shop could receive four
     * or five browser visits inside a couple of minutes - from its side,
     * indistinguishable from someone hammering it, and the reason the
     * challenges appeared. Spacing visits to one host is the honest fix: it
     * makes us a lighter guest rather than a harder-to-recognise one. The wait
     * is small enough to be invisible against a browser run that takes tens of
     * seconds anyway.
     */
    /**
     * What the page the browser was actually served says about our welcome.
     *
     * A robot check, a security wall or a 403 is the shop answering; a page
     * that loaded is the shop accepting. Both are recorded, because a host
     * that starts answering again should stop being treated as hostile.
     *
     * @param  array<string, mixed>  $scout
     */
    private function rememberAccessChallenge(string $url, array $scout): void
    {
        if (($scout['access_gate'] ?? false) === true) {
            $this->reputation->noteRefusal(
                $url,
                (string) ($scout['access_gate_reason'] ?? HostReputation::REFUSAL_ACCESS_GATE),
            );

            return;
        }

        $this->reputation->noteAcceptance($url);
    }

    /**
     * Whether the browser was left waiting on a connection that was accepted
     * and then never answered.
     *
     * This is not a crash and not a broken protocol: the handshake completes
     * in milliseconds and nothing follows. Measured on 2026-09-06 against a
     * host that had refused us all day - 0.07s to connect, then twenty seconds
     * of silence, identically over HTTP/2 and HTTP/1.1. Retrying the other
     * protocol cannot help, and only the timeout is paid.
     */
    private function looksLikeSilentHost(string $signal): bool
    {
        $signal = mb_strtolower($signal);

        return str_contains($signal, 'page.goto')
            && (str_contains($signal, 'timeout') || str_contains($signal, 'err_connection_timed_out'));
    }

    private function pauseBetweenVisits(string $url): void
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        if ($host === '') {
            return;
        }

        // Slowing every shop down to protect against the few that push back is
        // the wrong trade: one training makes five to eight visits to the same
        // host, so a twenty-second spacing would spend two minutes waiting on
        // every domain, most of which never object to anything.
        //
        // So the pace is ordinary until a host actually objects. Once one has
        // shown a robot check, a WAF or a 403, visits to it are spaced out for
        // the next few hours - that is where the cost buys something.
        $spacing = max(0.0, (float) config('product-images.browser_fallback.host_visit_spacing_seconds', 10));

        if ($this->reputation->isChallenged($url)) {
            $spacing = max($spacing, (float) config('product-images.browser_fallback.challenged_host_spacing_seconds', 25));
        }

        // A visit exactly every four seconds is its own pattern. The jitter is
        // small and one-sided: it only ever waits longer, never less.
        $spacing += $spacing > 0 ? random_int(0, 40) / 10 : 0;
        $key = 'gallery-browser-last-visit:'.$host;
        $previous = (float) (Cache::get($key) ?? 0);
        $wait = $spacing - (microtime(true) - $previous);

        if ($previous > 0 && $wait > 0) {
            usleep((int) round(min($spacing, $wait) * 1_000_000));
        }

        Cache::put($key, microtime(true), now()->addMinutes(30));
    }

    private function tryCompatibleDomainRecipes(
        string $url,
        int $limit,
        int $minimumSuccessCount,
        ?int $excludeRecipeId,
        ?callable $debug,
        ?int $telegramUpdateId,
        array $context,
    ): array {
        $bestImages = [];
        $candidates = $this->recipeRouter->compatibleCandidatesForUrl(
            $url,
            $excludeRecipeId,
            self::MAX_COMPATIBLE_RECIPE_ATTEMPTS,
        );

        foreach ($candidates as $candidate) {
            $debug?->__invoke(
                'step',
                'Playwright: без LLM проверяю совместимость успешного рецепта домена с новым path.',
            );
            $result = $this->executeRecipe(
                $url,
                $candidate->recipe ?? [],
                $limit,
                $debug,
                $telegramUpdateId,
                $context,
            );
            $this->recordBrowserResult($url, $result, $telegramUpdateId, 'compatible_recipe_probe');
            $images = is_array($result['images'] ?? null) ? $result['images'] : [];
            if (count($images) > count($bestImages)) {
                $bestImages = $images;
            }

            $validation = $this->resultValidator->validate(
                $candidate->recipe ?? [],
                $result,
                minimumSuccessCount: $minimumSuccessCount,
                countedOnThisPage: false,
            );
            $selectorsMismatched = $this->recipeSelectorsMismatchPage(
                $candidate->recipe ?? [],
                $result,
            );

            if (! $validation['passed'] || $selectorsMismatched) {
                continue;
            }

            $observedLayoutFingerprint = app(ProductPageLayoutFingerprint::class)->make(
                is_array($result['scout'] ?? null) ? $result['scout'] : [],
            );
            $updates = [
                'last_success_at' => now(),
                'last_error' => null,
            ];
            if ($observedLayoutFingerprint !== null) {
                $updates['last_observed_layout_fingerprint'] = $observedLayoutFingerprint;
            }
            $candidate->increment('success_count', 1, $updates);
            $this->recipeRouter->bindCompatiblePath($candidate, $url);
            $this->rememberConfirmedGalleryImages($images);
            $debug?->__invoke(
                'done',
                'Существующий рецепт домена подтвердился на новом path: '.count($images).' фото. Новый рецепт не создаю.',
            );

            return ['passed' => true, 'images' => $images];
        }

        return ['passed' => false, 'images' => $bestImages];
    }

    public function isConfirmedGalleryImage(string $url): bool
    {
        $key = $this->galleryImageKey($url);

        return isset($this->confirmedGalleryImages[$key])
            && ! isset($this->identityUnconfirmedImages[$key]);
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
    /**
     * Whether the browser never got a page because the server's HTTP/2 stack
     * broke the stream. Distinguished from every other navigation failure
     * because it is the one with a cheap, general remedy - the same request
     * over HTTP/1.1 - rather than a property of the site worth recording.
     *
     * @param  array<string, mixed>|null  $result
     */
    /**
     * How the operator is told why training is starting.
     *
     * "This domain has no recipe yet" was printed even when the domain had
     * several and the line above it said their compatibility had just been
     * probed. A shop the bot already knows how to open, described as one it
     * has never seen, is what makes a log stop being worth reading.
     */
    private function recipeSituationLabel(string $host, int $domainRecipes): string
    {
        return $domainRecipes === 0
            ? "Для {$host} ещё нет AI-рецепта"
            : "Рецепты {$host} ({$domainRecipes} шт.) этой странице не подошли";
    }

    private function looksLikeHttp2Failure(?array $result, string $stderr): bool
    {
        $signal = mb_strtolower(trim((string) ($result['error'] ?? '').' '.$stderr));

        return $signal !== ''
            && (str_contains($signal, 'err_http2_protocol_error')
                || str_contains($signal, 'err_spdy_protocol_error'));
    }

    private function runScript(
        string $url,
        array $recipe,
        int $limit,
        bool $scoutOnly,
        ?callable $debug,
        ?int $telegramUpdateId,
        array $context,
        bool $withoutHttp2 = false,
    ): array {
        if (! $this->available($limit, $debug)) {
            return [];
        }

        // A shop that has refused twice is answering, not failing. Visiting it
        // a third time cannot produce a gallery and does produce another entry
        // in whatever counted the first two - which is how a temporary block
        // becomes a permanent one.
        if ($this->reputation->isRefusing($url)) {
            $refusal = $this->reputation->refusal($url) ?? [];
            $host = strtolower((string) parse_url($url, PHP_URL_HOST));
            $debug?->__invoke('warning', sprintf(
                '%s отказал %d раз подряд (%s); остальные его страницы в этом поиске пропускаю, чтобы не усиливать блокировку.',
                $host,
                (int) ($refusal['count'] ?? 0),
                (string) ($refusal['reason'] ?? 'отказ'),
            ));

            return [
                'images' => [],
                'error' => 'Host refused '.(int) ($refusal['count'] ?? 0).' times in a row.',
                'failure_kind' => 'host_refusing',
            ];
        }

        // Rediscovering a server's broken HTTP/2 stack on every visit costs a
        // failed navigation each time. Once seen, start where the retry would
        // have ended up.
        $withoutHttp2 = $withoutHttp2 || $this->reputation->prefersHttp11($url);

        $script = base_path((string) config('product-images.browser_fallback.script', 'scripts/extract-product-gallery.mjs'));
        $transferDirectory = storage_path('framework/product-gallery-browser/'.Str::uuid());
        $this->pauseBetweenVisits($url);

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
                'PRODUCT_IMAGE_DISABLE_HTTP2' => $withoutHttp2 ? 'true' : 'false',
                // The panel decides, not the shell the worker happened to start in.
                'PRODUCT_IMAGE_BROWSER_HEADLESS' => $this->settings->browserHeadless() ? 'true' : 'false',
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

                if (! $withoutHttp2 && $this->looksLikeHttp2Failure(null, $error)) {
                    File::deleteDirectory($transferDirectory);

                    return $this->retryOverHttp11($url, $recipe, $limit, $scoutOnly, $debug, $telegramUpdateId, $context);
                }

                if ($this->looksLikeSilentHost($error)) {
                    return $this->recordSilentHost($url, $error, $debug);
                }

                return ['images' => [], 'error' => $error, 'failure_kind' => 'browser_process'];
            }

            $result = json_decode(trim($process->getOutput()), true);

            // A server that negotiates HTTP/2 and then breaks the stream leaves
            // Chromium with no page at all, and the site is written off in
            // seconds - hp.com went from candidate to "no suitable image" in
            // three, having never loaded. The same site serves over HTTP/1.1,
            // so the one thing worth trying is the other protocol, once.
            if (! $withoutHttp2 && $this->looksLikeHttp2Failure($result, $process->getErrorOutput())) {
                File::deleteDirectory($transferDirectory);

                return $this->retryOverHttp11($url, $recipe, $limit, $scoutOnly, $debug, $telegramUpdateId, $context);
            }

            // A connection accepted and then never answered used to be reported
            // as a browser crash, which sent the trainer looking for a fault in
            // the recipe and the operator looking for one in the code. Neither
            // was there: the shop was refusing us.
            if (is_array($result) && ($result['images'] ?? []) === [] && $this->looksLikeSilentHost((string) ($result['error'] ?? ''))) {
                return $this->recordSilentHost($url, (string) $result['error'], $debug);
            }

            if (! is_array($result)) {
                return ['images' => [], 'error' => 'Playwright returned invalid JSON.', 'failure_kind' => 'browser_protocol'];
            }

            $this->transfers->remember($result['transferred_images'] ?? [], $transferDirectory);
            unset($result['transferred_images']);

            // Read before the transfer directory is deleted below. The bytes
            // travel with the result rather than as a path, because by the time
            // the trainer looks at them the directory is long gone.
            $screenshot = is_string($result['screenshot_path'] ?? null) && is_file($result['screenshot_path'])
                ? @file_get_contents($result['screenshot_path'])
                : false;
            $result['screenshot'] = is_string($screenshot) && $screenshot !== '' ? $screenshot : null;
            unset($result['screenshot_path']);

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

            // Read from the page the browser was actually served, so a shop
            // that showed a robot check is slowed down before the next visit
            // rather than after the third one draws another.
            $this->rememberAccessChallenge($url, is_array($result['scout'] ?? null) ? $result['scout'] : []);
            $this->noteIdentityUnconfirmed($result, $debug);

            return $result;
        } catch (ProcessTimedOutException $exception) {
            // A process timeout can be DOM evaluation or browser cleanup, not
            // evidence that the remote host refused a connection.
            $recovered = BrowserGalleryCheckpoint::interrupted($transferDirectory, $exception->getMessage());
            $debug?->__invoke('warning', 'Playwright превысил лимит времени; источник можно повторить позже.');
            Log::notice('Browser product gallery extraction timed out.', [
                'host' => parse_url($url, PHP_URL_HOST),
                'error' => $exception->getMessage(),
            ]);

            return $recovered;
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

    /**
     * The one navigation failure with a cheap, general remedy.
     *
     * The downgrade is remembered against the host, so later visits start on
     * HTTP/1.1 instead of paying for the broken stream again - and the outcome
     * is reported, because "retrying over HTTP/1.1" followed by silence read
     * as though the retry had never happened.
     *
     * @param  array<string, mixed>  $recipe
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function retryOverHttp11(
        string $url,
        array $recipe,
        int $limit,
        bool $scoutOnly,
        ?callable $debug,
        ?int $telegramUpdateId,
        array $context,
    ): array {
        $this->reputation->noteHttp11Downgrade($url);
        $debug?->__invoke('warning', 'Сайт разорвал соединение по HTTP/2; повторяю один раз по HTTP/1.1.');

        $result = $this->runScript($url, $recipe, $limit, $scoutOnly, $debug, $telegramUpdateId, $context, true);

        if (trim((string) ($result['error'] ?? '')) !== '') {
            $debug?->__invoke('warning', 'По HTTP/1.1 сайт ответил так же; дело не в протоколе.');
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function recordSilentHost(string $url, string $error, ?callable $debug): array
    {
        $count = $this->reputation->noteRefusal($url, HostReputation::REFUSAL_SILENCE);
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $debug?->__invoke('warning', sprintf(
            '%s принял соединение и не ответил ни байта (отказ %d). Это не сбой браузера и не рецепт - сайт нас не пускает.',
            $host,
            $count,
        ));
        Log::notice('Product page host accepted the connection and returned nothing.', [
            'host' => $host,
            'refusals' => $count,
        ]);

        return ['images' => [], 'error' => $error, 'failure_kind' => 'host_unreachable'];
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

    /**
     * The browser navigated somewhere it could not identify.
     *
     * Recorded here, at the one place a browser result arrives, so it does not
     * matter which of the three later decisions would have confirmed these
     * frames. The run reported it; the frames carry it from now on.
     *
     * @param  array<string, mixed>  $result
     */
    private function noteIdentityUnconfirmed(array $result, ?callable $debug): void
    {
        if ((bool) data_get($result, 'diagnostics.identity_unconfirmed', false) !== true) {
            return;
        }

        $images = collect($result['images'] ?? [])
            ->filter(fn (mixed $image): bool => is_string($image) && $image !== '');

        foreach ($images as $image) {
            $this->identityUnconfirmedImages[$this->galleryImageKey($image)] = true;
        }

        $debug?->__invoke(
            'warning',
            'Часть кадров снята со страницы, которую не удалось опознать: ни она, ни карточка не публикуют '
                .'sku, canonical или og:url. Кадры оставляю, но подтверждёнными галерейными они не считаются - '
                .'их проверит Vision наравне с остальными.',
        );
    }

    /**
     * @param  array<int, string>  $images
     */
    private function rememberConfirmedGalleryImages(array $images): void
    {
        foreach ($images as $image) {
            if (! is_string($image) || $image === '') {
                continue;
            }

            $key = $this->galleryImageKey($image);

            // The badge is what lets a frame skip ahead of the checks that
            // exist to catch a wrong product. A frame from a page we could not
            // identify is exactly the frame those checks are for, so it is kept
            // and sent through them rather than trusted past them. Unknown is
            // not a soft yes.
            if (isset($this->identityUnconfirmedImages[$key])) {
                continue;
            }

            $this->confirmedGalleryImages[$key] = true;
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
