<?php

namespace App\Services\Products;

use App\Ai\Agents\ProductGalleryPreflightAgent;
use App\Ai\Agents\ProductGalleryRecipeTrainerAgent;
use App\Exceptions\InvalidGalleryRecipeException;
use App\Models\AiRun;
use App\Models\ProductGalleryRecipe;
use App\Models\ProductGalleryRecipeVersion;
use App\Models\ProductSourceDomain;
use App\Models\TelegramUpdate;
use App\Services\Ai\AiSettings;
use App\Services\Ai\OpenAiHeavyOperationGate;
use App\Services\Ai\ProductSearchCostBudget;
use App\Services\Ai\ProductSearchTimeBudget;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class ProductGalleryRecipeTrainer
{
    public function __construct(
        private readonly BrowserProductGalleryExtractor $browser,
        private readonly AiSettings $settings,
        private readonly ProductSearchTimeBudget $timeBudget,
        private readonly ProductSearchCostBudget $costBudget,
        private readonly ProductGalleryRecipeResultValidator $resultValidator,
        private readonly ProductSourceAttemptRecorder $attempts,
        private readonly ProductSourcePageRules $pageRules,
    ) {}

    public function train(
        string $url,
        string $trigger = 'automatic',
        ?callable $debug = null,
        bool $force = false,
        ?int $telegramUpdateId = null,
        array $context = [],
        bool $forceInteractive = false,
        ?array $previousRecipeImages = null,
        ?string $userHint = null,
    ): array {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $categorySlug = trim((string) ($context['category_slug'] ?? ''));
        $categorySlug = $categorySlug !== '' ? $categorySlug : null;
        // Only used to attribute the trainer agent's own write-tool calls
        // (see AbandonGalleryTrainingAttempt/FlagDomainRecipeNote) in the
        // ai_operations audit trail; null whenever this run has no Telegram
        // update at all (e.g. an admin-triggered TrainProductGalleryRecipe).
        $update = $telegramUpdateId ? TelegramUpdate::query()->find($telegramUpdateId) : null;

        if ($host === '') {
            return [];
        }

        if (! $this->timeBudget->canStart($telegramUpdateId, 30)) {
            $debug?->__invoke('warning', 'Резерв времени достигнут: обучение Playwright-рецепта пропущено, завершаю текущий результат.');

            return [];
        }

        $lock = Cache::lock('gallery-recipe-training:'.sha1($host), 360);

        if (! $lock->get()) {
            $debug?->__invoke('warning', "AI-тренер {$host} уже запущен другим воркером.");

            return [];
        }

        $failureKind = 'unknown';
        $failureRecorded = false;

        try {
            $recipe = ProductGalleryRecipe::query()->firstOrCreate(
                ['domain' => $host, 'path_pattern' => '*'],
                ['status' => 'learning'],
            );
            $domainSettings = ProductSourceDomain::query()->firstOrCreate([
                'domain' => ProductSourcePriority::host($url),
            ]);
            $domainHint = is_string($domainSettings->agent_hint) && trim($domainSettings->agent_hint) !== ''
                ? mb_substr(trim($domainSettings->agent_hint), 0, 4000)
                : null;
            $autoDomainHint = is_string($domainSettings->auto_agent_hint) && trim($domainSettings->auto_agent_hint) !== ''
                ? mb_substr(trim($domainSettings->auto_agent_hint), 0, 2000)
                : null;

            if ($recipe->status === 'disabled' && ! $force) {
                $debug?->__invoke('warning', "Playwright для {$host} отключён после подтверждённой CAPTCHA/WAF.");

                return [];
            }

            if (! $force && $recipe->retry_after?->isFuture()) {
                $debug?->__invoke(
                    'warning',
                    "AI-тренер {$host}: следующая безопасная попытка после {$recipe->retry_after->format('H:i d.m')}.",
                );

                return [];
            }

            $provider = $this->settings->providerFor('gallery_recipe_training');
            $model = $this->settings->modelFor('gallery_recipe_training');
            $version = ProductGalleryRecipeVersion::query()->create([
                'product_gallery_recipe_id' => $recipe->id,
                'domain' => $host,
                'product_url' => $url,
                'trigger' => $trigger,
                'status' => 'scouting',
                'provider' => $provider,
                'model' => $model,
                'previous_recipe' => $recipe->recipe,
            ]);

            if ($userHint !== null && trim($userHint) !== '') {
                $debug?->__invoke('step', 'Подсказка оператора учтена: '.mb_substr(trim($userHint), 0, 300));
            }
            if ($domainHint !== null) {
                $debug?->__invoke('step', "Постоянная подсказка домена {$host} передана AI-тренеру.");
            }
            if ($autoDomainHint !== null) {
                $debug?->__invoke('step', "AI-наблюдения домена {$host} переданы как неподтверждённая подсказка.");
            }

            $debug?->__invoke('step', "AI-тренер: Playwright собирает DOM, интерактивные элементы и сетевые изображения {$host}.");
            $scout = $this->browser->scout($url, $debug, $telegramUpdateId, $context);
            $pageScout = is_array($scout['scout'] ?? null) ? $scout['scout'] : [];
            $layoutFingerprint = app(ProductPageLayoutFingerprint::class)->make($pageScout);
            // Persist even a rejected scout: an empty fragment list can still
            // contain the exact Gallery/Media control needed to diagnose the
            // page, and failed versions must remain auditable in Filament.
            $version->update(['scout' => $pageScout]);

            if (($pageScout['rate_limited'] ?? false) === true) {
                $failureKind = 'rate_limited';
                throw new RuntimeException('Сайт вернул HTTP 429; попытку нужно повторить позже.');
            }

            if (($pageScout['access_gate'] ?? false) === true) {
                $failureKind = 'access_gate';
                $reason = (string) ($pageScout['access_gate_reason'] ?? 'captcha_or_waf');
                throw new RuntimeException("Playwright обнаружил защитную страницу: {$reason}.");
            }

            $hasFragments = ($pageScout['fragments'] ?? []) !== [];
            $hasInteractiveGalleryControls = ($pageScout['interactive_controls'] ?? []) !== []
                || ($pageScout['action_candidates'] ?? []) !== [];

            if (! $hasFragments && ! $hasInteractiveGalleryControls) {
                $failureKind = (string) ($scout['failure_kind'] ?? (
                    ($scout['error'] ?? null) ? 'browser_unavailable' : 'dom_unusable'
                ));
                throw new RuntimeException(
                    'Playwright не получил полезную DOM-структуру страницы.'
                    .(($scout['error'] ?? null) ? ' '.$scout['error'] : '')
                );
            }

            $recipe->update([
                'status' => $recipe->status === 'active' ? 'active' : 'learning',
                'consecutive_hard_blocks' => 0,
                'hard_block_urls' => [],
                'retry_after' => null,
            ]);

            $version->update(['status' => 'training']);
            $preflight = [];
            $preflightDecision = 'train_playwright';

            if (! $forceInteractive) {
                $preflight = $this->preflight($url, $pageScout, $scout['diagnostics'] ?? [], $context, $domainHint, $autoDomainHint, $provider, $model, $version, $telegramUpdateId, $debug);
                $preflightDecision = (string) ($preflight['decision'] ?? 'no_gallery');

                // static_sufficient is an estimate from raw DOM markup (thumbnails
                // and CDN size-variants can inflate expected_image_count - see the
                // smarty.cz case: predicted 8, Vision-verified 3), not a verified
                // count. Explicit user tradeoff: when a real gallery is present,
                // train a real, reusable, Vision-verified recipe instead of
                // trusting that estimate - slower and costlier per search, but the
                // trained recipe is reused on every later visit to this domain.
                if (
                    $preflightDecision === 'static_sufficient'
                    && ($preflight['gallery_likely'] ?? false)
                    && $this->settings->galleryPreferPlaywrightFirst()
                ) {
                    $debug?->__invoke(
                        'step',
                        'Предфильтр сказал "статики достаточно", но найдена настоящая галерея - обучаю Playwright-рецепт вместо доверия оценке количества фото: '.$url,
                    );
                    $preflightDecision = 'train_playwright';
                }
            } else {
                $debug?->__invoke('step', 'AI-предфильтр пропущен: предыдущая статичная галерея дала слишком мало разных фото, принудительно кликаю по слайдеру.');
            }

            if ($preflightDecision === 'unsuitable_page') {
                $pageRule = $this->pageRules->rememberUnsuitable(
                    $url,
                    (string) ($preflight['page_kind'] ?? 'unknown'),
                    (string) ($preflight['reason'] ?? ''),
                    is_array($preflight['evidence'] ?? null) ? $preflight['evidence'] : [],
                    (float) ($preflight['confidence'] ?? 0),
                    $layoutFingerprint,
                );

                if ($pageRule) {
                    $this->attempts->record([
                        'telegram_update_id' => $telegramUpdateId,
                        'product_gallery_recipe_version_id' => $version->id,
                        'product_url' => $url,
                        'actor' => 'ai',
                        'phase' => 'gallery_preflight',
                        'action' => 'assess_page_suitability',
                        'status' => 'failed',
                        'decision' => 'unsuitable_page',
                        'output' => $preflight,
                    ]);
                    $debug?->__invoke(
                        'warning',
                        'Страница не является изолированной товарной карточкой с перспективной галереей; запоминаю URL и перехожу к следующему источнику: '.($preflight['reason'] ?? $url),
                    );
                } else {
                    $preflightDecision = 'train_playwright';
                    $debug?->__invoke(
                        'warning',
                        'AI предложил пропустить страницу без достаточных доказательств; решение не сохраняю и разрешаю Playwright проверить её.',
                    );
                }
            }

            if ($preflightDecision === 'blocked') {
                $failureKind = 'access_gate';
                $this->recordFailure(
                    $recipe,
                    new RuntimeException((string) ($preflight['reason'] ?? 'AI detected an access gate.')),
                    $failureKind,
                    $url,
                    $debug,
                );
                $failureRecorded = true;
            }

            if ($preflightDecision !== 'train_playwright') {
                $version->update([
                    'status' => 'skipped',
                    'result' => ['preflight' => $preflight],
                    'error' => $preflight['reason'] ?? null,
                ]);

                if ($preflightDecision !== 'static_sufficient') {
                    return [];
                }

                // Prefer what the real rendered page actually fetched over what a
                // plain HTTP GET saw in the raw HTML: a static scrape only catches
                // whatever <img src> is literally in the markup (often a small tab
                // icon for JS-built galleries), while network_image_samples is the
                // real asset the browser downloaded to display it - usually already
                // full size, and this works the same way on any site, not just the
                // ones we've special-cased a CDN pattern for.
                return collect([
                    ...($pageScout['network_image_samples'] ?? []),
                    ...($context['static_image_urls'] ?? []),
                ])
                    ->filter(fn (mixed $url): bool => is_string($url) && $url !== '')
                    ->map(fn (string $url): string => ProductImageStorage::normalizeCandidateUrl($url))
                    ->unique(fn (string $url): string => ProductImageStorage::imageAssetKey($url))
                    ->take(20)->values()->all();
            }
            $oldImages = [];

            if ($previousRecipeImages !== null) {
                // The caller (extract()) already just ran this exact recipe against
                // this exact URL and measured its result - re-running it here would
                // be a second, non-deterministic execution of the very recipe that
                // was JUST found insufficient, and any drift between the two runs
                // (browser/page timing flakiness) has no more claim to being "the
                // real gallery" than the run that triggered retraining in the first
                // place. Reuse that measurement instead of trusting a fresh one.
                $oldImages = $previousRecipeImages;
            } elseif (is_array($recipe->recipe) && $recipe->recipe !== []) {
                $oldResult = $this->browser->executeRecipe($url, $recipe->recipe, 20, null, $telegramUpdateId, $context);
                $this->recordExecutionTrace($url, $version, 0, $recipe->recipe, $oldResult, $telegramUpdateId);
                $oldImages = $oldResult['images'] ?? [];
            }

            $attempts = [];
            $feedback = null;
            $candidate = [];
            $candidateResult = [];
            $candidateImages = [];
            $bestPartialImages = $oldImages;
            $bestPartialResult = [];
            $promote = false;
            // A single blip in which value the AI guessed is normal
            // correction; the same validation RULE failing repeatedly (a
            // different out-of-range number is still the same mistake) means
            // it is guessing around a hard constraint instead of fixing the
            // actual approach (e.g. narrowing an overly broad selector).
            // Stop burning rounds on it well before this session's cost/time
            // budget would - see MAX_IDENTICAL_VALIDATION_FAILURES below.
            $identicalValidationFailures = 0;
            $lastValidationSignature = null;
            // Only the money/time budget is allowed to end a request for
            // good - a fixed round count is not a real reason to stop while
            // both are still available. galleryTrainingMaxRounds() becomes a
            // safety cap instead of the everyday limit, used only when cost
            // genuinely cannot be measured (tests, no telegram_update_id, no
            // configured price for this model, or the budget itself
            // disabled) - the same pattern already used for the fallback
            // discovery rounds in ProductImageStorage::stage().
            $safetyRounds = max(1, $this->settings->galleryTrainingMaxRounds());
            $safetyLimited = app()->environment('testing')
                || ! $telegramUpdateId
                || $this->costBudget->limit() <= 0
                || $this->costBudget->unmeasurable($telegramUpdateId);
            // A source is normally allowed to spend freely until the whole
            // search's budget runs out (see stage()'s "a source is atomic"
            // comment) - but that lets one stubborn domain alone burn the
            // entire search budget on training before any other candidate
            // source ever gets a turn (seen live: 9 rounds on one Lenovo
            // page spent the full $1 cap and left literally nothing for the
            // other known sources). Capping how much THIS training session
            // alone may spend, as a share of the total limit, guarantees at
            // least a couple of other sources still get tried.
            $costAtTrainingStart = $this->costBudget->spent($telegramUpdateId) ?? 0.0;
            $sourceCostShare = (float) config('product-images.source_training_cost_share_fraction', 0.4);
            $previousCandidate = null;
            $previousCandidateResult = null;
            $previousProgressSignature = null;
            $stagnantRounds = 0;
            $stalled = false;
            $stuckOnValidation = false;
            $agentAbandoned = false;
            $agentAbandonReason = null;

            for ($attempt = 1; $safetyLimited ? $attempt <= $safetyRounds : true; $attempt++) {
                $costExceeded = ! $safetyLimited && $this->costBudget->exceeded($telegramUpdateId);

                if (! $this->timeBudget->canStart($telegramUpdateId, 30) || $costExceeded) {
                    $reason = $costExceeded
                        ? 'Бюджет поиска исчерпан: дополнительную попытку обучения не запускаю.'
                        : 'Резерв времени достигнут: дополнительную попытку обучения не запускаю.';
                    $debug?->__invoke('warning', $reason);

                    if ($attempt === 1) {
                        $version->update([
                            'status' => 'deferred',
                            'error' => $costExceeded
                                ? 'Обучение отложено: денежный бюджет текущего поиска исчерпан.'
                                : 'Обучение отложено: достигнут резерв времени текущего поиска.',
                        ]);

                        return $oldImages;
                    }

                    break;
                }

                // Not a verdict on the recipe: this domain would likely keep
                // being tried, but it must not be allowed to spend the
                // whole search's budget by itself before any other
                // candidate source gets a turn. Deferred like an attempt-1
                // global budget stop (never recordFailure()'d) so this
                // domain gets a full, unpenalized shot on its next search
                // instead of edging toward auto-disable over a rationing
                // decision that had nothing to do with whether it works.
                if (! $safetyLimited && $this->costBudget->exceededForSource($telegramUpdateId, $costAtTrainingStart, $sourceCostShare)) {
                    $debug?->__invoke(
                        'warning',
                        "AI-тренер: {$host} израсходовал свою долю бюджета этого поиска после {$attempt} раунд(а/ов); оставляю бюджет другим источникам.",
                    );
                    $version->update([
                        'status' => 'deferred',
                        'error' => 'Обучение отложено: этот источник израсходовал свою долю денежного бюджета текущего поиска.',
                    ]);

                    return $bestPartialImages !== [] ? $bestPartialImages : $oldImages;
                }

                // Field order matters for OpenAI's automatic prompt caching,
                // which only discounts a request's longest prefix that is
                // byte-identical to a recent previous request. Every field
                // below the fixed line is the same on every round of this
                // one training session (same url/recipe/scout diagnostics/
                // preflight/hint); everything that actually changes round to
                // round (attempt number, current DOM, growing history and
                // feedback) is pushed after it so round 2/3 can still cache
                // that shared prefix instead of invalidating it with the
                // round counter alone. A prior real search only ever cached
                // the ~1800-token system instructions for this reason - nothing
                // from this payload, despite most of it being unchanged
                // between its 3 rounds.
                $roundLabel = $safetyLimited ? "{$attempt}/{$safetyRounds}" : (string) $attempt;
                $prompt = json_encode([
                    'auto_domain_hint' => $autoDomainHint,
                    'url' => $url,
                    'max_attempts' => $safetyLimited ? $safetyRounds : null,
                    'current_recipe' => $recipe->recipe,
                    'diagnostics' => $scout['diagnostics'] ?? [],
                    'preflight' => $preflight,
                    'domain_hint' => $domainHint,
                    'operator_hint' => $userHint,
                    // This is always the original, freshly loaded page. Every
                    // recipe execution starts from this state in a new browser
                    // process; post-interaction DOM is diagnostic feedback only.
                    'page' => $pageScout,
                    'execution_contract' => [
                        'browser_state' => 'fresh_page_load_for_every_recipe_execution',
                        'recipe_must_be_self_contained' => true,
                        'post_interaction_dom_is_diagnostic_only' => true,
                        'required_behavior' => 'Replay every successful prerequisite action before using controls revealed by it.',
                    ],
                    // --- everything below changes every round ---
                    'attempt' => $attempt,
                    'attempt_history' => $attempts,
                    'previous_attempt_feedback' => $feedback,
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

                $debug?->__invoke(
                    'step',
                    $attempt === 1
                        ? "AI-тренер: {$model} строит безопасный JSON-рецепт."
                        : "AI-тренер: {$model} исправляет рецепт по DOM и результату предыдущего раунда.",
                );
                $run = $telegramUpdateId ? AiRun::query()->create([
                    'telegram_update_id' => $telegramUpdateId,
                    'provider' => $provider,
                    'model' => $model,
                    'status' => 'running',
                    'prompt' => $prompt ?: '{}',
                    'started_at' => now(),
                ]) : null;
                $abandonSignal = new GalleryTrainingAbandonSignal;

                try {
                    $recipeTimeout = $this->timeBudget->timeoutFor(
                        $telegramUpdateId,
                        $this->settings->galleryRecipeTimeoutSeconds(),
                    );
                    $response = app(OpenAiHeavyOperationGate::class)->run(
                        $provider,
                        $recipeTimeout,
                        fn () => ProductGalleryRecipeTrainerAgent::make(
                            url: $url,
                            domain: $host,
                            telegramUpdateId: $telegramUpdateId,
                            categorySlug: $categorySlug,
                            version: $version,
                            recipe: $recipe,
                            domainSettings: $domainSettings,
                            update: $update,
                            abandonSignal: $abandonSignal,
                        )->prompt(
                            $prompt ?: '{}',
                            provider: $provider,
                            model: $model,
                            timeout: $recipeTimeout,
                        ),
                    );
                    $run?->update([
                        'invocation_id' => $response->invocationId,
                        'status' => 'completed',
                        'response' => $response->toArray(),
                        'usage' => $response->usage->toArray(),
                        'completed_at' => now(),
                    ]);
                } catch (Throwable $exception) {
                    $run?->update([
                        'status' => 'failed',
                        'error' => mb_substr($exception->getMessage(), 0, 5000),
                        'completed_at' => now(),
                    ]);
                    $failureKind = $this->failureKindForException($exception);
                    $debug?->__invoke('warning', "AI-тренер: раунд {$roundLabel} не удался ({$exception->getMessage()}), пробую следующий раунд.");
                    $attempts[] = ['attempt' => $attempt, 'error' => 'ai_call_failed: '.$exception->getMessage()];
                    $feedback = ['error' => 'The previous attempt failed to produce a response: '.mb_substr($exception->getMessage(), 0, 300)];

                    continue;
                }

                if ($abandonSignal->abandoned) {
                    $agentAbandoned = true;
                    $agentAbandonReason = $abandonSignal->reason;
                    $failureKind = $abandonSignal->failureKind ?? 'agent_abandoned';
                    $debug?->__invoke(
                        'warning',
                        "AI-тренер: агент сам решил прекратить обучение на этом URL ({$agentAbandonReason}); перехожу к следующему источнику.",
                    );
                    $attempts[] = ['attempt' => $attempt, 'error' => 'agent_abandoned: '.$agentAbandonReason];

                    break;
                }

                try {
                    $candidate = $this->validateRecipe($response->toArray());

                    if (($candidate['training_decision'] ?? 'propose_recipe') === 'abandon_page') {
                        $pageRule = $this->pageRules->rememberUnsuitable(
                            $url,
                            (string) ($candidate['page_kind'] ?? 'unknown'),
                            (string) ($candidate['reason'] ?? ''),
                            is_array($candidate['page_assessment_evidence'] ?? null)
                                ? $candidate['page_assessment_evidence']
                                : [],
                            (float) ($candidate['confidence'] ?? 0),
                            $layoutFingerprint,
                        );

                        if (! $pageRule) {
                            throw new RuntimeException(
                                'abandon_page requires a terminal page kind, confidence >= 0.85 and at least two concrete evidence items.',
                            );
                        }

                        $this->attempts->record([
                            'telegram_update_id' => $telegramUpdateId,
                            'product_gallery_recipe_version_id' => $version->id,
                            'product_url' => $url,
                            'actor' => 'ai',
                            'phase' => 'gallery_training',
                            'action' => 'assess_page_suitability',
                            'status' => 'failed',
                            'decision' => 'unsuitable_page',
                            'round' => $attempt,
                            'output' => [
                                'page_kind' => $candidate['page_kind'],
                                'reason' => $candidate['reason'],
                                'evidence' => $candidate['page_assessment_evidence'],
                                'confidence' => $candidate['confidence'],
                            ],
                        ]);
                        $version->update([
                            'status' => 'rejected',
                            'recipe' => $candidate,
                            'result' => ['page_assessment' => $candidate],
                            'error' => $candidate['reason'],
                        ]);
                        $debug?->__invoke(
                            'warning',
                            'После проверки DOM страница признана бесперспективной для товарной галереи; запоминаю URL и перехожу к следующему источнику: '.$candidate['reason'],
                        );

                        return [];
                    }

                    $candidate = $this->makeRecipeReplayable(
                        $candidate,
                        $previousCandidate,
                        $previousCandidateResult,
                    );
                } catch (Throwable $exception) {
                    // The operator-facing progress line stays in the app
                    // locale (Russian); the AI trainer prompt is entirely
                    // English, so its feedback/history use englishMessage
                    // when available instead of leaking a Russian sentence.
                    $aiFacingMessage = $exception instanceof InvalidGalleryRecipeException
                        ? $exception->englishMessage
                        : $exception->getMessage();
                    $signature = $exception instanceof InvalidGalleryRecipeException
                        ? $exception->ruleSignature
                        : [];

                    if ($signature !== [] && $signature === $lastValidationSignature) {
                        $identicalValidationFailures++;
                    } else {
                        $identicalValidationFailures = $signature !== [] ? 1 : 0;
                    }
                    $lastValidationSignature = $signature;

                    $debug?->__invoke('warning', "AI-тренер: раунд {$roundLabel} вернул невалидный рецепт ({$exception->getMessage()}), пробую следующий раунд.");
                    $attempts[] = ['attempt' => $attempt, 'error' => 'invalid_recipe: '.$aiFacingMessage];

                    if ($identicalValidationFailures >= self::MAX_IDENTICAL_VALIDATION_FAILURES) {
                        $debug?->__invoke(
                            'warning',
                            "AI-тренер: {$identicalValidationFailures} раунда подряд одна и та же ошибка валидации (".implode(', ', $signature).'); прекращаю этот URL и перехожу к следующему источнику.',
                        );
                        $failureKind = 'recipe_mismatch';
                        $stuckOnValidation = true;

                        break;
                    }

                    $feedback = [
                        'error' => 'The previous attempt returned an invalid recipe: '.mb_substr($aiFacingMessage, 0, 300),
                        ...($identicalValidationFailures >= self::MAX_IDENTICAL_VALIDATION_FAILURES - 1 ? [
                            'stuck_warning' => 'This exact validation rule ('.implode(', ', $signature).') has now failed '
                                .$identicalValidationFailures.' rounds in a row with a different value each time. Resubmitting '
                                .'another value for the same field will not work - the constraint is hard. Change the underlying '
                                .'approach instead (e.g. a more specific selector so the target element falls within range), or '
                                .'this training session will be abandoned for this URL.',
                        ] : []),
                    ];

                    continue;
                }

                $debug?->__invoke('step', "AI-тренер: проверяю рецепт, раунд {$roundLabel} · {$url}");
                $candidateResult = $this->browser->executeRecipe($url, $candidate, 20, $debug, $telegramUpdateId, $context);
                $this->recordExecutionTrace(
                    $url,
                    $version,
                    $attempt,
                    $candidate,
                    $candidateResult,
                    $telegramUpdateId,
                );
                $candidateImages = $candidateResult['images'] ?? [];
                if (count($candidateImages) > count($bestPartialImages)) {
                    $bestPartialImages = $candidateImages;
                    $bestPartialResult = $candidateResult;
                }
                $contextMinimum = max(0, (int) ($context['minimum_verified_images'] ?? 0));
                $validation = $this->resultValidator->validate(
                    $candidate,
                    $candidateResult,
                    minimumSuccessCount: $contextMinimum > 0 ? $contextMinimum : null,
                );
                $promote = $validation['passed']
                    && (count($oldImages) < 2 || count($candidateImages) >= count($oldImages));
                $attempts[] = [
                    'attempt' => $attempt,
                    'selectors_tried' => $candidate,
                    'candidate_count' => count($candidateImages),
                    'validation' => $validation,
                    'diagnostics' => $candidateResult['diagnostics'] ?? [],
                    'failure_kind' => $stalled ? 'page_stalled' : ($candidateResult['failure_kind'] ?? null),
                    'error' => $candidateResult['error'] ?? null,
                ];

                if ($promote) {
                    break;
                }

                $progressSignature = $this->trainingProgressSignature($candidateResult);
                if ($previousProgressSignature !== null && hash_equals($previousProgressSignature, $progressSignature)) {
                    $stagnantRounds++;
                } else {
                    $stagnantRounds = 0;
                }
                $previousProgressSignature = $progressSignature;

                if ($stagnantRounds >= 2) {
                    $stalled = true;
                    $debug?->__invoke(
                        'warning',
                        'AI-тренер три раунда подряд не получил новых фото, нового DOM-состояния или успешного перехода; прекращаю этот URL и перехожу к следующему источнику.',
                    );

                    break;
                }

                $postInteractionScout = $candidateResult['post_interaction_scout'] ?? [];
                $postInteractionScoutUsable = is_array($postInteractionScout)
                    && (
                        ($postInteractionScout['fragments'] ?? []) !== []
                        || ($postInteractionScout['interactive_controls'] ?? []) !== []
                        || ($postInteractionScout['action_candidates'] ?? []) !== []
                    );
                $feedback = [
                    'rejected_recipe' => $candidate,
                    'candidate_count' => count($candidateImages),
                    'previous_working_count' => count($oldImages),
                    'diagnostics' => $candidateResult['diagnostics'] ?? [],
                    'failure_kind' => $stalled ? 'page_stalled' : ($candidateResult['failure_kind'] ?? null),
                    'error' => $candidateResult['error'] ?? $validation['reason'],
                    'action_trace' => $candidateResult['action_trace'] ?? [],
                    // The initial page remains the replay starting point;
                    // this sanitized post-action snapshot is observation only.
                    'previous_attempt_observation' => is_array($postInteractionScout) && $postInteractionScout !== []
                        ? $postInteractionScout
                        : null,
                    'instruction' => $postInteractionScoutUsable
                        ? 'The page field is the fresh initial page. previous_attempt_observation is the DOM revealed by the last execution and is diagnostic only. Return a complete recipe that replays all prerequisites from the fresh page before using newly revealed controls.'
                        : 'The page field is the fresh initial page. Use the action trace and diagnostics to return a materially corrected, complete recipe; every execution starts from that fresh page.',
                ];
                $previousCandidate = $candidate;
                $previousCandidateResult = $candidateResult;
            }

            $hasPartial = ! $promote && count($bestPartialImages) > 0;
            $score = $this->score($promote ? $candidateResult : $bestPartialResult);
            $version->update([
                'status' => $promote ? 'promoted' : ($hasPartial ? 'partial' : 'rejected'),
                'recipe' => $candidate,
                'result' => [
                    'candidate_count' => count($candidateImages),
                    'best_partial_count' => count($bestPartialImages),
                    'previous_count' => count($oldImages),
                    'preflight' => $preflight,
                    'validation' => $validation ?? null,
                    'attempts' => $attempts,
                    'diagnostics' => $candidateResult['diagnostics'] ?? [],
                    'action_trace' => $candidateResult['action_trace'] ?? [],
                    'failure_kind' => match (true) {
                        $stalled => 'page_stalled',
                        $agentAbandoned => $failureKind,
                        $stuckOnValidation => 'recipe_mismatch',
                        default => $candidateResult['failure_kind'] ?? null,
                    },
                    'error' => $candidateResult['error'] ?? null,
                ],
                'score' => $score,
                'promoted_at' => $promote ? now() : null,
                'error' => match (true) {
                    $promote => null,
                    $stalled => 'Обучение остановлено: три последовательных раунда не дали материального прогресса.',
                    $agentAbandoned => 'Обучение остановлено по решению AI-агента: '.($agentAbandonReason ?? ''),
                    $stuckOnValidation => 'Обучение остановлено: '.self::MAX_IDENTICAL_VALIDATION_FAILURES.' раунда подряд одна и та же ошибка валидации ('.implode(', ', $lastValidationSignature ?? []).').',
                    default => 'Все разрешённые раунды завершены без полной подтверждённой галереи.',
                },
            ]);

            if (! $promote) {
                $failureKind = $agentAbandoned ? $failureKind : 'recipe_mismatch';
                $this->recordFailure(
                    $recipe,
                    new RuntimeException((string) $version->error),
                    $failureKind,
                    $url,
                    $debug,
                );
                $failureRecorded = true;
                if ($hasPartial) {
                    $debug?->__invoke('warning', 'Полная галерея не собрана; сохраняю проверяемый частичный результат: '.count($bestPartialImages).' фото · '.$url);

                    return $bestPartialImages;
                }
                $debug?->__invoke('warning', 'Все раунды рецепта завершены без полной галереи; рабочая версия оставлена без изменений.');

                return $oldImages;
            }

            $recipe->update([
                'recipe' => $candidate,
                'status' => 'active',
                'region' => $this->regionForUrl($url),
                'sample_path' => mb_substr((string) (parse_url($url, PHP_URL_PATH) ?: '/'), 0, 1024),
                'layout_fingerprint' => $layoutFingerprint,
                'last_observed_layout_fingerprint' => $layoutFingerprint,
                'success_count' => $recipe->success_count + 1,
                'consecutive_hard_blocks' => 0,
                'hard_block_urls' => [],
                'last_success_at' => now(),
                'last_error' => null,
                'last_failure_kind' => null,
                'retry_after' => null,
            ]);
            $debug?->__invoke('done', 'AI-рецепт проверен и опубликован. Фото: '.count($candidateImages).'.');

            return $candidateImages;
        } catch (Throwable $exception) {
            isset($version) && $version->update([
                'status' => 'failed',
                'error' => mb_substr($exception->getMessage(), 0, 4000),
            ]);

            if (isset($recipe) && ! $failureRecorded) {
                $this->recordFailure(
                    $recipe,
                    $exception,
                    $failureKind === 'unknown' ? $this->failureKindForException($exception) : $failureKind,
                    $url,
                    $debug,
                );
            }

            $debug?->__invoke('error', 'AI-тренер: '.$exception->getMessage());
            Log::warning('Gallery recipe training failed.', [
                'host' => $host,
                'failure_kind' => $failureKind,
                'error' => $exception->getMessage(),
            ]);

            return [];
        } finally {
            $lock->release();
        }
    }

    private function regionForUrl(string $url): ?string
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $parts = array_values(array_filter(explode('.', $host)));
        $topLevel = end($parts) ?: null;

        if (is_string($topLevel) && strlen($topLevel) === 2) {
            return $topLevel;
        }

        $firstPathPart = collect(explode('/', trim((string) parse_url($url, PHP_URL_PATH), '/')))
            ->first(fn (string $part): bool => preg_match('/^[a-z]{2}(?:-[a-z]{2})?$/i', $part) === 1);

        return is_string($firstPathPart) ? strtolower($firstPathPart) : null;
    }

    private function recordExecutionTrace(
        string $url,
        ProductGalleryRecipeVersion $version,
        int $round,
        array $recipe,
        array $result,
        ?int $telegramUpdateId,
    ): void {
        $this->attempts->record([
            'telegram_update_id' => $telegramUpdateId,
            'product_gallery_recipe_version_id' => $version->id,
            'product_url' => $url,
            'actor' => 'ai',
            'phase' => 'gallery_training',
            'action' => 'propose_recipe',
            'status' => 'completed',
            'decision' => 'execute_candidate',
            'round' => $round,
            'input' => ['recipe' => $recipe],
            'output' => [
                'images' => $result['images'] ?? [],
                'diagnostics' => $result['diagnostics'] ?? [],
                'post_interaction_scout' => $result['post_interaction_scout'] ?? [],
            ],
        ]);

        foreach ($result['action_trace'] ?? [] as $action) {
            if (! is_array($action)) {
                continue;
            }

            $this->attempts->record([
                'telegram_update_id' => $telegramUpdateId,
                'product_gallery_recipe_version_id' => $version->id,
                'product_url' => $url,
                'actor' => 'playwright',
                'phase' => 'gallery_training',
                'action' => (string) ($action['action'] ?? 'click'),
                'status' => ($action['clicked'] ?? false) ? 'completed' : 'skipped',
                'decision' => ($action['changed'] ?? false) ? 'dom_changed' : 'no_change',
                'round' => $round,
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

    /** @return array<string, mixed> */
    private function preflight(
        string $url,
        array $pageScout,
        array $diagnostics,
        array $context,
        ?string $domainHint,
        ?string $autoDomainHint,
        string $provider,
        string $model,
        ProductGalleryRecipeVersion $version,
        ?int $telegramUpdateId,
        ?callable $debug,
    ): array {
        $payload = [
            'url' => $url,
            'page' => $pageScout,
            'diagnostics' => $diagnostics,
            'domain_hint' => $domainHint,
            'auto_domain_hint' => $autoDomainHint,
            // Two raw URLs can still be the exact same photo at another size
            // (e.g. Adobe Scene7's wid/hei or $preset$ query forms) - counting
            // them as distinct before the preflight AI ever sees them would
            // let a single duplicated photo pass as "static_sufficient" and
            // skip Playwright training entirely. Normalizing first keeps this
            // headcount consistent with the one downloadCandidates() uses.
            'static_image_urls' => collect($context['static_image_urls'] ?? [])
                ->filter(fn (mixed $url): bool => is_string($url) && $url !== '')
                ->map(fn (string $url): string => ProductImageStorage::normalizeCandidateUrl($url))
                ->unique(fn (string $url): string => ProductImageStorage::imageAssetKey($url))
                ->take(20)->values()->all(),
        ];
        $prompt = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
        $startedAt = hrtime(true);
        $run = $telegramUpdateId ? AiRun::query()->create([
            'telegram_update_id' => $telegramUpdateId,
            'provider' => $provider,
            'model' => $model,
            'status' => 'running',
            'prompt' => $prompt,
            'started_at' => now(),
        ]) : null;

        try {
            $preflightTimeout = $this->timeBudget->timeoutFor(
                $telegramUpdateId,
                $this->settings->galleryRecipeTimeoutSeconds(),
            );
            $response = app(OpenAiHeavyOperationGate::class)->run(
                $provider,
                $preflightTimeout,
                fn () => ProductGalleryPreflightAgent::make()->prompt(
                    $prompt,
                    provider: $provider,
                    model: $model,
                    timeout: $preflightTimeout,
                ),
            );
            $preflightResponse = $response->toArray();
            $preflightResponse['page_kind'] = is_string($preflightResponse['page_kind'] ?? null)
                ? $preflightResponse['page_kind']
                : 'unknown';
            // The provider's own schema already caps these string lengths, but
            // that constraint isn't always honored - truncate defensively so an
            // overlong sentence doesn't throw away an otherwise-valid decision.
            if (is_string($preflightResponse['reason'] ?? null)) {
                $preflightResponse['reason'] = mb_substr($preflightResponse['reason'], 0, 1200);
            }
            if (is_array($preflightResponse['evidence'] ?? null)) {
                $preflightResponse['evidence'] = collect($preflightResponse['evidence'])
                    ->map(fn (mixed $item): mixed => is_string($item) ? mb_substr($item, 0, 500) : $item)
                    ->all();
            }

            $data = Validator::make($preflightResponse, [
                'decision' => ['required', 'in:static_sufficient,train_playwright,no_gallery,unsuitable_page,blocked'],
                'page_kind' => ['required', 'in:product_card,product_family_landing,editorial_marketing,listing_or_comparison,non_product_page,unknown'],
                'gallery_likely' => ['required', 'boolean'],
                'hidden_images_likely' => ['required', 'boolean'],
                'interaction_required' => ['required', 'boolean'],
                'expected_image_count' => ['required', 'integer', 'between:0,20'],
                'evidence' => ['present', 'array', 'max:12'],
                'evidence.*' => ['string', 'max:500'],
                'confidence' => ['required', 'numeric', 'between:0,1'],
                'reason' => ['required', 'string', 'max:1200'],
            ])->validate();
            $run?->update([
                'invocation_id' => $response->invocationId,
                'status' => 'completed',
                'response' => $response->toArray(),
                'usage' => $response->usage->toArray(),
                'completed_at' => now(),
            ]);
            $status = 'completed';
        } catch (Throwable $exception) {
            $run?->update([
                'status' => 'failed',
                'error' => mb_substr($exception->getMessage(), 0, 5000),
                'completed_at' => now(),
            ]);
            $data = [
                'decision' => count($payload['static_image_urls']) >= 2 ? 'static_sufficient' : 'no_gallery',
                'page_kind' => 'unknown',
                'gallery_likely' => false,
                'hidden_images_likely' => false,
                'interaction_required' => false,
                'expected_image_count' => count($payload['static_image_urls']),
                'evidence' => [],
                'confidence' => 0,
                'reason' => 'AI preflight failed: '.$exception->getMessage(),
            ];
            $status = 'failed';
        }

        $this->attempts->record([
            'telegram_update_id' => $telegramUpdateId,
            'product_gallery_recipe_version_id' => $version->id,
            'product_url' => $url,
            'actor' => 'ai',
            'phase' => 'gallery_preflight',
            'action' => 'classify_gallery',
            'status' => $status,
            'decision' => $data['decision'],
            'input' => $payload,
            'output' => $data,
            'duration_ms' => (int) round((hrtime(true) - $startedAt) / 1_000_000),
        ]);
        $debug?->__invoke(
            $data['decision'] === 'train_playwright' ? 'step' : 'warning',
            'AI-предфильтр: '.$data['decision'].' · '.$data['reason'].' · '.$url,
        );

        return $data;
    }

    // A validation failure (schema-invalid recipe) that repeats on the exact
    // same rule round after round means the AI is guessing around a hard
    // constraint (e.g. a selector_index beyond the safety cap) rather than
    // fixing the underlying approach - production seen: 11-15 rounds burnt
    // on one unchanging rule before this existed. Nothing else bounds this
    // loop by round count (gallery_training_max_rounds is a cost-budget-only
    // safety net, not a real per-training-session cap), so without this a
    // stuck loop can consume the whole search's time/cost budget alone.
    private const MAX_IDENTICAL_VALIDATION_FAILURES = 3;

    private const DOWNLOAD_FAILURE_KIND = 'download_unreachable';

    // A single blip (transient network hiccup) must not force a retrain -
    // only a repeated pattern across separate drafts/searches is treated as
    // a real signal that the recipe's URLs are structurally unreachable
    // outside a live browser session (e.g. referer/cookie-gated CDN).
    private const DOWNLOAD_FAILURE_THRESHOLD = 3;

    // If retraining keeps "verifying" the recipe (its own in-browser probe
    // passes) and downloads still fail every time afterwards, the problem is
    // almost certainly not the click sequence itself - no amount of
    // retraining fixes a CDN that only serves images inside a live session.
    // Stop spending AI budget on it after this many degrade cycles and leave
    // it for manual review instead of retraining forever.
    private const DOWNLOAD_DEGRADE_CYCLE_CAP = 3;

    /**
     * The recipe's own in-browser probe (see train()'s verification round)
     * can load a candidate image successfully while a later plain HTTP
     * download of the exact same URL fails - e.g. a CDN that only serves
     * images inside a live browser session/referer. Nothing else reports
     * that mismatch back here, so without this an "active" recipe would
     * stay trusted and fail the same way on every future draft for this
     * domain. Only a confirmed (Playwright-verified) candidate should ever
     * reach this - a plain quality rejection (too small, etc.) says nothing
     * about whether the recipe's URLs are actually reachable.
     *
     * @return bool True if this call just moved the recipe to "learning".
     */
    public function recordConfirmedDownloadFailure(string $domain, ?callable $debug = null): bool
    {
        $recipe = ProductGalleryRecipe::query()->where('domain', $domain)->where('status', 'active')->first();

        if (! $recipe) {
            return false;
        }

        $failedAfterLastSuccess = ! $recipe->last_success_at
            || ! $recipe->last_failure_at
            || $recipe->last_failure_at->greaterThan($recipe->last_success_at);
        $sameFailureSequence = $failedAfterLastSuccess && $recipe->last_failure_kind === self::DOWNLOAD_FAILURE_KIND;
        $failureCount = $sameFailureSequence ? max(0, (int) $recipe->failure_count) + 1 : 1;
        $update = [
            'failure_count' => $failureCount,
            'last_failure_at' => now(),
            'last_failure_kind' => self::DOWNLOAD_FAILURE_KIND,
        ];

        if ($failureCount < self::DOWNLOAD_FAILURE_THRESHOLD) {
            $update['last_error'] = "Подтверждённое в браузере фото не скачалось обычным HTTP-запросом ({$failureCount}/"
                .self::DOWNLOAD_FAILURE_THRESHOLD.').';
            $recipe->update($update);

            return false;
        }

        $cycleKey = "gallery-recipe-download-degrade-cycles:{$domain}";
        $cycles = (int) Cache::get($cycleKey, 0);

        if ($cycles >= self::DOWNLOAD_DEGRADE_CYCLE_CAP) {
            $update['last_error'] = 'Повторные провалы скачивания после '.self::DOWNLOAD_DEGRADE_CYCLE_CAP.' циклов '
                .'переобучения - дело, вероятно, не в самом рецепте (сессионная/cookie-защита CDN?). '
                .'Автопереобучение по этой причине остановлено, нужна ручная проверка.';
            $recipe->update($update);
            $debug?->__invoke(
                'warning',
                "Домен {$domain}: авто-переобучение по скачиванию остановлено после ".self::DOWNLOAD_DEGRADE_CYCLE_CAP.' циклов - нужна ручная проверка.',
            );

            return false;
        }

        Cache::put($cycleKey, $cycles + 1, now()->addDays(7));
        $update['status'] = 'learning';
        $update['last_error'] = "Подтверждённые в браузере фото не скачались {$failureCount} раз(а) подряд обычным HTTP-запросом.";
        $recipe->update($update);
        $debug?->__invoke(
            'warning',
            "Домен {$domain}: подтверждённые фото не скачались {$failureCount} раз подряд - рецепт помечен на переобучение.",
        );

        return true;
    }

    /**
     * A confirmed candidate that DID download successfully means this
     * domain's images are currently reachable, so a prior run of blips
     * shouldn't count toward the failure threshold above.
     */
    public function recordConfirmedDownloadSuccess(string $domain): void
    {
        ProductGalleryRecipe::query()
            ->where('domain', $domain)
            ->where('status', 'active')
            ->where('last_failure_kind', self::DOWNLOAD_FAILURE_KIND)
            ->where('failure_count', '>', 0)
            ->update(['failure_count' => 0]);
    }

    /**
     * Exposes recordConfirmedDownloadFailure()'s otherwise-private Cache
     * counter for read-only inspection (e.g. by GetRecipeHealth), so a
     * caller can tell "this recipe is on retrain cycle 2 of 3 because of a
     * download-layer problem" without duplicating the Cache key elsewhere.
     */
    public function downloadDegradeCycles(string $domain): int
    {
        return (int) Cache::get("gallery-recipe-download-degrade-cycles:{$domain}", 0);
    }

    private function recordFailure(
        ProductGalleryRecipe $recipe,
        Throwable $exception,
        string $kind,
        string $url,
        ?callable $debug = null,
    ): void {
        $failedAfterLastSuccess = ! $recipe->last_success_at
            || ! $recipe->last_failure_at
            || $recipe->last_failure_at->greaterThan($recipe->last_success_at);
        $sameFailureSequence = $failedAfterLastSuccess && $recipe->last_failure_kind === $kind;
        $failureCount = $sameFailureSequence
            ? max(0, (int) $recipe->failure_count) + 1
            : 1;
        $hasUsableRecipe = ! empty($recipe->recipe['collect_selectors'] ?? []);
        $hardBlockUrls = $recipe->hard_block_urls ?? [];
        $hardBlockCount = (int) $recipe->consecutive_hard_blocks;

        if ($kind === 'access_gate') {
            $hardBlockUrls = collect($hardBlockUrls)
                ->push($url)
                ->filter(fn (mixed $item): bool => is_string($item) && $item !== '')
                ->unique()
                ->values()
                ->slice(-5)
                ->values()
                ->all();
            $hardBlockCount++;
        }

        $disableAfter = match ($kind) {
            'access_gate' => 1,
            'recipe_mismatch', 'dom_unusable', 'agent_abandoned' => 2,
            'browser_timeout', 'browser_protocol' => 3,
            default => PHP_INT_MAX,
        };
        $disable = $failureCount >= $disableAfter;

        $retryAfter = $disable ? null : match ($kind) {
            'rate_limited' => now()->addMinutes(30),
            'browser_timeout', 'browser_protocol' => now()->addMinutes(min(60, 2 ** min(5, $failureCount))),
            'browser_unavailable', 'browser_process' => now()->addMinutes(15),
            'ai_timeout', 'ai_rate_limited' => now()->addMinutes(15),
            'recipe_mismatch', 'dom_unusable', 'agent_abandoned' => now()->addMinutes(10),
            default => now()->addMinutes(15),
        };
        $pausePlaywright = in_array($kind, [
            'access_gate',
            'rate_limited',
            'browser_timeout',
            'browser_unavailable',
            'browser_process',
            'browser_protocol',
        ], true);
        $status = $disable
            ? 'disabled'
            : ($pausePlaywright ? 'learning' : ($hasUsableRecipe ? 'active' : 'learning'));
        $disableReason = match ($kind) {
            'access_gate' => 'CAPTCHA/WAF: повторный вход тем же Playwright с высокой вероятностью снова будет заблокирован.',
            'recipe_mismatch', 'dom_unusable' => 'две полные тренировки рецепта не смогли получить галерею.',
            'agent_abandoned' => 'AI-тренер дважды сам решил, что дальнейшее обучение на этом URL бесперспективно.',
            'browser_timeout', 'browser_protocol' => 'три последовательные браузерные попытки завершились одинаковой ошибкой.',
            default => 'исчерпан безопасный бюджет повторных попыток.',
        };
        $error = $disable
            ? "Playwright отключён: {$disableReason} Обычный HTML-поиск продолжает работать; включить домен снова можно вручную."
            : mb_substr($exception->getMessage(), 0, 4000);
        $sourceBlock = $kind === 'access_gate' ? [
            'source_blocked' => true,
            'source_block_reason' => $disableReason,
            'source_blocked_at' => now(),
        ] : [];

        $recipe->update([
            ...$sourceBlock,
            'status' => $status,
            'failure_count' => $failureCount,
            'consecutive_hard_blocks' => $hardBlockCount,
            'hard_block_urls' => $hardBlockUrls,
            'last_failure_at' => now(),
            'last_error' => $error,
            'last_failure_kind' => $kind,
            'retry_after' => $retryAfter,
        ]);

        if ($disable) {
            $debug?->__invoke(
                'warning',
                "Playwright для {$recipe->domain} отключён: {$disableReason} HTML-поиск не отключён.",
            );
        }
    }

    private function failureKindForException(Throwable $exception): string
    {
        $message = strtolower($exception->getMessage());

        return match (true) {
            str_contains($message, '429'), str_contains($message, 'rate limit') => 'ai_rate_limited',
            str_contains($message, 'timeout'), str_contains($message, 'timed out') => 'ai_timeout',
            str_contains($message, 'connection'), str_contains($message, 'temporar') => 'ai_transient',
            default => 'ai_error',
        };
    }

    /** @return array<string, mixed> */
    private function validateRecipe(array $data): array
    {
        $data['training_decision'] = is_string($data['training_decision'] ?? null)
            ? $data['training_decision']
            : 'propose_recipe';
        $data['page_kind'] = is_string($data['page_kind'] ?? null)
            ? $data['page_kind']
            : 'unknown';
        $data['page_assessment_evidence'] = collect(
            is_array($data['page_assessment_evidence'] ?? null)
                ? $data['page_assessment_evidence']
                : [],
        )
            ->filter(fn (mixed $item): bool => is_string($item) && trim($item) !== '')
            ->map(fn (string $item): string => mb_substr(trim($item), 0, 500))
            ->unique()
            ->values()
            ->all();
        // Stored recipes and test fixtures created before ordered actions
        // remain valid; new strict AI responses always include this field.
        $data['actions'] = is_array($data['actions'] ?? null) ? $data['actions'] : [];
        // Same backward-compatibility rule for exclude_selectors: recipes
        // trained before this field existed simply exclude nothing.
        $data['exclude_selectors'] = is_array($data['exclude_selectors'] ?? null) ? $data['exclude_selectors'] : [];
        $data['actions'] = collect($data['actions'])
            ->map(function (mixed $action): mixed {
                if (! is_array($action)) {
                    return $action;
                }

                if (is_string($action['purpose'] ?? null)) {
                    $action['purpose'] = mb_substr(trim($action['purpose']), 0, 200);
                }

                return $action;
            })
            ->values()
            ->all();
        $data['wait_after_click_ms'] = $this->clampInt($data['wait_after_click_ms'] ?? null, 50, 1000, 150);
        $data['max_thumbnail_clicks'] = $this->clampInt($data['max_thumbnail_clicks'] ?? null, 0, 20, 5);
        $data['max_next_clicks'] = $this->clampInt($data['max_next_clicks'] ?? null, 0, 15, 5);
        // Free-text reasoning fields: truncate an overlong AI answer instead of
        // rejecting an otherwise-usable recipe outright over field length.
        if (is_string($data['expected_count_evidence'] ?? null)) {
            $data['expected_count_evidence'] = mb_substr($data['expected_count_evidence'], 0, 500);
        }
        if (is_string($data['reason'] ?? null)) {
            $data['reason'] = mb_substr($data['reason'], 0, 1000);
        }

        // Scout exposes the DOM property as `current_src`, while the browser
        // runner already reads element.currentSrc automatically and recipe
        // attributes are deliberately limited to real HTML attribute names.
        // Normalize that observation alias before validation so one harmless
        // naming mismatch cannot burn every paid correction round.
        $data['attributes'] = collect(is_array($data['attributes'] ?? null) ? $data['attributes'] : [])
            ->filter(fn (mixed $attribute): bool => is_string($attribute))
            ->map(function (string $attribute): string {
                $attribute = strtolower(trim($attribute));

                return match ($attribute) {
                    'currentsrc', 'current_src', 'current-src' => 'src',
                    'source_srcset', 'source-srcset' => 'srcset',
                    default => str_starts_with($attribute, 'data_')
                        ? str_replace('_', '-', $attribute)
                        : $attribute,
                };
            })
            ->unique()
            ->values()
            ->all();

        try {
            $data = Validator::make(
                $data,
                $this->recipeValidationRules(),
                $this->recipeValidationMessages(app()->getLocale()),
            )->validate();
        } catch (ValidationException $exception) {
            throw new InvalidGalleryRecipeException(
                $exception->getMessage(),
                $this->englishValidationMessage($data),
                self::validationRuleSignature($exception),
            );
        }

        foreach (['pre_click_selectors', 'collect_selectors', 'thumbnail_selectors', 'open_selectors', 'next_selectors', 'exclude_selectors'] as $key) {
            $data[$key] = collect($data[$key] ?? [])
                ->filter(fn (mixed $selector): bool => is_string($selector) && $this->safeSelector($selector))
                ->map(fn (string $selector): string => trim($selector))
                ->unique()->values()->all();
        }

        // Prefer a selector scoped to the product gallery over its broad suffix.
        // AI may return both `img.foo` and `.gallery img.foo`; executing both can
        // collect unrelated recommendation, recently-viewed or cart images that
        // happen to share the same utility classes with the real gallery.
        $collectSelectors = collect($data['collect_selectors']);
        $data['collect_selectors'] = $collectSelectors
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

        $data['actions'] = collect($data['actions'])
            ->filter(fn (mixed $action): bool => is_array($action)
                && is_string($action['selector'] ?? null)
                && $this->safeSelector($action['selector']))
            ->map(fn (array $action): array => [
                'kind' => $action['kind'],
                'selector' => trim($action['selector']),
                'index' => (int) $action['index'],
                'limit' => (int) $action['limit'],
                'wait_after_ms' => (int) $action['wait_after_ms'],
                'purpose' => trim($action['purpose']),
            ])
            ->values()
            ->all();

        $data['attributes'] = collect($data['attributes'] ?? [])
            ->map(fn (string $attribute): string => strtolower(trim($attribute)))
            ->unique()->values()->all();

        if (($data['training_decision'] ?? 'propose_recipe') === 'propose_recipe' && $data['collect_selectors'] === []) {
            throw new RuntimeException('AI не вернул безопасный селектор сбора изображений.');
        }

        return $data;
    }

    /** @return array<string, array<int, string>> */
    private function recipeValidationRules(): array
    {
        return [
            'training_decision' => ['required', 'in:propose_recipe,abandon_page'],
            'page_kind' => ['required', 'in:product_card,product_family_landing,editorial_marketing,listing_or_comparison,non_product_page,unknown'],
            'page_assessment_evidence' => ['present', 'array', 'max:8'],
            'page_assessment_evidence.*' => ['string', 'max:500'],
            'gallery_present' => ['required', 'boolean'],
            'expected_image_count' => ['required', 'integer', 'between:0,20'],
            'expected_count_evidence' => ['required', 'string', 'max:500'],
            'content_confirmed_product' => ['required', 'boolean'],
            'actions' => ['present', 'array', 'max:12'],
            'actions.*.kind' => ['required', 'in:click,click_each,click_until_no_change'],
            'actions.*.selector' => ['required', 'string', 'max:300'],
            'actions.*.index' => ['required', 'integer', 'between:0,20'],
            'actions.*.limit' => ['required', 'integer', 'between:1,20'],
            'actions.*.wait_after_ms' => ['required', 'integer', 'between:50,1500'],
            'actions.*.purpose' => ['required', 'string', 'max:200'],
            'pre_click_selectors' => ['present', 'array', 'max:5'],
            'collect_selectors' => ['present', 'array', 'max:12'],
            'thumbnail_selectors' => ['present', 'array', 'max:8'],
            'open_selectors' => ['present', 'array', 'max:5'],
            'next_selectors' => ['present', 'array', 'max:5'],
            'exclude_selectors' => ['present', 'array', 'max:8'],
            'pre_click_selectors.*' => ['string', 'max:300'],
            'collect_selectors.*' => ['string', 'max:300'],
            'thumbnail_selectors.*' => ['string', 'max:300'],
            'open_selectors.*' => ['string', 'max:300'],
            'next_selectors.*' => ['string', 'max:300'],
            'exclude_selectors.*' => ['string', 'max:300'],
            'attributes' => ['present', 'array', 'max:12'],
            'attributes.*' => ['regex:/^(?:src|href|srcset|data-[a-z0-9_-]+)$/i', 'max:80'],
            'max_thumbnail_clicks' => ['required', 'integer', 'between:0,20'],
            'max_next_clicks' => ['required', 'integer', 'between:0,15'],
            'wait_after_click_ms' => ['required', 'integer', 'between:50,1000'],
            'confidence' => ['required', 'numeric', 'between:0,1'],
            'reason' => ['required', 'string', 'max:1000'],
        ];
    }

    /**
     * The app locale is Russian (operator-facing Telegram text), but this
     * message is fed back to the AI trainer as previous_attempt_feedback and
     * attempt_history, alongside an otherwise entirely English prompt - so it
     * is rendered in English regardless of APP_LOCALE.
     */
    private function englishValidationMessage(array $data): string
    {
        $locale = app()->getLocale();
        app()->setLocale('en');

        try {
            Validator::make($data, $this->recipeValidationRules(), $this->recipeValidationMessages('en'))->validate();

            return 'The recipe failed schema validation.';
        } catch (ValidationException $exception) {
            return $exception->getMessage();
        } finally {
            app()->setLocale($locale);
        }
    }

    /**
     * Identifies which rule(s) failed independent of which array index or
     * value triggered them ("actions.1.index" and "actions.0.index" are the
     * same recurring mistake, not two different ones) - this is what lets
     * the training loop notice "stuck repeating this" instead of only ever
     * seeing the message text change because the bad value itself changed.
     *
     * @return array<int, string>
     */
    private static function validationRuleSignature(ValidationException $exception): array
    {
        return collect($exception->validator->errors()->keys())
            ->map(fn (string $key): string => preg_replace('/\.\d+(?=\.|$)/', '.*', $key) ?? $key)
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * Laravel's default regex-failure message ("the attributes.2 field
     * format is invalid") doesn't say what format IS valid, which let the AI
     * repeat the same rejected value for all 3 rounds of a real training run
     * (it kept adding "alt" - not a URL-bearing attribute - to identify a
     * placeholder/logo carousel slide, a case the app already handles by
     * URL pattern after collection; see instructions() for the matching
     * prompt guidance). Only this one rule gets a clearer message; the rest
     * already get Laravel's normal localized text.
     *
     * @return array<string, string>
     */
    private function recipeValidationMessages(string $locale): array
    {
        return [
            'attributes.*.regex' => $locale === 'ru'
                ? 'Каждый элемент attributes должен быть ровно "src", "href", "srcset" либо именем атрибута "data-*" - другие атрибуты (например "alt") сюда не годятся; заглушки/логотипы приложение и так отсеивает по URL после сбора.'
                : 'Each attributes entry must be exactly "src", "href", "srcset", or a "data-*" attribute name - other attributes such as "alt" are not accepted here; the app already filters placeholder/logo images by URL pattern after collection, so there is no need to identify them via attributes.',
        ];
    }

    /**
     * A correction is proposed from post-interaction evidence but executed in
     * a fresh browser. Preserve the successful click prefix that produced the
     * observed state unless the correction already contains that exact step.
     * This is generic replay protection, not a domain-specific selector rule.
     *
     * @param  array<string, mixed>  $candidate
     * @param  array<string, mixed>|null  $previousCandidate
     * @param  array<string, mixed>|null  $previousResult
     * @return array<string, mixed>
     */
    private function makeRecipeReplayable(
        array $candidate,
        ?array $previousCandidate,
        ?array $previousResult,
    ): array {
        if (! $previousCandidate || ! $previousResult) {
            return $candidate;
        }

        $previousActions = is_array($previousCandidate['actions'] ?? null)
            ? $previousCandidate['actions']
            : [];
        $trace = collect(is_array($previousResult['action_trace'] ?? null)
            ? $previousResult['action_trace']
            : []);
        $candidateActions = collect(is_array($candidate['actions'] ?? null)
            ? $candidate['actions']
            : []);

        $successfulPrefix = [];
        foreach ($previousActions as $actionIndex => $action) {
            if (! is_array($action) || ($action['kind'] ?? null) !== 'click') {
                break;
            }

            $selector = (string) ($action['selector'] ?? '');
            $purpose = (string) ($action['purpose'] ?? '');
            $opensGallery = in_array($selector, $previousCandidate['open_selectors'] ?? [], true)
                || preg_match('/(?:open|expand|full.?screen|lightbox|zoom|viewer|view.?all)/i', $purpose) === 1;
            $worked = $trace->contains(fn (mixed $item): bool => is_array($item)
                && (int) ($item['action_index'] ?? -1) === $actionIndex
                && ($item['action'] ?? null) === 'click'
                && ($item['clicked'] ?? false) === true
                && ($item['unsafe_control'] ?? false) !== true
                && ($item['navigation_blocked'] ?? false) !== true
                && ($item['navigated_away'] ?? false) !== true
                && (! $opensGallery
                    || ($item['changed'] ?? false) === true
                    || ($item['expanded_gallery_visible_after'] ?? false) === true));
            if (! $worked) {
                break;
            }

            $successfulPrefix[] = $action;
        }

        if ($successfulPrefix === []) {
            return $candidate;
        }

        $prefixKeys = collect($successfulPrefix)
            ->map(fn (array $action): string => 'click|'.($action['selector'] ?? '').'|'.($action['index'] ?? 0))
            ->all();
        $remainingActions = $candidateActions
            ->reject(function (array $action) use ($prefixKeys): bool {
                $key = ($action['kind'] ?? '').'|'.($action['selector'] ?? '').'|'.($action['index'] ?? 0);

                return in_array($key, $prefixKeys, true);
            })
            ->values()->all();
        $candidate['actions'] = array_slice([
            ...$successfulPrefix,
            ...$remainingActions,
        ], 0, 12);
        $candidate['open_selectors'] = collect($candidate['open_selectors'] ?? [])
            ->merge($previousCandidate['open_selectors'] ?? [])
            ->filter(fn (mixed $selector): bool => is_string($selector))
            ->unique()->take(5)->values()->all();
        $candidate['pre_click_selectors'] = collect($candidate['pre_click_selectors'] ?? [])
            ->merge($previousCandidate['pre_click_selectors'] ?? [])
            ->filter(fn (mixed $selector): bool => is_string($selector))
            ->unique()->take(5)->values()->all();

        return $candidate;
    }

    /**
     * Fingerprint only observable progress, not the selector text proposed by
     * the model. Trying three different selectors against the same unchanged
     * page is still a stalled training session.
     */
    private function trainingProgressSignature(array $result): string
    {
        $images = collect(is_array($result['images'] ?? null) ? $result['images'] : [])
            ->filter(fn (mixed $url): bool => is_string($url) && $url !== '')
            ->map(fn (string $url): string => ProductImageStorage::imageAssetKey($url))
            ->unique()
            ->sort()
            ->values()
            ->all();
        $postInteractionScout = is_array($result['post_interaction_scout'] ?? null)
            ? $result['post_interaction_scout']
            : [];
        $layoutFingerprint = $postInteractionScout === []
            ? null
            : app(ProductPageLayoutFingerprint::class)->make($postInteractionScout);
        $transitions = collect(is_array($result['action_trace'] ?? null) ? $result['action_trace'] : [])
            ->filter(fn (mixed $action): bool => is_array($action) && ($action['clicked'] ?? false) === true)
            ->map(fn (array $action): array => [
                'changed' => (bool) ($action['changed'] ?? false),
                'expanded_gallery' => (bool) ($action['expanded_gallery_visible_after'] ?? false),
                'navigated' => (bool) ($action['navigated'] ?? false),
                'gallery_count_after' => (int) ($action['gallery_count_after'] ?? 0),
            ])
            ->values()
            ->all();

        return hash('sha256', json_encode([
            'images' => $images,
            'layout_fingerprint' => $layoutFingerprint,
            'transitions' => $transitions,
            'observed_gallery_count' => (int) data_get($result, 'diagnostics.observed_gallery_count', 0),
            'validated_candidates' => (int) data_get($result, 'diagnostics.validated_candidates', 0),
        ], JSON_UNESCAPED_SLASHES) ?: '{}');
    }

    private function clampInt(mixed $value, int $min, int $max, int $default): int
    {
        if (! is_numeric($value)) {
            return $default;
        }

        return max($min, min($max, (int) $value));
    }

    private function safeSelector(string $selector): bool
    {
        return trim($selector) !== ''
            && ! str_contains($selector, "\0")
            && ! preg_match('/(?:javascript:|https?:|file:|xpath|script\b|iframe\b)/i', $selector);
    }

    private function score(array $result): float
    {
        $count = count($result['images'] ?? []);
        $dom = (int) data_get($result, 'diagnostics.dom_candidates', 0);

        return min(100, ($count * 10) + min(20, $dom));
    }
}
