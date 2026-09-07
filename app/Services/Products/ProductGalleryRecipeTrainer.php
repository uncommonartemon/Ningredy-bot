<?php

namespace App\Services\Products;

use App\Ai\Agents\ProductGalleryPreflightAgent;
use App\Ai\Agents\ProductGalleryRecipeTrainerAgent;
use App\Exceptions\InvalidGalleryRecipeException;
use App\Models\AiRun;
use App\Models\ProductGalleryRecipe;
use App\Models\ProductGalleryRecipeVersion;
use App\Models\ProductSourceAttempt;
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
use Laravel\Ai\Files\Image;
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
        private readonly ProductGalleryRecipeRouter $recipeRouter,
        private readonly ProductImageResolver $resolver,
    ) {}

    /**
     * What the downloader would make of the frames this round produced.
     *
     * Training used to end at "the browser returned these URLs", and the pixels
     * behind them were only measured afterwards, by a different part of the
     * search, long after the agent had stopped listening. So a recipe could be
     * promoted for collecting ten thumbnails - seen live on lenovo.com, where
     * every URL the gallery exposed was 584px wide against a 700px floor, and
     * on islandelectricalsupply.com, where eight frames survived the technical
     * checks and none reached the catalog.
     *
     * The frames are fetched here, in the round that produced them, with the
     * same downloader and the same rules the catalog will apply - so the agent
     * is told "your selector yields 584x584, find the full-size source" while
     * it can still act on it.
     *
     * @param  array<int, string>  $urls
     * @param  array<string, mixed>  $context
     * @return array{measured: int, fetched: int, usable: int, rejected: array<string, int>, samples: array<int, string>}
     */
    /**
     * Whether a replacement recipe breaks a page the current one is known to
     * have opened, returning the reason when it does.
     *
     * Only for a recipe that has actually succeeded before: a first version has
     * nothing to regress, and paying for a browser run to prove that would be
     * a tax on every new domain. The page is taken from this recipe's own
     * successful history rather than from a list someone has to maintain, and
     * the page currently being trained is excluded - it is the one page the new
     * version is guaranteed to fit.
     *
     * @param  array<string, mixed>  $candidate
     */
    private function canaryFailure(
        ProductGalleryRecipe $recipe,
        array $candidate,
        string $trainingUrl,
        ?int $telegramUpdateId,
        ?callable $debug,
    ): ?string {
        if ((int) $recipe->success_count < 1) {
            return null;
        }

        $provenUrl = ProductSourceAttempt::query()
            ->where('domain', $recipe->domain)
            ->where('phase', 'active_recipe')
            ->where('status', 'completed')
            ->whereNotNull('product_url')
            ->where('product_url', '!=', $trainingUrl)
            ->latest('id')
            ->value('product_url');

        if (! is_string($provenUrl) || $provenUrl === '') {
            return null;
        }

        // Never at the cost of the search itself: a regression check that eats
        // the last of the budget turns a working repair into no photographs at
        // all. Skipping it leaves the old behaviour, which is what happens
        // today on every promotion.
        if (! $this->timeBudget->canStart($telegramUpdateId, 40) || $this->costBudget->exceeded($telegramUpdateId)) {
            return null;
        }

        $debug?->__invoke('step', 'Проверяю починенный рецепт на прежде рабочей странице: '.$provenUrl);

        try {
            $result = $this->browser->executeRecipe($provenUrl, $candidate, 20, null, $telegramUpdateId);
        } catch (Throwable $exception) {
            // The page itself failing - a timeout, a block - says nothing about
            // the repair, and must not veto it.
            return null;
        }

        $validation = $this->resultValidator->validate($candidate, $result, countedOnThisPage: false);

        return $validation['passed'] ? null : (string) $validation['reason'];
    }

    private function measureDownloadableFrames(array $urls, array $context, string $pageUrl): array
    {
        $minimumWidth = (int) ($context['minimum_image_width'] ?? 0) > 0
            ? (int) $context['minimum_image_width']
            : $this->settings->imageMinimumWidth();
        $minimumHeight = ($context['minimum_image_height'] ?? null) === null
            ? $this->settings->imageMinimumHeight()
            : max(0, (int) $context['minimum_image_height']);
        // A sample, not the set: this is diagnosis, and downloading twenty
        // frames on every round would cost more time than the answer is worth.
        // Distinct assets only, so five renditions of one photo cannot make a
        // thumbnail-only recipe look healthy.
        $sample = collect($urls)
            ->filter(fn (mixed $url): bool => is_string($url) && filter_var($url, FILTER_VALIDATE_URL) !== false)
            ->unique(fn (string $url): string => ProductImageStorage::imageAssetKey($url))
            ->take(self::DOWNLOAD_PROBE_SAMPLE)
            ->values();
        $usable = 0;
        $fetched = 0;
        $rejected = [];
        $samples = [];

        foreach ($sample as $url) {
            $failure = null;
            $download = $this->resolver->download($url, failureReason: $failure, refererUrl: $pageUrl);

            if ($download === null) {
                $rejected[$failure ?: 'download_failed'] = ($rejected[$failure ?: 'download_failed'] ?? 0) + 1;

                continue;
            }

            $fetched++;
            $width = (int) ($download['width'] ?? 0);
            $height = (int) ($download['height'] ?? 0);
            $samples[] = $width.'x'.$height;

            if ($width >= $minimumWidth && ($minimumHeight === 0 || $height >= $minimumHeight)) {
                $usable++;

                continue;
            }

            $reason = 'too_small (required width>='.$minimumWidth
                .($minimumHeight > 0 ? ', height>='.$minimumHeight : ', height=any').')';
            $rejected[$reason] = ($rejected[$reason] ?? 0) + 1;
        }

        return [
            'measured' => $sample->count(),
            'fetched' => $fetched,
            'usable' => $usable,
            'rejected' => $rejected,
            'samples' => $samples,
        ];
    }

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
        array $repairFrom = [],
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

        $pathPattern = $this->recipeRouter->pathPatternForUrl($url);
        $lock = Cache::lock('gallery-recipe-training:'.sha1($host.'|'.$pathPattern), 360);

        if (! $lock->get()) {
            $debug?->__invoke('warning', "AI-тренер {$host} уже запущен другим воркером.");

            return [];
        }

        $failureKind = 'unknown';
        $failureRecorded = false;

        try {
            $recipe = $this->recipeRouter->recipeForTraining(
                $url,
                reuseLegacyFallback: $trigger !== 'automatic_failure',
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
            // The work is visual, and until now the agent did it blind: it read
            // a description of a layout it had never seen. One picture costs a
            // fraction of the text describing it and answers what the text
            // cannot - whether the thumbnails are a strip or a grid, whether
            // anything is still sitting on top of the page.
            $pageImage = is_string($scout['screenshot'] ?? null) ? $scout['screenshot'] : null;
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

                // An interrupted classification is a call that never reached the
                // agent, and everything downstream reads it as "the gallery was
                // not confirmed" - which sends a page with a perfectly good
                // recipe off to be scraped blind. Seen live on cdw.com: the
                // stored recipe had already collected all six photographs, the
                // classification broke technically, and the source ended up
                // downloading one tracking pixel's worth of nothing.
                //
                // One retry, and only while the budget can pay for it. A second
                // failure is a real answer about the provider, not about the page.
                if (($preflight['decision'] ?? null) === 'interrupted'
                    && $this->timeBudget->canStart($telegramUpdateId, 20)
                    && ! $this->costBudget->exceeded($telegramUpdateId)) {
                    $debug?->__invoke('warning', 'AI-предфильтр не состоялся технически; повторяю один раз.');
                    $preflight = $this->preflight($url, $pageScout, $scout['diagnostics'] ?? [], $context, $domainHint, $autoDomainHint, $provider, $model, $version, $telegramUpdateId, $debug);
                }

                $preflightDecision = (string) ($preflight['decision'] ?? 'no_gallery');

                // static_sufficient is an estimate from raw DOM markup (thumbnails
                // and CDN size-variants can inflate expected_image_count - see the
                // smarty.cz case: predicted 8, Vision-verified 3), not a verified
                // count. Explicit user tradeoff: when a real gallery is present,
                // train a real, reusable, Vision-verified recipe instead of
                // trusting that estimate - slower and costlier per search, but the
                // trained recipe is reused on every later visit to this domain.
                // The trade only pays while the search can still afford it. Seen
                // live (2026-09-03, draft #95): three sources in a row reported
                // full-size static photos already in the DOM, the override
                // trained on all three, every training failed, and a whole
                // dollar bought one partial photo - the last source still had
                // five static photos waiting when the budget ran out. Past the
                // same reservation the source loop uses, the estimate is taken
                // at its word: photos in hand beat a recipe nobody can pay for.
                $canAffordToDisbelieve = ! $this->costBudget->reachedFraction(
                    $telegramUpdateId,
                    (float) config('product-images.source_exploration_budget_fraction', 0.70),
                );

                $measuredStatic = $this->usableStaticGallerySize($pageScout, $context);
                $requiredImages = max(1, (int) ($context['minimum_verified_images'] ?? 3));

                if (
                    $preflightDecision === 'static_sufficient'
                    && ($preflight['gallery_likely'] ?? false)
                    && $this->settings->galleryPreferPlaywrightFirst()
                ) {
                    if ($measuredStatic >= $requiredImages) {
                        // The distrust below is aimed at the agent's *count*,
                        // which markup inflates. This is not that count: it is
                        // the browser's own intrinsic width for each image the
                        // page loaded, inside the media area, deduplicated by
                        // asset key so renditions of one photo count once - the
                        // exact failure the distrust was written for. Measured
                        // evidence beats an estimate, in both directions.
                        $debug?->__invoke(
                            'step',
                            "Замер подтвердил предфильтр: {$measuredStatic} разных фото уже нужного размера, обучение не требуется: ".$url,
                        );
                    } elseif ($canAffordToDisbelieve) {
                        $debug?->__invoke(
                            'step',
                            'Предфильтр сказал "статики достаточно", но найдена настоящая галерея - обучаю Playwright-рецепт вместо доверия оценке количества фото: '.$url,
                        );
                        $preflightDecision = 'train_playwright';
                    } else {
                        $debug?->__invoke(
                            'step',
                            'Бюджет на исходе: беру статичную галерею как есть, не обучая рецепт: '.$url,
                        );
                    }
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

            if ($preflightDecision === 'interrupted') {
                $version->update([
                    'status' => 'interrupted',
                    'result' => ['preflight' => $preflight],
                    'error' => $preflight['reason'] ?? null,
                ]);

                // Provider/transport/schema failure is not evidence about
                // the page. Preserve real observations as provisional
                // candidates, but do not train or publish a semantic rule.
                return collect([
                    ...($pageScout['network_image_samples'] ?? []),
                    ...($context['static_image_urls'] ?? []),
                ])
                    ->filter(fn (mixed $url): bool => is_string($url) && $url !== '')
                    ->map(fn (string $url): string => ProductImageStorage::normalizeCandidateUrl($url))
                    ->unique(fn (string $url): string => ProductImageStorage::imageAssetKey($url))
                    ->take(20)->values()->all();
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

            // Rounds that were paid for and then thrown away.
            //
            // A training stopped by the per-source budget share used to write
            // nothing but "deferred" - no candidate, no round history, no
            // reason any of them was rejected - so the next search started this
            // domain at round one again. cdw.com was trained three times on
            // three days, four rounds each, every round returning a real
            // gallery, and arrived at the fourth search knowing nothing.
            //
            // A share of the budget is the right way to stop one domain eating
            // a whole search. Losing the work when it triggers is not.
            $resumed = $repairFrom === [] ? $this->interruptedTrainingProgress($recipe) : [];
            $attempts = $resumed['attempts'] ?? [];
            // A stored recipe that stopped working is not a blank page. It
            // opened this site correctly a dozen times, and what changed is
            // knowable: the selector that matched nothing, the traversal that
            // stopped early, the frames that came back too small. Starting the
            // agent from nothing threw all of that away and paid for a full
            // rediscovery of a site we already knew - and produced a second
            // recipe beside the first rather than a better one.
            //
            // Seeded as the first round's feedback, so round one is a repair
            // with evidence rather than a guess.
            $feedback = $repairFrom === [] ? null : [
                'rejected_recipe' => $repairFrom['recipe'] ?? null,
                'error' => 'This stored recipe stopped working on this page: '
                    .($repairFrom['reason'] ?? 'unknown reason'),
                'action_trace' => $repairFrom['action_trace'] ?? [],
                'diagnostics' => $repairFrom['diagnostics'] ?? [],
                'candidate_count' => count($repairFrom['images'] ?? []),
                'instruction' => 'This recipe already works on other pages of this site. Repair the step that '
                    .'failed here rather than describing the gallery from scratch, and keep every step that '
                    .'still executed correctly - a replacement that only fits this page breaks the pages the '
                    .'recipe already opens.',
            ];

            if ($feedback === null && ($resumed['feedback'] ?? null) !== null) {
                $feedback = $resumed['feedback'];
                $debug?->__invoke(
                    'step',
                    'Продолжаю прерванное обучение: раундов из прошлой сессии — '.count($attempts).'.',
                );
            }
            $candidate = [];
            $candidateResult = [];
            $candidateImages = [];
            $bestPartialImages = $oldImages;
            $bestPartialResult = $oldImages === [] ? [] : ['images' => $oldImages];
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
            $emptyRounds = 0;
            $identicalFailures = 0;
            $lastFailureSignature = null;
            $requestWasRejected = false;
            $photoOutcome = $this->previousPhotoOutcome($host, $url);
            $stalled = false;
            $budgetDeferred = false;
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
                        "AI-тренер: {$host} израсходовал свою долю бюджета этого поиска после {$attempt} раунд(а/ов); "
                            .'работу сохраняю, следующий поиск продолжит с этого места.',
                    );
                    // Breaks rather than returns, so the ordinary finalization
                    // below writes the candidate and the whole round history to
                    // the version - the same record a training that ran out of
                    // rounds leaves. It returned here once, and everything the
                    // rounds had learned went with it.
                    $budgetDeferred = true;

                    break;
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
                    // A round that repeats the previous round's outcome gets the
                    // whole page back: the short version is an economy, and an
                    // economy must never be the reason the agent is stuck.
                    'page' => $this->scoutForAgent($pageScout, $stagnantRounds > 0),
                    'execution_contract' => [
                        'browser_state' => 'fresh_page_load_for_every_recipe_execution',
                        'recipe_must_be_self_contained' => true,
                        'post_interaction_dom_is_diagnostic_only' => true,
                        'required_behavior' => 'Replay every successful prerequisite action before using controls revealed by it.',
                    ],
                    // What became of the photographs the last recipe for this
                    // shop actually produced. Without it the agent optimises
                    // "extract N images" and never learns that all twenty were
                    // 370px and were thrown away on arrival - it cannot correct
                    // a mistake nobody tells it about. Costs a few dozen tokens
                    // against the twenty-four thousand the page markup costs.
                    'previous_photo_outcome' => $photoOutcome,
                    // What is left to spend. Without it the agent cannot tell a
                    // first round from a last one - it would keep proposing
                    // careful multi-step plans with seconds remaining, and the
                    // decision to stop was always taken for it by a counter in
                    // this file. It has the tool to abandon a page; this is the
                    // information that makes that its decision rather than ours.
                    'remaining_budget' => [
                        'rounds_left' => $safetyLimited ? max(0, $safetyRounds - $attempt) : null,
                        'seconds_left' => $this->timeBudget->remainingWorkingSeconds($telegramUpdateId),
                        'money_spent_fraction' => $this->costBudget->spentFraction($telegramUpdateId),
                        'instruction' => 'When little is left, prefer the smallest plan that could work over the '
                            .'thorough one, and abandon the page yourself if the evidence says it cannot succeed - '
                            .'a round spent here is a round the next source does not get.',
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
                // Training started from the Filament recipe screen carries no
                // Telegram update, and ai_runs.telegram_update_id used to be
                // NOT NULL - so those rounds were skipped here entirely and
                // operator-triggered retraining cost nothing visible in the
                // audit trail. The column is nullable now, so every round is
                // recorded regardless of what triggered it.
                $run = AiRun::query()->create([
                    'telegram_update_id' => $telegramUpdateId,
                    'provider' => $provider,
                    'model' => $model,
                    'status' => 'running',
                    'prompt' => $prompt ?: '{}',
                    'started_at' => now(),
                ]);
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
                            visionImageUrls: $this->observedImageUrls($pageScout, $feedback),
                        )->prompt(
                            $prompt ?: '{}',
                            // The media type is not optional: without it the
                            // data URL is malformed and the provider rejects
                            // the whole request with a 400 - which it did, on
                            // every round of every training, the moment the
                            // screenshot was added. The runner writes a PNG.
                            // Dropped after a technical failure so the next call
                            // can get through: a rejected request never reaches
                            // the model, so the agent is left with silence
                            // instead of the error it could have reasoned about.
                            // The picture is an aid; being heard is not.
                            attachments: $pageImage === null || $requestWasRejected
                                ? []
                                : [Image::fromBase64(base64_encode($pageImage), 'image/png')->as('page.png')],
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
                    // A call that got through settles the question the counter
                    // was asking. Left standing, one transient provider error
                    // took the screenshot away for the rest of the training and
                    // brought the breaker one round closer for reasons that had
                    // already stopped being true.
                    $identicalFailures = 0;
                    $lastFailureSignature = null;
                    $requestWasRejected = false;
                } catch (Throwable $exception) {
                    $run?->update([
                        'status' => 'failed',
                        'error' => mb_substr($exception->getMessage(), 0, 5000),
                        'completed_at' => now(),
                    ]);
                    $failureKind = $this->failureKindForException($exception);
                    $attempts[] = ['attempt' => $attempt, 'error' => 'ai_call_failed: '.$exception->getMessage()];
                    $feedback = ['error' => 'The previous attempt failed to produce a response: '.mb_substr($exception->getMessage(), 0, 300)];

                    // A round is retried because the next one might go
                    // differently. The same call failing the same way is not
                    // that: a malformed request stays malformed, and retrying
                    // it only spends rounds. Seen live - a missing media type
                    // on an attachment made the provider answer 400, and the
                    // loop asked thirty-five more times in twenty seconds.
                    $failureSignature = $this->technicalFailureSignature($exception);
                    $identicalFailures = $failureSignature === $lastFailureSignature
                        ? $identicalFailures + 1
                        : 1;
                    $lastFailureSignature = $failureSignature;
                    // Only a request the provider refused to read is a reason to
                    // send the next one without its picture. A timeout, a rate
                    // limit or a dropped connection says nothing about the
                    // attachment, and dropping it there cost the agent its view
                    // of the page for the rest of the training over a fault that
                    // had nothing to do with it.
                    $requestWasRejected = $requestWasRejected || $this->looksLikeRejectedRequest($exception);

                    if ($identicalFailures >= self::MAX_IDENTICAL_TECHNICAL_FAILURES) {
                        $debug?->__invoke(
                            'warning',
                            "AI-тренер: раунд {$roundLabel} не удался с той же ошибкой {$identicalFailures} раза подряд "
                                ."({$exception->getMessage()}); это не лечится повтором, прекращаю обучение на этом URL.",
                        );

                        break;
                    }

                    $debug?->__invoke('warning', "AI-тренер: раунд {$roundLabel} не удался ({$exception->getMessage()}), пробую следующий раунд.");

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
                    $candidate = $this->validateRecipe($response->toArray(), (string) ($pageScout['title'] ?? ''));

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
                // Different valid interaction plans for the same product page
                // may expose complementary gallery frames. Preserve the union
                // of URLs actually observed across rounds instead of keeping
                // only the largest individual round. Downstream verification
                // still decides which candidates are safe to store.
                $mergedPartialImages = collect([
                    ...$bestPartialImages,
                    ...$candidateImages,
                ])
                    ->filter(fn (mixed $image): bool => is_string($image) && $image !== '')
                    ->unique(fn (string $image): string => ProductImageStorage::imageAssetKey($image))
                    ->values()
                    ->all();
                if (count($mergedPartialImages) > count($bestPartialImages)) {
                    $bestPartialImages = $mergedPartialImages;
                    $bestPartialResult = [
                        ...$candidateResult,
                        'images' => $bestPartialImages,
                    ];
                }
                $contextMinimum = max(0, (int) ($context['minimum_verified_images'] ?? 0));
                $validation = $this->resultValidator->validate(
                    $candidate,
                    $candidateResult,
                    minimumSuccessCount: $contextMinimum > 0 ? $contextMinimum : null,
                );
                // Only worth the bandwidth once the plan itself is sound: a
                // recipe already going back for structural repair learns
                // nothing extra from the size of frames it will not keep.
                $downloadProbe = $validation['passed'] && $candidateImages !== []
                    ? $this->measureDownloadableFrames($candidateImages, $context, $url)
                    : null;
                // A gallery of thumbnails is not a working recipe, however
                // complete its traversal. Promoting one is how a domain came to
                // hold a "proven" recipe that had never put a photograph in the
                // catalog.
                // Fetched, not merely attempted. A frame that could not be
                // downloaded at all - DNS, a timeout, a 403 - says nothing about
                // what the recipe collects, and reading it as "this gallery is
                // unpublishable" would be exactly the masking of a technical
                // failure as a verdict that the strategy forbids.
                $yieldsNothingUsable = $downloadProbe !== null
                    && $downloadProbe['fetched'] > 0
                    && $downloadProbe['usable'] === 0;

                // Nothing usable was too weak a bar, and the gap between it and
                // the truth is where a whole gallery goes missing.
                //
                // cdw.com: the recipe collected nine frames, the probe found one
                // of four publishable, and that passed - so the recipe was
                // promoted, the search downloaded all nine, and one photograph
                // reached the catalog. The agent was gone by then and learned
                // none of it. A recipe that yields one publishable frame in four
                // is collecting thumbnails just as surely as one that yields
                // none; it simply has a stray full-size frame among them.
                //
                // Measured against what a gallery has to be worth, not against
                // zero: the probe's rate applied to the frames this recipe
                // actually collected, compared with the minimum this search
                // needs. Both conditions must hold - a majority unpublishable,
                // AND the extrapolation falling short - so one odd small frame
                // in a good gallery is not a verdict.
                $publishable = $downloadProbe !== null && $downloadProbe['fetched'] > 0
                    ? $downloadProbe['usable'] / $downloadProbe['fetched']
                    : null;
                $minimumGallery = max(1, $contextMinimum > 0
                    ? $contextMinimum
                    : $this->settings->galleryMinSuccessCount());
                $expectedKeepers = $publishable === null
                    ? null
                    : (int) floor(count($candidateImages) * $publishable);
                $yieldsTooFewToPublish = $publishable !== null
                    && $downloadProbe['fetched'] > $downloadProbe['usable'] * 2
                    && $expectedKeepers < $minimumGallery;

                if ($yieldsNothingUsable || $yieldsTooFewToPublish) {
                    $measured = collect($downloadProbe['rejected'])->map(
                        fn (int $count, string $reason): string => $reason.' x'.$count,
                    )->implode('; ');
                    $sizes = implode(', ', $downloadProbe['samples']) ?: 'none';
                    $validation = [
                        'passed' => false,
                        'expected' => $validation['expected'],
                        'extracted' => $validation['extracted'],
                        'reason' => $yieldsNothingUsable
                            ? 'Every measured frame was rejected by the download rules ('.$measured
                                .'). Observed sizes: '.$sizes.'.'
                            : 'Only '.$downloadProbe['usable'].' of '.$downloadProbe['fetched']
                                .' measured frames can be published ('.$measured.'). At that rate the '
                                .count($candidateImages).' frames this recipe collects would leave about '
                                .$expectedKeepers.' in the catalog, and this search needs '.$minimumGallery
                                .'. Observed sizes: '.$sizes.'.',
                    ];
                }

                $promote = $validation['passed']
                    && (count($oldImages) < 2 || count($candidateImages) >= count($oldImages));
                $attempts[] = [
                    'attempt' => $attempt,
                    'download_probe' => $downloadProbe,
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

                // Counted for the round report, and for nothing else.
                //
                // This used to end the page after three of them. It was written
                // for a canvas-rendered viewer that no selector can ever reach -
                // a real case - but the evidence it acted on was "no image yet",
                // which is also what the first rounds of an ordinary hard page
                // look like. Reaching a genuinely new DOM state and collecting
                // nothing from it is progress towards a recipe, not proof there
                // is nothing to find, and a fourth round did produce the gallery
                // where this rule had already given up. What bounds a page that
                // truly cannot be scraped is the stagnation rule above - three
                // rounds with no new state at all - plus the round cap, the time
                // and money budgets, and the agent's own tool for abandoning it.
                $emptyRounds = count($candidateImages) === 0 ? $emptyRounds + 1 : 0;

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
                    // The pixels behind the URLs this round produced, measured
                    // by the downloader that decides what the catalog keeps.
                    // Without it the agent optimised for collecting URLs and
                    // never learned that a whole gallery of them was too small
                    // to publish.
                    'downloaded_frames' => $downloadProbe === null ? null : [
                        ...$downloadProbe,
                        'instruction' => ($yieldsNothingUsable || $yieldsTooFewToPublish)
                            ? 'Too few of these frames can be published at these sizes for this gallery to be '
                                .'worth keeping. Look for the full-size source behind the same photographs - a zoom '
                                .'or lightbox control, a data attribute holding a larger rendition, or a URL '
                                .'parameter the page itself uses for the large view. Collecting more thumbnails '
                                .'does not help; the same photographs at their real size do.'
                            : 'These are the real sizes the downloader measured for the frames you collected.',
                    ],
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
                'status' => match (true) {
                    $promote => 'promoted',
                    // Not a verdict on the recipe: the session was rationed, not
                    // judged. The row keeps the candidate and the round history
                    // so the next search resumes from it.
                    $budgetDeferred => 'deferred',
                    $hasPartial => 'partial',
                    default => 'rejected',
                },
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
                    $budgetDeferred => 'Обучение отложено: этот источник израсходовал свою долю денежного бюджета текущего поиска. Кандидат и история раундов сохранены.',
                    $stalled => 'Обучение остановлено: три последовательных раунда не дали материального прогресса.',
                    $agentAbandoned => 'Обучение остановлено по решению AI-агента: '.($agentAbandonReason ?? ''),
                    $stuckOnValidation => 'Обучение остановлено: '.self::MAX_IDENTICAL_VALIDATION_FAILURES.' раунда подряд одна и та же ошибка валидации ('.implode(', ', $lastValidationSignature ?? []).').',
                    default => 'Все разрешённые раунды завершены без полной подтверждённой галереи.',
                },
            ]);

            if (! $promote && ! $budgetDeferred) {
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

            // A repaired recipe replaces one that already worked elsewhere on
            // this site, and nothing ever checked that it still does. A fix
            // shaped around the page in front of the agent can quietly break
            // every other page the recipe opened - and the breakage only
            // surfaces later, as a domain that mysteriously stopped producing
            // photographs. So it is tried once on a page it is known to have
            // worked on, and a failure there keeps the version that works.
            $canary = $this->canaryFailure($recipe, $candidate, $url, $telegramUpdateId, $debug);

            if ($canary !== null) {
                // Two recipes, both correct, for two page families of one shop.
                //
                // The candidate opens the page in front of it and breaks the
                // page the stored one already opened, which is exactly the
                // evidence that this shop does not have a single template. Now
                // that a recipe belongs to the whole shop, simply rejecting the
                // candidate would leave that family permanently unopenable -
                // and promoting it would break the rest of the site on the next
                // search, then be repaired back, forever.
                //
                // So the shop keeps the recipe that still works everywhere
                // else, and this family gets its own, which recipeForUrl()
                // prefers where it applies. This is the one way a narrower
                // scope is created, and it is created from evidence rather than
                // from the shape of a URL.
                $narrower = $this->recipeRouter->recipeForTraining($url, scopeToPath: true);

                if ($narrower->is($recipe)) {
                    $version->update([
                        'status' => 'rejected',
                        'promoted_at' => null,
                        'error' => 'Починенный рецепт сломал страницу, где прежний работал: '.$canary,
                    ]);
                    $debug?->__invoke(
                        'warning',
                        'Новая версия рецепта не прошла проверку на прежде рабочей странице ('.$canary
                            .'); оставляю прежнюю версию без изменений.',
                    );

                    return $oldImages;
                }

                $narrower->update([
                    'recipe' => $this->withoutTrainingCounts($candidate),
                    'status' => 'active',
                    'region' => $this->regionForUrl($url),
                    'sample_path' => mb_substr((string) (parse_url($url, PHP_URL_PATH) ?: '/'), 0, 1024),
                    'layout_fingerprint' => $layoutFingerprint,
                    'last_observed_layout_fingerprint' => $layoutFingerprint,
                    'success_count' => $narrower->success_count + 1,
                    'consecutive_hard_blocks' => 0,
                    'hard_block_urls' => [],
                    'last_success_at' => now(),
                    'last_error' => null,
                    'last_failure_kind' => null,
                    'retry_after' => null,
                    'source_blocked' => false,
                    'source_block_reason' => null,
                    'source_blocked_at' => null,
                ]);
                $version->update([
                    'product_gallery_recipe_id' => $narrower->id,
                    'status' => 'promoted',
                    'promoted_at' => now(),
                    'error' => 'Рецепт всего магазина не заменён: он ломается на '.$canary
                        .'. Эта версия сохранена как отдельный рецепт для семейства страниц '
                        .$narrower->path_pattern.'.',
                ]);
                $debug?->__invoke(
                    'done',
                    'Этот раздел сайта устроен иначе, чем остальной: рецепт магазина оставлен как есть, '
                        .'а для '.$narrower->path_pattern.' сохранён отдельный. Фото: '.count($candidateImages).'.',
                );

                return $candidateImages;
            }

            $recipe->update([
                'recipe' => $this->withoutTrainingCounts($candidate),
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
                // Nothing in the codebase used to clear source_blocked, so a
                // single CAPTCHA left the domain without Playwright forever even
                // after it demonstrably let the browser back in. A completed,
                // validated training run is that demonstration.
                'source_blocked' => false,
                'source_block_reason' => null,
                'source_blocked_at' => null,
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

            $debug?->__invoke('error', 'AI-тренер: '.$exception->getMessage().' · '.$url);
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

    /**
     * What an interrupted training session already learned about this domain.
     *
     * A session stopped by the per-source budget share is rationed, not judged:
     * its rounds ran, cost money and produced real candidates. cdw.com was
     * trained on three separate days, four rounds each, every round returning a
     * gallery - and each new search began at round one, because the stop wrote
     * nothing but the word "deferred".
     *
     * The stored history is handed back as round one's feedback, so the next
     * session continues the argument instead of restarting it. Only the last
     * few rounds, and only the parts an agent can act on: the whole history
     * carries page diagnostics measured in hundreds of kilobytes.
     *
     * @return array{attempts: array<int, mixed>, feedback: array<string, mixed>|null}
     */
    private function interruptedTrainingProgress(ProductGalleryRecipe $recipe): array
    {
        $version = ProductGalleryRecipeVersion::query()
            ->where('product_gallery_recipe_id', $recipe->id)
            ->where('status', 'deferred')
            ->where('created_at', '>=', now()->subDays(7))
            ->latest('id')
            ->first();

        $attempts = is_array($version?->result['attempts'] ?? null) ? $version->result['attempts'] : [];

        if ($attempts === []) {
            return ['attempts' => [], 'feedback' => null];
        }

        $attempts = array_slice($attempts, -3);
        $last = end($attempts);

        return [
            'attempts' => $attempts,
            'feedback' => [
                'rejected_recipe' => $last['selectors_tried'] ?? null,
                'candidate_count' => (int) ($last['candidate_count'] ?? 0),
                'downloaded_frames' => $last['download_probe'] ?? null,
                'error' => 'A previous training session on this domain was stopped part-way because it had spent '
                    .'its share of that search\'s budget - not because these recipes were wrong. Its last rounds '
                    .'are in attempt_history.',
                'instruction' => 'Continue that work rather than starting over: the selectors and actions already '
                    .'tried are in attempt_history with the reason each was rejected. Correct the step that failed, '
                    .'keep the steps that worked, and do not re-propose a recipe that history already shows failing.',
            ],
        ];
    }

    /**
     * Every selector the recipe will run, wherever it is stored.
     *
     * @param  array<string, mixed>  $recipe
     * @return array<int, string>
     */
    private function selectorsIn(array $recipe): array
    {
        $selectors = [];

        foreach (['collect_selectors', 'exclude_selectors', 'thumbnail_selectors', 'next_selectors', 'pre_click_selectors'] as $key) {
            foreach (is_array($recipe[$key] ?? null) ? $recipe[$key] : [] as $selector) {
                if (is_string($selector)) {
                    $selectors[] = $selector;
                }
            }
        }

        foreach (is_array($recipe['actions'] ?? null) ? $recipe['actions'] : [] as $action) {
            foreach (['selector', 'after_each_selector'] as $key) {
                if (is_string($action[$key] ?? null)) {
                    $selectors[] = $action[$key];
                }
            }
        }

        return $selectors;
    }

    /**
     * The words a phrase is made of, for comparing one against another.
     *
     * @return array<int, string>
     */
    private function significantWords(string $text): array
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_unique(array_filter(
            $words,
            fn (string $word): bool => mb_strlen($word) >= 3,
        )));
    }

    /**
     * A recipe is how to open a gallery, so it must not name the product.
     *
     * Live on dell.com: a page held two colourways in one gallery block, and
     * the agent separated them the only way it saw - by putting the product's
     * own name inside the selector:
     *
     *     button[aria-label*="Notebook Dell 14 Premium con touch-screen"]
     *
     * That matches on exactly one page of one shop in one language. Every
     * other Dell laptop trains a second recipe beside it, and the domain
     * accumulates a recipe per product instead of one that opens the site.
     * The page already marked the right group structurally - aria-checked on
     * the selected swatch - which is the same fact expressed in a way that
     * survives the next product.
     *
     * Detected by comparing against the page's own title rather than against
     * any list of shops or models: a literal inside a selector that shares
     * three or more words with the product's title is describing this product,
     * whatever the shop or the language.
     *
     * @param  array<string, mixed>  $recipe
     */
    private function rejectProductSpecificSelectors(array $recipe, string $productPageTitle): void
    {
        $titleWords = $this->significantWords($productPageTitle);

        if (count($titleWords) < 3) {
            return;
        }

        foreach ($this->selectorsIn($recipe) as $selector) {
            // Quotes are paired by kind rather than by proximity. Matching any
            // quote to any other let a short literal earlier in the selector
            // swallow the opening quote of the long one after it, so
            // [aria-label^="Thumbnail "]:not([aria-label*="<the product>"])
            // read as one harmless fragment and the name inside the exclusion
            // was never seen.
            preg_match_all('/"([^"]*)"|\'([^\']*)\'/u', $selector, $matches, PREG_SET_ORDER);

            foreach ($matches as $match) {
                $literal = $match[2] ?? '';
                $literal = $literal !== '' ? $literal : ($match[1] ?? '');

                if (mb_strlen($literal) < 12) {
                    continue;
                }

                $literalWords = $this->significantWords($literal);
                $shared = array_intersect($literalWords, $titleWords);

                if (count($literalWords) >= 3 && count($shared) >= 3) {
                    throw new InvalidGalleryRecipeException(
                        'Селектор описывает конкретный товар, а не устройство галереи: "'.mb_substr($literal, 0, 80).'".',
                        'The selector '.mb_substr($selector, 0, 200).' matches on this product\'s own name ('
                            .implode(', ', array_slice($shared, 0, 5)).'), which exists on this page and no other. '
                            .'A recipe is how to open and walk this shop\'s gallery, so it must survive the next '
                            .'product. When a gallery block holds more than one variant, select the active group by '
                            .'the state the page itself marks it with - aria-checked="true", aria-selected="true", '
                            .'[data-group] on the chosen swatch, a class the page adds to the selected group - and '
                            .'never by the words naming the product.',
                        ['selector_names_the_product'],
                    );
                }
            }
        }
    }

    /**
     * The recipe as it leaves training, with what it counted left behind.
     *
     * expected_image_count is the agent saying "this page shows seven photos".
     * That is true, useful, and checked - on the page it was learned on. It is
     * meaningless on the next product, and the validator has to be told so
     * through a boolean at every call site. It was told wrong once already, and
     * a recipe that carries a number is a recipe someone will read the number
     * from.
     *
     * So the domain's stored recipe does not carry it at all. The version row
     * still does - that is the audit trail of what the agent believed while it
     * was learning, and it is not what later products are opened with.
     *
     * The traversal bounds stay. They are ceilings rather than targets - see
     * traversalCeiling() in the browser runner - and zero among them is a
     * structural statement ("this gallery has no carousel") that must survive.
     *
     * @param  array<string, mixed>  $candidate
     * @return array<string, mixed>
     */
    private function withoutTrainingCounts(array $candidate): array
    {
        unset($candidate['expected_image_count']);

        return $candidate;
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
    /**
     * Keep the Vision tool constrained to URLs the browser already exposed in
     * the sanitized initial page or the previous post-interaction observation.
     * Page content may influence the agent, but it cannot turn the tool into an
     * arbitrary URL fetcher.
     *
     * @return array<int, string>
     */
    private function observedImageUrls(mixed ...$observations): array
    {
        $urls = [];
        $walk = function (mixed $value) use (&$walk, &$urls): void {
            if (is_array($value)) {
                foreach ($value as $child) {
                    $walk($child);
                }

                return;
            }

            if (is_string($value) && filter_var($value, FILTER_VALIDATE_URL) !== false) {
                $urls[] = $value;
            }
        };

        foreach ($observations as $observation) {
            $walk($observation);
        }

        return collect($urls)
            ->map(fn (string $url): string => ProductImageStorage::normalizeCandidateUrl($url))
            ->unique()
            ->take(80)
            ->values()
            ->all();
    }

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
                'expected_image_count' => ['required', 'integer', 'min:0'],
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
                'decision' => 'interrupted',
                'page_kind' => 'unknown',
                'gallery_likely' => false,
                'hidden_images_likely' => false,
                'interaction_required' => false,
                'expected_image_count' => count($payload['static_image_urls']),
                'evidence' => [],
                'confidence' => 0,
                'reason' => 'AI preflight failed: '.$exception->getMessage(),
            ];
            $status = 'interrupted';
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

    /**
     * How many times the same technical failure may repeat before the URL is
     * given up on. A round is retried because the next one might go
     * differently; a malformed request, a revoked key or a missing model will
     * fail identically forever, and the agent never even sees it - the call
     * does not reach the model, so there is nobody to reason about it.
     */
    /**
     * How many of a round's frames are actually fetched to see what the
     * downloader makes of them. A sample answers "are these publishable at
     * all" without paying to download a whole gallery on every round.
     */
    private const DOWNLOAD_PROBE_SAMPLE = 4;

    private const MAX_IDENTICAL_TECHNICAL_FAILURES = 3;

    /**
     * How many entries of each ranked page list reach the agent on a round that
     * is still making progress. The counts an omitted-tail marker reports back,
     * so the agent knows it is looking at a head and can ask the round to widen
     * by failing to progress - see scoutForAgent().
     */
    private const AGENT_PAGE_LIST_LIMITS = [
        'image_candidates' => 20,
        'action_candidates' => 24,
        'fragments' => 16,
        'network_image_samples' => 12,
    ];

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
    public function recordConfirmedDownloadFailure(string $domainOrUrl, ?callable $debug = null): bool
    {
        $isUrl = filter_var($domainOrUrl, FILTER_VALIDATE_URL) !== false;
        $recipe = $isUrl
            ? $this->recipeRouter->activeRecipeForUrl($domainOrUrl)
            : ProductGalleryRecipe::query()
                ->where('domain', strtolower($domainOrUrl))
                ->where('status', 'active')
                ->orderByDesc('success_count')
                ->first();

        if (! $recipe) {
            return false;
        }

        $domain = $recipe->domain;

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
    public function recordConfirmedDownloadSuccess(string $domainOrUrl): void
    {
        $recipeId = filter_var($domainOrUrl, FILTER_VALIDATE_URL) !== false
            ? $this->recipeRouter->activeRecipeForUrl($domainOrUrl)?->id
            : null;
        $query = ProductGalleryRecipe::query()
            ->where('status', 'active')
            ->where('last_failure_kind', self::DOWNLOAD_FAILURE_KIND)
            ->where('failure_count', '>', 0);

        if ($recipeId !== null) {
            $query->whereKey($recipeId);
        } else {
            $query->where('domain', strtolower($domainOrUrl));
        }

        $query->update(['failure_count' => 0]);
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

        // A challenge page looks identical whether the WAF is reacting to this
        // IP's recent request volume or refusing headless browsers outright, so
        // the page itself cannot tell the two apart - but the domain's own
        // history can. Playwright that has already succeeded here proves the
        // browser is acceptable to this site, which leaves reputation/rate as
        // the explanation, and that decays on its own. A domain that has never
        // once let Playwright through gets the original treatment, because
        // waiting for a fingerprint block to expire never ends.
        $playwrightWorkedHereBefore = $kind === 'access_gate' && ProductGalleryRecipe::query()
            ->where('domain', $recipe->domain)
            ->where('success_count', '>', 0)
            ->exists();

        $disableAfter = match (true) {
            $playwrightWorkedHereBefore => 4,
            $kind === 'access_gate' => 1,
            in_array($kind, ['recipe_mismatch', 'dom_unusable', 'agent_abandoned'], true) => 2,
            in_array($kind, ['browser_timeout', 'browser_protocol'], true) => 3,
            default => PHP_INT_MAX,
        };
        $disable = $failureCount >= $disableAfter;

        $retryAfter = $disable ? null : match (true) {
            // Backs off far harder than the other kinds: hammering a WAF is how
            // a soft challenge turns into a real IP ban.
            $kind === 'access_gate' => now()->addMinutes(min(1440, 30 * (4 ** max(0, $hardBlockCount - 1)))),
            $kind === 'rate_limited' => now()->addMinutes(30),
            in_array($kind, ['browser_timeout', 'browser_protocol', 'host_unreachable'], true) => now()->addMinutes(min(60, 2 ** min(5, $failureCount))),
            in_array($kind, ['browser_unavailable', 'browser_process'], true) => now()->addMinutes(15),
            in_array($kind, ['ai_timeout', 'ai_rate_limited'], true) => now()->addMinutes(15),
            in_array($kind, ['recipe_mismatch', 'dom_unusable', 'agent_abandoned'], true) => now()->addMinutes(10),
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
        // Blocking the source is the widest decision this method can take - it
        // takes Playwright away from every path of the domain - so it is now
        // reserved for the case that actually earns it: a gate we have decided
        // to stop retrying. While the recipe is merely paused, the domain stays
        // usable and simply waits out its backoff.
        $sourceBlock = $kind === 'access_gate' && $disable ? [
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
                "Playwright для {$recipe->domain} отключён: {$disableReason} HTML-поиск не отключён. · {$url}",
            );
        }
    }

    /**
     * The provider refused to read the request at all - a malformed payload, a
     * rejected attachment - as opposed to failing to answer one it understood.
     * Only the first kind is worth retrying without the picture.
     */
    private function looksLikeRejectedRequest(Throwable $exception): bool
    {
        $message = strtolower($exception->getMessage());

        if (str_contains($message, '429') || str_contains($message, 'rate limit')) {
            return false;
        }

        return preg_match('/\b4\d{2}\b/', $message) === 1
            || str_contains($message, 'invalid_request')
            || str_contains($message, 'unsupported')
            || str_contains($message, 'malformed');
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
    private function validateRecipe(array $data, string $productPageTitle = ''): array
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
            ->map(fn (array $action): array => $this->sanitizedAction($action))
            ->values()
            ->all();

        $data['attributes'] = collect($data['attributes'] ?? [])
            ->map(fn (string $attribute): string => strtolower(trim($attribute)))
            ->unique()->values()->all();

        if (($data['training_decision'] ?? 'propose_recipe') === 'propose_recipe' && $data['collect_selectors'] === []) {
            throw new RuntimeException('AI не вернул безопасный селектор сбора изображений.');
        }

        // Last, so it sees the selectors as they will actually run - after the
        // unsafe ones are dropped and the redundant ones folded away.
        $this->rejectProductSpecificSelectors($data, $productPageTitle);

        return $data;
    }

    /**
     * Every field of one action, and nothing else, on its way to the browser.
     *
     * The set is read off the validation rules rather than written out a second
     * time. A hand-kept copy is how after_each_selector came to be asked for in
     * the prompt, accepted by the rules and checked by the validator while the
     * browser never once received it: the field was added to the contract and
     * to everything that reads it, and the one list that had to repeat it was
     * missed. Whatever the rules admit now passes; anything invented still does
     * not, which is the only reason this list exists.
     *
     * @param  array<string, mixed>  $action
     * @return array<string, mixed>
     */
    private function sanitizedAction(array $action): array
    {
        $sanitized = [];

        foreach ($this->recipeActionFields() as $field) {
            $value = $action[$field] ?? null;
            $sanitized[$field] = is_string($value) ? trim($value) : $value;
        }

        // A step the page only sometimes puts up - a consent wall a returning
        // visitor no longer sees, a region or age gate. Absent means skipped,
        // not failed, so one recipe holds for a first visit and for every later
        // one. Anything else, including a missing value, stays mandatory.
        if (array_key_exists('when', $sanitized)) {
            $sanitized['when'] = $sanitized['when'] === 'if_present' ? 'if_present' : 'always';
        }

        // The one check the rules cannot make: a selector may be well-formed and
        // still unsafe. An unsafe follow-up drops with its own settings, never
        // leaving a limit pointing at a selector that will not run.
        if (array_key_exists('after_each_selector', $sanitized)
            && ! (is_string($sanitized['after_each_selector']) && $this->safeSelector($sanitized['after_each_selector']))) {
            $sanitized['after_each_selector'] = null;
            $sanitized['after_each_limit'] = null;
            $sanitized['after_each_wait_after_ms'] = null;
        }

        return $sanitized;
    }

    /**
     * The action fields the contract admits, in declaration order.
     *
     * @return array<int, string>
     */
    public function recipeActionFields(): array
    {
        return collect(array_keys($this->recipeValidationRules()))
            ->filter(fn (string $rule): bool => str_starts_with($rule, 'actions.*.'))
            ->map(fn (string $rule): string => substr($rule, strlen('actions.*.')))
            ->values()
            ->all();
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
            'expected_image_count' => ['required', 'integer', 'min:0'],
            'expected_count_evidence' => ['required', 'string', 'max:500'],
            'content_confirmed_product' => ['required', 'boolean'],
            'actions' => ['present', 'array', 'max:12'],
            'actions.*.kind' => ['required', 'in:click,click_each,click_until_no_change'],
            'actions.*.selector' => ['required', 'string', 'max:300'],
            'actions.*.index' => ['required', 'integer', 'between:0,20'],
            'actions.*.limit' => ['required', 'integer', 'between:1,20'],
            'actions.*.wait_after_ms' => ['required', 'integer', 'between:50,1500'],
            'actions.*.when' => ['nullable', 'in:always,if_present'],
            'actions.*.after_each_selector' => ['nullable', 'string', 'max:300'],
            'actions.*.after_each_limit' => ['nullable', 'integer', 'between:1,20'],
            'actions.*.after_each_wait_after_ms' => ['nullable', 'integer', 'between:50,1500'],
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
    /**
     * How much of the page the agent is shown.
     *
     * Measured on a real training call: the payload was 109,000 characters, and
     * three fields carried 72% of it - 50 image candidates, 54 clickable
     * candidates, 32 DOM fragments. Across the catalog the expensive model's
     * input outweighed its output 46 to 1, so the bill is almost entirely what
     * we hand over rather than what it thinks about. A recipe has to recognise
     * the shape of a gallery, and that shape is already visible in the first
     * few of each - the fiftieth image candidate has never been what decided a
     * selector.
     *
     * Every list here arrives ranked by the runner, so trimming takes the tail,
     * not a sample. And it steps aside entirely the moment it might be the
     * problem: see the caller, where a round that made no progress is given the
     * whole page back before it tries again.
     *
     * @param  array<string, mixed>  $pageScout
     * @return array<string, mixed>
     */
    /**
     * How many distinct, large-enough product photographs the page already
     * showed without anyone clicking anything.
     *
     * This is not the agent's expected_image_count - that one is read off the
     * markup and inflated by thumbnails and size variants, which is exactly why
     * the pipeline learned to disbelieve it (a page promised eight, Vision
     * confirmed three). This counts the browser's own intrinsic width for each
     * image the page actually loaded, keeps only those inside the media area
     * and at or above the size the catalog will accept, and collapses
     * renditions of one photo through the same asset key the downloader uses.
     * A thumbnail strip cannot survive it, and neither can one photo served at
     * four sizes.
     *
     * @param  array<string, mixed>  $pageScout
     * @param  array<string, mixed>  $context
     */
    private function usableStaticGallerySize(array $pageScout, array $context): int
    {
        // A category that says zero is saying "do not restrict this", which is
        // not the same as saying nothing at all - only the absent value falls
        // back to the global setting. Treating both as "unset" would impose a
        // 700px floor on a category that had deliberately removed one.
        // Only height may be switched off with a zero - Category clamps its own
        // width to at least 100, so a zero there is not a category saying
        // "unrestricted", it is a value that cannot come from one. Treating it
        // as unrestricted would let the measurement pass images the downloader
        // is about to reject.
        $minimumWidth = (int) ($context['minimum_image_width'] ?? 0) > 0
            ? (int) $context['minimum_image_width']
            : $this->settings->imageMinimumWidth();

        // Height is checked on the same terms the downloader will apply it,
        // including its "0 means unlimited" rule - measuring width alone let a
        // wide, short banner count as a photograph the downloader would then
        // throw away, and the search would have skipped training for nothing.
        $minimumHeight = ($context['minimum_image_height'] ?? null) === null
            ? $this->settings->imageMinimumHeight()
            : max(0, (int) $context['minimum_image_height']);

        return collect($pageScout['image_candidates'] ?? [])
            ->filter(fn (mixed $candidate): bool => is_array($candidate)
                && ($candidate['within_media'] ?? false) === true
                && (int) ($candidate['natural_width'] ?? 0) >= $minimumWidth
                && (int) ($candidate['natural_height'] ?? 0) >= $minimumHeight)
            ->map(fn (array $candidate): string => trim((string) ($candidate['current_src'] ?? $candidate['src'] ?? '')))
            ->filter(fn (string $url): bool => $url !== '' && filter_var($url, FILTER_VALIDATE_URL) !== false)
            ->map(fn (string $url): string => ProductImageStorage::imageAssetKey($url))
            ->unique()
            ->count();
    }

    /**
     * What makes two failures "the same one again".
     *
     * Digits are stripped so that timestamps, request ids and durations inside
     * a provider's message do not disguise one recurring fault as a series of
     * different ones - which is exactly how thirty-five identical 400s looked
     * like thirty-five separate rounds worth trying.
     */
    /**
     * What happened to the photographs this shop's last recipe produced.
     *
     * Training ends before the download and Vision stages run, so the outcome
     * does not exist yet when a round finishes - it only appears later, in
     * another layer. The result was an agent optimising the wrong thing: it
     * knew it had extracted twelve images and never that ten of them were
     * 370px and were rejected on arrival. This is that missing half, read back
     * from the attempts the pipeline already records.
     *
     * @return array<string, mixed>|null
     */
    private function previousPhotoOutcome(string $domain, string $url = ''): ?array
    {
        if ($domain === '') {
            return null;
        }

        // Narrowed to the page family this training is for when the domain has
        // one. A shop's product cards and its accessory pages are different
        // recipes with different galleries, and telling the agent that the last
        // photographs from somewhere on this domain were all too small is
        // advice about a page it is not looking at.
        $pathPattern = $url === '' ? null : $this->recipeRouter->pathPatternForUrl($url);
        $downloadsHere = fn () => ProductSourceAttempt::query()
            ->where('domain', $domain)
            ->whereIn('action', ['download_candidates', 'download_discovered_candidates'])
            ->whereNotNull('output')
            ->latest('id');
        $download = $pathPattern === null ? null : $downloadsHere()
            ->limit(40)
            ->get(['id', 'product_url', 'output', 'created_at'])
            ->first(fn (ProductSourceAttempt $attempt): bool => is_string($attempt->product_url)
                && $this->recipeRouter->pathPatternForUrl($attempt->product_url) === $pathPattern);
        // Nothing from this page family yet: the domain's own last outcome is
        // still worth more than silence, and the agent is told which it is.
        $sameFamily = $download !== null;
        $download ??= $downloadsHere()->first();

        if (! $download) {
            return null;
        }

        $output = is_array($download->output) ? $download->output : [];
        $rejections = collect($output['rejected_candidates'] ?? [])
            ->map(fn (mixed $rejection): string => is_array($rejection)
                ? (string) ($rejection['reason'] ?? 'unknown')
                : 'unknown')
            ->countBy()
            ->sortDesc()
            ->take(4)
            ->all();

        if (($output['downloaded_images'] ?? null) === null && $rejections === []) {
            return null;
        }

        // Tied to the search these photographs belong to, not to the domain.
        // "Anything on this domain succeeded afterwards" answered yes for a
        // different path, a different product, or a parallel search running at
        // the same time - and told the agent its last recipe had reached the
        // catalog when every frame it produced had in fact been thrown away.
        // Without a draft to scope by there is no honest answer, so it says so.
        $published = $download->product_draft_id === null
            ? null
            : ProductSourceAttempt::query()
                ->where('product_draft_id', $download->product_draft_id)
                ->where('id', '>=', $download->id)
                ->whereIn('decision', ['accept_agent_confirmed_atomic_gallery', 'accept_agent_confirmed_gallery'])
                ->exists();

        return [
            'when' => $download->created_at?->toDateString(),
            'from' => $sameFamily ? 'this_page_family' : 'elsewhere_on_this_domain',
            'downloaded' => (int) ($output['downloaded_images'] ?? 0),
            'kept_after_technical_checks' => (int) ($output['unique_images'] ?? 0),
            'rejected_by_reason' => $rejections,
            'reached_the_catalog' => $published ?? 'unknown',
            'instruction' => 'This is what became of the photographs the last recipe here produced - read "from" for '
                .'whether it was this page family or only somewhere else on the domain. A recipe that '
                .'collects thumbnails or size variants extracts a healthy-looking count and then loses all of it to '
                .'the size check, so prefer the selectors and controls that reveal full-size frames even when a '
                .'smaller set is easier to reach.',
        ];
    }

    private function technicalFailureSignature(Throwable $exception): string
    {
        // Numbers are flattened so that ids, byte counts and timestamps do not
        // make two occurrences of the same fault look different - but a status
        // code is the fault, not noise in it. Erasing every digit made a 400
        // and a 429 share one signature, so three unrelated failures could trip
        // a breaker built for one failure repeating. Short runs stay; only runs
        // of four digits or more, which no status code is, are collapsed.
        return $exception::class.'|'.preg_replace('/\d{4,}/', '#', mb_substr($exception->getMessage(), 0, 200));
    }

    private function scoutForAgent(array $pageScout, bool $complete): array
    {
        if ($complete) {
            return $pageScout;
        }

        foreach (self::AGENT_PAGE_LIST_LIMITS as $key => $keep) {
            if (is_array($pageScout[$key] ?? null) && count($pageScout[$key]) > $keep) {
                $pageScout[$key.'_omitted'] = count($pageScout[$key]) - $keep;
                $pageScout[$key] = array_slice($pageScout[$key], 0, $keep);
            }
        }

        return $pageScout;
    }

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
            // How many distinct image assets the DOM held after this round.
            // The layout fingerprint above is built from selectors, so a page
            // whose markup keeps the same controls while genuinely filling with
            // assets - a viewer loading its frames in, a lazy list growing -
            // signed identically round after round and read as stagnant. The
            // strategy calls a new DOM state progress, and this is the
            // observation that carries it.
            'distinct_dom_assets' => (int) data_get($result, 'diagnostics.distinct_dom_assets', 0),
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
