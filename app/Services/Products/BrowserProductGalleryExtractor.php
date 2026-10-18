<?php

namespace App\Services\Products;

use App\Models\ProductGalleryRecipe;
use App\Services\Ai\AiSettings;
use App\Services\Ai\ProductSearchTimeBudget;
use App\Services\GalleryRecipes\RecipeReplayFailure;
use App\Services\Photos\ImageUrls;
use App\Services\Playwright\BrowserRunner;
use App\Services\Playwright\BrowserRunRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class BrowserProductGalleryExtractor
{
    private const MAX_COMPATIBLE_RECIPE_ATTEMPTS = 3;

    /** Failed reuse-only attempts live only in the owning resolver's job scope. */
    private array $failedReuseAttempts = [];

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

    private array $lastResultImages = [];

    private array $lastScout = [];

    private bool $hasObservation = false;

    private bool $lastResultIdentityUnconfirmed = false;

    /**
     * The specification-oriented page text Playwright's own scout already
     * captured for the most recent extract() call - a same-call side
     * channel (reset at the top of extract(), read immediately after by the
     * caller) rather than a public return value, because extract()'s own
     * return type is the plain image URL list every existing caller already
     * depends on.
     */
    private ?string $lastSpecificationText = null;

    /** Same-call side channel as lastSpecificationText() - the page actually
     * settled on after navigation/redirects, for a caller that needs to
     * confirm it never left the host it was given. */
    private ?string $lastProductPageUrl = null;

    /** @var array<int, array{selector: string, text: string}> */
    private array $lastObservedControls = [];

    /** @var ?array{selector: string, clicked: bool, changed: bool, selector_missing: bool, navigated_away: bool, navigated_away_reason: ?string} */
    private ?array $lastClickOutcome = null;

    /** What the page said it is - canonical link, og:url, JSON-LD Product
     * fields - the same evidence-based, dictionary-free identity used to
     * decide whether an in-page click stayed on the product. Null when the
     * page published none of it, which is unknown, not "same product". */
    private ?array $lastProductIdentity = null;

    /** Server assessment for this execution only, never a recipe property. */
    private ?array $lastSourceIdentityAssessment = null;

    public function __construct(
        private readonly AiSettings $settings,
        private readonly ProductSearchTimeBudget $timeBudget,
        private readonly ProductGalleryRecipeResultValidator $resultValidator,
        private readonly ProductSourceAttemptRecorder $attempts,
        private readonly BrowserRunner $runner,
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
        $this->resetObservation();

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
            $attemptKey = $this->reuseAttemptKey($url, $recipe, $limit, $telegramUpdateId, $context);
            $previousAttempt = $activeRecipeOnly ? null : $this->takeFailedReuseAttempt($attemptKey);
            $debug?->__invoke('step', $previousAttempt === null
                ? 'Playwright: применяю AI-рецепт для '.$host.'.'
                : 'Использую сохранённый результат проверки рецепта для '.$host.'; передаю его на диагностику.');
            $executionStartedAt = hrtime(true);
            $result = $previousAttempt
                ?? $this->executeRecipe($url, $recipe->recipe ?? [], $limit, $debug, $telegramUpdateId, $context);
            $executionMs = (int) round((hrtime(true) - $executionStartedAt) / 1_000_000);
            $observedLayoutFingerprint = app(ProductPageLayoutFingerprint::class)->make(
                is_array($result['scout'] ?? null) ? $result['scout'] : [],
            );
            if ($previousAttempt === null && $observedLayoutFingerprint !== null) {
                $recipe->update(['last_observed_layout_fingerprint' => $observedLayoutFingerprint]);
            }
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
            // A browser crash, an interrupted download or a technical
            // navigation failure says nothing about whether the recipe's
            // selectors still fit this page - it means the attempt to find
            // out did not finish. Counting it the same as a genuine mismatch
            // let one bad network moment count against a recipe that had
            // done nothing wrong, on the very same counter that decides
            // whether Playwright stays enabled for the whole domain.
            $failure = RecipeReplayFailure::inspect($result, $selectorsMismatched, $validation);
            $technicalFailureKind = $failure->kind;
            $executionFailed = $failure->interrupted;
            // An empty selector snapshot from an unfinished page load is not
            // proof that the store changed its layout.
            if ($executionFailed) {
                $selectorsMismatched = false;
                $this->rememberPartialGalleryImages($images);
            }
            if ($previousAttempt === null) {
                $this->recordBrowserResult($url, $result, $telegramUpdateId, 'active_recipe', $executionMs,
                    $this->recipeRunOutcome($recipe, $validation, $selectorsMismatched, $executionFailed));
            }

            if ($validation['passed'] && ! $selectorsMismatched && ! $failure->qualityUnconfirmed) {
                if (($validation['traversal_confirmed'] ?? true) === false) {
                    $this->rememberPartialGalleryImages($images);
                    $debug?->__invoke('step', 'Рецепт собрал '.count($images).' фото; полный обход текущей галереи не доказан. Сохраняю частичный набор для проверки.');

                    return $images;
                }
                app(ProductGalleryRecipeProof::class)->remember($recipe, $url, $validation, $telegramUpdateId);
                $this->rememberConfirmedGalleryImages($images);
                $recipe->increment('success_count', 1, [
                    'last_success_at' => now(),
                    'last_error' => null,
                ]);
                $debug?->__invoke('done', 'Playwright получил фото: '.count($images).'.');

                return $previousRecipeImages ?? $images;
            }

            if ($failure->qualityUnconfirmed) {
                $this->rememberPartialGalleryImages($images);
                $debug?->__invoke('warning', ($validation['unexplored_media_control'] ?? null) !== null
                    ? 'Кадры собраны, но наблюдаемое увеличение ещё не проверено. Передаю агенту состояние галереи для исследования.'
                    : 'Галерея пройдена, но часть кадров найдена только в малом размере: пригодных '
                        .$validation['extracted'].' из '.$validation['collected'].'. Передаю это на диагностику; рецепт не считаю сломанным.');
            } elseif ($selectorsMismatched) {
                $layoutChanged = $recipe->layout_fingerprint !== null
                    && $observedLayoutFingerprint !== null
                    && $recipe->layout_fingerprint !== $observedLayoutFingerprint;
                $debug?->__invoke(
                    'warning',
                    "Сохранённый рецепт для {$host} не нашёл ни одного своего селектора на этой странице"
                        .($layoutChanged ? ' (layout fingerprint изменился)' : '')
                        .'; причина пока неизвестна: проверяю страницу, не считаю рецепт сломанным.',
                );
            } elseif ($executionFailed) {
                $debug?->__invoke(
                    'warning',
                    'Технический сбой при исполнении рецепта для '.$host.' ('.$technicalFailureKind
                        .'); найденное сохраняю, счётчик совместимости не трогаю.',
                );
            } else {
                $debug?->__invoke(
                    'warning',
                    'Галерея неполная: получено '.$validation['extracted']
                        .' из '.$validation['expected'].'. Рецепт не засчитан.',
                );
            }

            // A missing product, consent page or loading screen may have no
            // matching selectors either. Preserve the observation, not a
            // premature verdict about the saved instruction or whole domain.
            if ($previousAttempt === null && ! $failure->qualityUnconfirmed && ! $failure->mayAffectCompatibility()) {
                $recipe->update([
                    'last_failure_at' => now(),
                    'last_failure_kind' => $technicalFailureKind ?: 'execution_interrupted',
                    'last_error' => ($failure->sourceUnconfirmed
                        ? 'No recipe selectors matched; page suitability and layout need diagnosis. '
                        : 'Execution did not complete: '.($technicalFailureKind ?: 'interrupted').'. ')
                        .'Not counted as a recipe mismatch.',
                ]);
            } elseif ($previousAttempt === null && ! $failure->qualityUnconfirmed) {
                $recipe->increment('failure_count', 1, [
                    'last_failure_at' => now(),
                    'last_error' => $selectorsMismatched
                        ? 'Recipe selectors matched nothing on this page (layout mismatch).'
                        : $validation['reason'],
                ]);
            }

            if ($activeRecipeOnly) {
                $this->rememberFailedReuseAttempt($url, $recipe, $result, $limit, $telegramUpdateId, $context);
            }

            $compatibleAttempt = ($executionFailed || $failure->qualityUnconfirmed) ? ['passed' => false, 'images' => []] : $this->tryCompatibleDomainRecipes(
                $url,
                $limit,
                $minimumSuccessCount,
                $recipe->id,
                $debug,
                $telegramUpdateId,
                $context,
                $activeRecipeOnly,
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
                    'Быстрая проверка готового рецепта для '.$host.' не дала окончательного результата; сохраняю найденные кадры и диагностику без обучения на этом проходе.',
                );

                return $previousRecipeImages ?? $images;
            }

            $debug?->__invoke('warning', $executionFailed
                ? 'Исполнение оборвалось; передаю агенту причину и найденные кадры для выбора повтора или исправления.'
                : ($failure->sourceUnconfirmed || $failure->qualityUnconfirmed
                    ? 'Передаю агенту страницу и результат исполнения: нужно выяснить причину, прежде чем менять рецепт.'
                    : 'Сохранённый рецепт перестал давать галерею; отправляю его агенту на ремонт.'));
            $repairFrom = $failure->qualityUnconfirmed ? $this->qualityDiagnosisContext($recipe, $result, $validation) : [
                'mode' => $failure->recoveryMode(),
                'failure_kind' => $technicalFailureKind,
                'recipe' => $recipe->recipe ?? [],
                'reason' => match (true) {
                    $selectorsMismatched => 'None of the recipe selectors matched. This alone does not prove a broken recipe: '
                        .'the product may be unavailable, the page may be incomplete, or the layout may differ. '
                        .'Inspect the observed page before deciding whether to retry, leave this source, or repair the recipe.',
                    // Told plainly rather than folded into the same wording a
                    // genuine mismatch gets: this recipe is not accused of
                    // anything here, only asked to look at what happened and
                    // decide for itself whether a plain retry or an actual
                    // change is warranted.
                    $executionFailed => 'Execution did not complete ('.$technicalFailureKind
                        .') before the gallery could be verified - this is not evidence the recipe itself is '
                        .'wrong. Decide from the diagnostics/action_trace below whether the same plan is likely '
                        .'to work on a retry or a real change is needed.',
                    default => $validation['reason'] ?? 'unknown',
                },
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
                $activeRecipeOnly,
            );
            if ($compatibleAttempt['passed']) {
                return $compatibleAttempt['images'];
            }
            $previousRecipeImages = $compatibleAttempt['images'];
            $repairFrom = $compatibleAttempt['diagnosis'] ?? [];

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
            ($repairFrom['mode'] ?? null) === 'execution_recovery' ? 'execution_recovery' : ($recipe ? 'automatic_failure' : 'initial'),
            $debug,
            telegramUpdateId: $telegramUpdateId,
            context: $context,
            forceInteractive: $forceInteractive,
            previousRecipeImages: $previousRecipeImages,
            repairFrom: $repairFrom,
            onBrowserObservation: fn (array $observation) => $this->importObservation($observation),
        );

        $trainedRecipe = $this->recipeRouter->exactRecipeForUrl($url)
            ?? $this->recipeRouter->recipeForUrl($url);
        $latestVersion = $trainedRecipe?->versions()->latest('id')->first();

        if (in_array($latestVersion?->status, ['partial', 'deferred', 'interrupted', 'rejected'], true)) {
            // An older active recipe must not lend its trust to frames from
            // an unfinished or rejected replacement. Provisional frames still
            // need verification, even when its current-page execution passed.
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
        bool $activeRecipeOnly = false,
    ): array {
        $bestImages = [];
        $diagnosis = [];
        $candidates = $this->recipeRouter->compatibleCandidatesForUrl(
            $url,
            $excludeRecipeId,
            self::MAX_COMPATIBLE_RECIPE_ATTEMPTS,
        );

        foreach ($candidates->values() as $probeIndex => $candidate) {
            $attemptKey = $this->reuseAttemptKey($url, $candidate, $limit, $telegramUpdateId, $context);
            $previousAttempt = $activeRecipeOnly ? null : $this->takeFailedReuseAttempt($attemptKey);
            $debug?->__invoke(
                'step',
                $previousAttempt === null
                    ? 'Playwright: без LLM проверяю совместимость успешного рецепта домена с новым path.'
                    : 'Использую сохранённый результат проверки совместимости рецепта с этим path.',
            );
            $executionStartedAt = hrtime(true);
            $result = $previousAttempt ?? $this->executeRecipe(
                $url,
                $candidate->recipe ?? [],
                $limit,
                $debug,
                $telegramUpdateId,
                $context,
            );
            $executionMs = (int) round((hrtime(true) - $executionStartedAt) / 1_000_000);
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
            if ($previousAttempt === null) {
                // How long a probe takes and how often it fits is what decides
                // whether MAX_COMPATIBLE_RECIPE_ATTEMPTS is right; the archived
                // run had no measurement of either.
                $this->recordBrowserResult($url, $result, $telegramUpdateId, 'compatible_recipe_probe', $executionMs, [
                    ...$this->recipeRunOutcome($candidate, $validation, $selectorsMismatched, $this->executionWasInterrupted($result)),
                    'probe_rank' => $probeIndex + 1,
                    'probe_candidates' => $candidates->count(),
                ]);
            }

            if (! $validation['passed'] || $selectorsMismatched || ($validation['needs_source_quality_diagnosis'] ?? false)) {
                if (($validation['needs_source_quality_diagnosis'] ?? false) && ! $selectorsMismatched) {
                    $this->rememberPartialGalleryImages($images);
                    if (count($images) >= count($bestImages)) {
                        $diagnosis = $this->qualityDiagnosisContext($candidate, $result, $validation);
                    }
                }
                if ($activeRecipeOnly) {
                    $this->rememberFailedReuseAttempt($url, $candidate, $result, $limit, $telegramUpdateId, $context);
                }

                continue;
            }

            if (($validation['traversal_confirmed'] ?? true) === false) {
                $this->rememberPartialGalleryImages($images);
                $debug?->__invoke('step', 'Совместимый рецепт собрал фото без доказанного полного обхода; сохраняю частичный набор для проверки.');

                return ['passed' => true, 'images' => $images];
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
            app(ProductGalleryRecipeProof::class)->remember($candidate, $url, $validation, $telegramUpdateId);
            $this->recipeRouter->bindCompatiblePath($candidate, $url);
            $this->rememberConfirmedGalleryImages($images);
            $debug?->__invoke(
                'done',
                'Существующий рецепт домена подтвердился на новом path: '.count($images).' фото. Новый рецепт не создаю.',
            );

            return ['passed' => true, 'images' => $images];
        }

        return ['passed' => false, 'images' => $bestImages, 'diagnosis' => $diagnosis];
    }

    private function qualityDiagnosisContext(ProductGalleryRecipe $recipe, array $result, array $validation): array
    {
        return [
            'mode' => 'source_diagnosis',
            'failure_kind' => 'source_quality_unconfirmed',
            'recipe' => $recipe->recipe ?? [],
            'unexplored_media_control' => $validation['unexplored_media_control'] ?? null,
            'reason' => $validation['unexplored_media_control']['reason'] ?? ('Traversal collected '.$validation['collected'].' frames, but only '.$validation['extracted']
                .' meet the image requirements (requested minimum '.$validation['min_success_count'].'). '
                .'Some observed frames were decoded only at insufficient dimensions. '
                .'This does not prove larger originals are unavailable or that the recipe is incompatible.'),
            'action_trace' => $result['action_trace'] ?? [],
            'diagnostics' => $result['diagnostics'] ?? [],
            'images' => $result['images'] ?? [],
        ];
    }

    /**
     * One shape for "did this stored recipe fit this page" on both the active
     * recipe run and a probe of another recipe of the same domain.
     *
     * @param  array<string, mixed>  $validation
     * @return array<string, mixed>
     */
    private function recipeRunOutcome(
        ProductGalleryRecipe $recipe,
        array $validation,
        bool $selectorsMismatched,
        bool $executionInterrupted,
    ): array {
        return [
            'recipe_id' => $recipe->id,
            'fits' => ($validation['passed'] ?? false) && ! $selectorsMismatched && ! $executionInterrupted
                && ! ($validation['needs_source_quality_diagnosis'] ?? false),
            'validation_reason' => $validation['reason'] ?? null,
            'traversal_confirmed' => ($validation['traversal_confirmed'] ?? true) && ($validation['passed'] ?? false),
            'selectors_mismatched' => $selectorsMismatched,
            'execution_interrupted' => $executionInterrupted,
            'failure_attribution' => $executionInterrupted ? 'execution'
                : ($selectorsMismatched ? 'source_unconfirmed'
                    : (($validation['needs_source_quality_diagnosis'] ?? false) ? 'source_quality_unconfirmed'
                        : (($validation['passed'] ?? false) ? null : 'traversal'))),
        ];
    }

    private function reuseAttemptKey(
        string $url,
        ProductGalleryRecipe $recipe,
        int $limit,
        ?int $telegramUpdateId,
        array $context,
    ): string {
        return hash('sha256', serialize([
            $url, $recipe->id, $recipe->recipe,
            $limit, $telegramUpdateId, $context,
        ]));
    }

    private function rememberFailedReuseAttempt(
        string $url,
        ProductGalleryRecipe $recipe,
        array $result,
        int $limit,
        ?int $telegramUpdateId,
        array $context,
    ): void {
        // The complete recipe body is the executable revision. Health updates
        // from another product must not turn this same plan into a new attempt.
        $key = $this->reuseAttemptKey($url, $recipe, $limit, $telegramUpdateId, $context);
        $this->failedReuseAttempts[$key] = [
            'result' => $result,
            'observation' => $this->exportObservation(),
        ];
    }

    private function takeFailedReuseAttempt(string $key): ?array
    {
        $attempt = $this->failedReuseAttempts[$key] ?? null;
        unset($this->failedReuseAttempts[$key]);
        if ($attempt === null) {
            return null;
        }

        // The full queue pass diagnoses the real failed execution from the
        // reuse-only pass. Explicit executeRecipe() calls by the trainer remain
        // fresh browser runs, and successful executions are never memoized.
        $this->importObservation($attempt['observation']);

        return $attempt['result'];
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

    public function lastSpecificationText(): ?string
    {
        return $this->lastSpecificationText;
    }

    /** The page actually settled on after navigation/redirects for the most
     * recent scout()/scoutAfterOpening() call - null when unavailable. */
    public function lastProductPageUrl(): ?string
    {
        return $this->lastProductPageUrl;
    }

    /**
     * Visible, structurally-stable clickable/expandable controls observed on
     * the most recent scout()/scoutAfterOpening() page - a generic
     * definition (button/[role=tab]/[role=button]/[aria-expanded]/summary/
     * a[href^="#"]), never a word list, so a caller can offer an agent real
     * selectors to choose from instead of asking it to invent one from text.
     *
     * @return array<int, array{selector: string, text: string}>
     */
    public function lastObservedControls(): array
    {
        return $this->lastObservedControls;
    }

    /**
     * What actually happened to the one selector scoutAfterOpening() was
     * asked to click - null when scout() (no selector) was the last call.
     *
     * @return ?array{selector: string, clicked: bool, changed: bool, selector_missing: bool, navigated_away: bool, navigated_away_reason: ?string}
     */
    public function lastClickOutcome(): ?array
    {
        return $this->lastClickOutcome;
    }

    /** @return ?array<string, mixed> */
    public function lastProductIdentity(): ?array
    {
        return $this->lastProductIdentity;
    }

    /** @param array<string, mixed> $assessment */
    public function rememberSourceIdentityAssessment(array $assessment): void
    {
        $this->lastSourceIdentityAssessment = $assessment;
    }

    /** @return array<string, mixed>|null */
    public function lastSourceIdentityAssessment(): ?array
    {
        return $this->lastSourceIdentityAssessment;
    }

    /** Observation belongs to one actual execution, never to its canary. */
    public function exportObservation(): array
    {
        if (! $this->hasObservation) {
            return [];
        }

        return [
            'product_page_url' => $this->lastProductPageUrl,
            'product_identity' => $this->lastProductIdentity,
            'specification_text' => $this->lastSpecificationText,
            'observed_controls' => $this->lastObservedControls,
            'click_outcome' => $this->lastClickOutcome,
            'source_identity_assessment' => $this->lastSourceIdentityAssessment,
            'scout' => $this->lastScout,
            'images' => $this->lastResultImages,
            'identity_unconfirmed' => $this->lastResultIdentityUnconfirmed,
        ];
    }

    private function importObservation(array $observation): void
    {
        if ($observation === []) {
            return;
        }

        $this->lastProductPageUrl = $observation['product_page_url'];
        $this->lastProductIdentity = $observation['product_identity'];
        $this->lastSpecificationText = $observation['specification_text'];
        $this->lastObservedControls = $observation['observed_controls'];
        $this->lastClickOutcome = $observation['click_outcome'];
        $this->lastSourceIdentityAssessment = $observation['source_identity_assessment'];
        $this->lastScout = $observation['scout'];
        $this->hasObservation = true;
        $this->noteIdentityUnconfirmed([
            'images' => $observation['images'],
            'diagnostics' => ['identity_unconfirmed' => $observation['identity_unconfirmed']],
        ], null);
    }

    private function resetObservation(): void
    {
        $this->lastSpecificationText = null;
        $this->lastProductPageUrl = null;
        $this->lastObservedControls = [];
        $this->lastClickOutcome = null;
        $this->lastProductIdentity = null;
        $this->lastSourceIdentityAssessment = null;
        $this->lastResultImages = [];
        $this->lastScout = [];
        $this->lastResultIdentityUnconfirmed = false;
        $this->hasObservation = false;
    }

    /** @return array<string, mixed> */
    /**
     * Card assembly may run past the search deadline (see ProductCardAssembly);
     * its page reads get its window, not the search's spent one - which left
     * them one second.
     */
    private function processTimeoutSeconds(bool $scoutOnly, ?int $telegramUpdateId, array $context): int
    {
        $configuredTimeout = $scoutOnly
            ? $this->settings->browserScoutTimeoutSeconds()
            : $this->settings->browserTimeoutSeconds();

        return ($context['card_assembly'] ?? false) === true
            ? $this->timeBudget->assemblyTimeoutFor($telegramUpdateId, $configuredTimeout)
            : $this->timeBudget->timeoutFor($telegramUpdateId, $configuredTimeout);
    }

    public function scout(
        string $url,
        ?callable $debug = null,
        ?int $telegramUpdateId = null,
        array $context = [],
        ?string $confineNavigationToHost = null,
    ): array {
        $this->resetObservation();

        return $this->runScript($url, [], 20, true, $debug, $telegramUpdateId, $context, confineNavigationToHost: $confineNavigationToHost);
    }

    /**
     * Same as scout(), but clicks one selector on the product page first -
     * e.g. a "характеристики"/specifications tab that reveals content
     * in-page rather than at a different URL - before reading
     * lastSpecificationText(). Reuses pre_click_selectors, the same
     * already-safe declarative mechanism a trained gallery recipe uses to
     * clear a consent wall or open a viewer before collection: Playwright's
     * own locator only ever finds and clicks an existing element on THIS
     * product's page, and clickAndWaitForGalleryChange() already refuses any
     * navigation that would leave it (see isAllowedProductNavigation() in
     * the script) - so an unhelpful or malformed selector just matches
     * nothing rather than doing anything unsafe.
     *
     * @return array<string, mixed>
     */
    public function scoutAfterOpening(
        string $url,
        string $selector,
        ?callable $debug = null,
        ?int $telegramUpdateId = null,
        array $context = [],
        ?string $confineNavigationToHost = null,
    ): array {
        $this->resetObservation();

        return $this->runScript(
            $url,
            ['pre_click_selectors' => [$selector]],
            20,
            true,
            $debug,
            $telegramUpdateId,
            $context,
            confineNavigationToHost: $confineNavigationToHost,
        );
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
        $this->resetObservation();
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
        ?string $confineNavigationToHost = null,
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

        $this->pauseBetweenVisits($url);
        try {
            $execution = $this->runner->run(new BrowserRunRequest(
                $url, $recipe, $limit, $scoutOnly,
                $this->processTimeoutSeconds($scoutOnly, $telegramUpdateId, $context),
                $context, $withoutHttp2, $confineNavigationToHost,
            ), $debug);
            $result = $execution->payload;
            if (! $withoutHttp2 && $this->looksLikeHttp2Failure($result, $execution->stderr)) {
                return $this->retryOverHttp11($url, $recipe, $limit, $scoutOnly, $debug, $telegramUpdateId, $context, $confineNavigationToHost);
            }
            // Only navigation silence is a host refusal. A process timeout is not.
            if (($result['failure_kind'] ?? '') !== 'browser_timeout'
                && ($result['images'] ?? []) === [] && $this->looksLikeSilentHost((string) ($result['error'] ?? ''))) {
                return $this->recordSilentHost($url, (string) $result['error'], $debug);
            }
            if (! $execution->hasObservation) {
                return $result;
            }
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
            $scout = is_array($result['scout'] ?? null) ? $result['scout'] : [];
            $this->hasObservation = true;
            $this->lastScout = $scout;
            $this->rememberAccessChallenge($url, $scout);
            $this->noteIdentityUnconfirmed($result, $debug);
            $this->lastSpecificationText = is_string($scout['specification_text'] ?? null)
                ? $scout['specification_text']
                : null;
            $this->lastProductPageUrl = is_string($result['product_page_url'] ?? null)
                ? $result['product_page_url']
                : null;
            $this->lastProductIdentity = is_array($result['product_identity'] ?? null)
                ? $result['product_identity']
                : null;
            $this->lastObservedControls = collect($scout['observed_controls'] ?? [])
                ->filter(fn (mixed $control): bool => is_array($control)
                    && is_string($control['selector'] ?? null) && $control['selector'] !== ''
                    && is_string($control['text'] ?? null) && $control['text'] !== '')
                ->map(fn (array $control): array => ['selector' => $control['selector'], 'text' => $control['text']])
                ->values()
                ->all();
            // At most one entry: scoutAfterOpening() ever asks for a single
            // selector, so the last (only) 'pre_click' trace entry is this
            // call's own outcome, not a leftover from an earlier round.
            $click = collect($result['action_trace'] ?? [])
                ->last(fn (mixed $action): bool => is_array($action) && ($action['phase'] ?? null) === 'pre_click');
            $this->lastClickOutcome = is_array($click) ? [
                'selector' => (string) ($click['selector'] ?? ''),
                'clicked' => (bool) ($click['clicked'] ?? false),
                'changed' => (bool) ($click['changed'] ?? false),
                'selector_missing' => (bool) ($click['selector_missing'] ?? false),
                'navigated_away' => (bool) ($click['navigated_away'] ?? false),
                'navigated_away_reason' => is_string($click['navigated_away_reason'] ?? null)
                    ? $click['navigated_away_reason']
                    : null,
            ] : null;

            return $result;
        } catch (Throwable $exception) {
            $debug?->__invoke('error', 'Playwright: '.$exception->getMessage());
            Log::debug('Browser product gallery extractor was unavailable.', [
                'host' => parse_url($url, PHP_URL_HOST), 'error' => $exception->getMessage(),
            ]);

            return ['images' => [], 'error' => $exception->getMessage(), 'failure_kind' => 'browser_unavailable'];
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
        ?string $confineNavigationToHost = null,
    ): array {
        $this->reputation->noteHttp11Downgrade($url);
        $debug?->__invoke('warning', 'Сайт разорвал соединение по HTTP/2; повторяю один раз по HTTP/1.1.');

        $result = $this->runScript($url, $recipe, $limit, $scoutOnly, $debug, $telegramUpdateId, $context, true, $confineNavigationToHost);

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

    /**
     * @param  array<string, mixed>  $outcome  whether the stored recipe fitted this page, when known
     */
    private function recordBrowserResult(
        string $url,
        array $result,
        ?int $telegramUpdateId,
        string $phase,
        ?int $durationMs = null,
        array $outcome = [],
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
                ...($outcome === [] ? [] : ['outcome' => $outcome]),
            ],
            'duration_ms' => $durationMs,
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
     * frames. A subsequent actual execution can replace this evidence;
     * merely asking to confirm the same URLs cannot.
     *
     * @param  array<string, mixed>  $result
     */
    private function noteIdentityUnconfirmed(array $result, ?callable $debug): void
    {
        $images = collect($result['images'] ?? [])
            ->filter(fn (mixed $image): bool => is_string($image) && $image !== '');
        $this->lastResultImages = $images->values()->all();
        $this->lastResultIdentityUnconfirmed = (bool) data_get($result, 'diagnostics.identity_unconfirmed', false);

        foreach ($images as $image) {
            $key = $this->galleryImageKey($image);
            // A later execution may prove or disprove what the earlier one
            // could not. Keep other reserve assets, but never borrow their
            // previous verdict for the frames observed in this execution.
            unset($this->confirmedGalleryImages[$key], $this->partialGalleryImages[$key], $this->identityUnconfirmedImages[$key]);
            if ($this->lastResultIdentityUnconfirmed) {
                $this->identityUnconfirmedImages[$key] = true;
            }
        }

        if (! $this->lastResultIdentityUnconfirmed) {
            return;
        }

        $debug?->__invoke(
            'warning',
            'После перехода не хватило сопоставимых данных, чтобы подтвердить тот же товар. '
                .'Кадры оставляю, но подтверждёнными галерейными они не считаются - '
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

            unset($this->partialGalleryImages[$key]);
            $this->confirmedGalleryImages[$key] = true;
        }
    }

    /** @param array<int, string> $images */
    private function rememberPartialGalleryImages(array $images): void
    {
        foreach ($images as $image) {
            if (is_string($image) && $image !== '') {
                $key = $this->galleryImageKey($image);
                unset($this->confirmedGalleryImages[$key]);
                $this->partialGalleryImages[$key] = true;
            }
        }
    }

    /**
     * ProductImageResolver::download() looks these up by an already-
     * normalized URL (its caller runs every candidate through
     * ImageUrls::normalizeCandidateUrl() first), but Playwright
     * returns the raw URLs it saw on the page - hashing both sides through
     * the same normalization keeps a confirmed/partial gallery photo from
     * silently losing that flag just because its query string got rewritten
     * (Scene7 wid/hei upgraded, a $PRESET$ modifier stripped, ...) between
     * when it was remembered and when it's looked up.
     */
    private function galleryImageKey(string $url): string
    {
        return hash('sha256', ImageUrls::imageAssetKey($url));
    }

    private function executionWasInterrupted(array $result): bool
    {
        return RecipeReplayFailure::inspect($result, false)->interrupted;
    }

    /**
     * When applying a cached recipe, the runner only sends the recipe's own
     * collect/thumbnail selectors to the browser (see extract-product-gallery.mjs's
     * strictRecipe mode) - no generic fallback selectors are mixed in. So if
     * learned_recipe comes back with both lists empty while the recipe itself
     * configured at least one selector, every single one of them matched zero
     * elements on this exact page. That may be a different layout, an
     * unavailable product or a loading/interstitial page; this observation
     * alone does not attribute the failure to the recipe.
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
                ->unique(fn (string $url): string => ImageUrls::imageAssetKey($url))
                ->count();
            $recipeDomCount = collect(data_get($result, 'diagnostics.validated_image_evidence', []))
                ->filter(fn (mixed $item): bool => is_array($item)
                    && ($item['source'] ?? null) === 'recipe_dom'
                    && is_string($item['url'] ?? null)
                    && $item['url'] !== '')
                ->pluck('url')
                ->unique(fn (string $url): string => ImageUrls::imageAssetKey($url))
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
