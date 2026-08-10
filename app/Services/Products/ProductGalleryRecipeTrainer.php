<?php

namespace App\Services\Products;

use App\Ai\Agents\ProductGalleryPreflightAgent;
use App\Ai\Agents\ProductGalleryRecipeTrainerAgent;
use App\Models\AiRun;
use App\Models\ProductGalleryRecipe;
use App\Models\ProductGalleryRecipeVersion;
use App\Services\Ai\AiSettings;
use App\Services\Ai\OpenAiHeavyOperationGate;
use App\Services\Ai\ProductSearchTimeBudget;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use RuntimeException;
use Throwable;

class ProductGalleryRecipeTrainer
{
    public function __construct(
        private readonly BrowserProductGalleryExtractor $browser,
        private readonly AiSettings $settings,
        private readonly ProductSearchTimeBudget $timeBudget,
        private readonly ProductGalleryRecipeResultValidator $resultValidator,
        private readonly ProductSourceAttemptRecorder $attempts,
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

            $debug?->__invoke('step', "AI-тренер: Playwright собирает DOM, интерактивные элементы и сетевые изображения {$host}.");
            $scout = $this->browser->scout($url, $debug, $telegramUpdateId);
            $pageScout = is_array($scout['scout'] ?? null) ? $scout['scout'] : [];
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
            $hasInteractiveGalleryControls = ($pageScout['interactive_controls'] ?? []) !== [];

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
                $preflight = $this->preflight($url, $pageScout, $scout['diagnostics'] ?? [], $context, $provider, $model, $version, $telegramUpdateId, $debug);
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
                    ->unique()->take(20)->values()->all();
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
                $oldResult = $this->browser->executeRecipe($url, $recipe->recipe, 20, null, $telegramUpdateId);
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
            $maxRounds = $this->settings->galleryTrainingMaxRounds();
            $roundScout = $pageScout;

            for ($attempt = 1; $attempt <= $maxRounds; $attempt++) {
                if (! $this->timeBudget->canStart($telegramUpdateId, 30)) {
                    $debug?->__invoke('warning', 'Резерв времени достигнут: дополнительную попытку обучения не запускаю.');

                    if ($attempt === 1) {
                        $version->update([
                            'status' => 'deferred',
                            'error' => 'Обучение отложено: достигнут резерв времени текущего поиска.',
                        ]);

                        return $oldImages;
                    }

                    break;
                }

                $prompt = json_encode([
                    'url' => $url,
                    'attempt' => $attempt,
                    'max_attempts' => $maxRounds,
                    'current_recipe' => $recipe->recipe,
                    'page' => $roundScout,
                    'diagnostics' => $scout['diagnostics'] ?? [],
                    'attempt_history' => $attempts,
                    'preflight' => $preflight,
                    'previous_attempt_feedback' => $feedback,
                    'operator_hint' => $userHint,
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

                try {
                    $recipeTimeout = $this->timeBudget->timeoutFor(
                        $telegramUpdateId,
                        $this->settings->galleryRecipeTimeoutSeconds(),
                    );
                    $response = app(OpenAiHeavyOperationGate::class)->run(
                        $provider,
                        $recipeTimeout,
                        fn () => ProductGalleryRecipeTrainerAgent::make()->prompt(
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
                    $debug?->__invoke('warning', "AI-тренер: раунд {$attempt}/{$maxRounds} не удался ({$exception->getMessage()}), пробую следующий раунд.");
                    $attempts[] = ['attempt' => $attempt, 'error' => 'ai_call_failed: '.$exception->getMessage()];
                    $feedback = ['error' => 'The previous attempt failed to produce a response: '.mb_substr($exception->getMessage(), 0, 300)];

                    continue;
                }

                try {
                    $candidate = $this->validateRecipe($response->toArray());
                } catch (Throwable $exception) {
                    $debug?->__invoke('warning', "AI-тренер: раунд {$attempt}/{$maxRounds} вернул невалидный рецепт ({$exception->getMessage()}), пробую следующий раунд.");
                    $attempts[] = ['attempt' => $attempt, 'error' => 'invalid_recipe: '.$exception->getMessage()];
                    $feedback = ['error' => 'The previous attempt returned an invalid recipe: '.mb_substr($exception->getMessage(), 0, 300)];

                    continue;
                }

                $debug?->__invoke('step', "AI-тренер: проверяю рецепт, раунд {$attempt}/{$maxRounds} · {$url}");
                $candidateResult = $this->browser->executeRecipe($url, $candidate, 20, $debug, $telegramUpdateId);
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
                $validation = $this->resultValidator->validate($candidate, $candidateResult);
                $promote = $validation['passed']
                    && (count($oldImages) < 2 || count($candidateImages) >= count($oldImages));
                $attempts[] = [
                    'attempt' => $attempt,
                    'selectors_tried' => $candidate,
                    'candidate_count' => count($candidateImages),
                    'validation' => $validation,
                    'diagnostics' => $candidateResult['diagnostics'] ?? [],
                    'failure_kind' => $candidateResult['failure_kind'] ?? null,
                    'error' => $candidateResult['error'] ?? null,
                ];

                if ($promote) {
                    break;
                }

                $postInteractionScout = $candidateResult['post_interaction_scout'] ?? [];
                if (
                    is_array($postInteractionScout)
                    && (
                        ($postInteractionScout['fragments'] ?? []) !== []
                        || ($postInteractionScout['interactive_controls'] ?? []) !== []
                    )
                ) {
                    $roundScout = $postInteractionScout;
                }

                $feedback = [
                    'rejected_recipe' => $candidate,
                    'candidate_count' => count($candidateImages),
                    'previous_working_count' => count($oldImages),
                    'diagnostics' => $candidateResult['diagnostics'] ?? [],
                    'failure_kind' => $candidateResult['failure_kind'] ?? null,
                    'error' => $candidateResult['error'] ?? $validation['reason'],
                    'action_trace' => $candidateResult['action_trace'] ?? [],
                    'post_interaction_scout' => $postInteractionScout,
                    'instruction' => 'Use the post-interaction DOM and action trace. Decide whether another safe click opens a deeper gallery layer; do not repeat failed actions.',
                ];
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
                ],
                'score' => $score,
                'promoted_at' => $promote ? now() : null,
                'error' => $promote ? null : 'Все разрешённые раунды завершены без полной подтверждённой галереи.',
            ]);

            if (! $promote) {
                $failureKind = 'recipe_mismatch';
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
            // Two raw URLs can still be the exact same photo at another size
            // (e.g. Adobe Scene7's wid/hei or $preset$ query forms) - counting
            // them as distinct before the preflight AI ever sees them would
            // let a single duplicated photo pass as "static_sufficient" and
            // skip Playwright training entirely. Normalizing first keeps this
            // headcount consistent with the one downloadCandidates() uses.
            'static_image_urls' => collect($context['static_image_urls'] ?? [])
                ->filter(fn (mixed $url): bool => is_string($url) && $url !== '')
                ->map(fn (string $url): string => ProductImageStorage::normalizeCandidateUrl($url))
                ->unique()->take(20)->values()->all(),
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
                'decision' => ['required', 'in:static_sufficient,train_playwright,no_gallery,blocked'],
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
            'recipe_mismatch', 'dom_unusable' => 2,
            'browser_timeout', 'browser_protocol' => 3,
            default => PHP_INT_MAX,
        };
        $disable = $failureCount >= $disableAfter;

        $retryAfter = $disable ? null : match ($kind) {
            'rate_limited' => now()->addMinutes(30),
            'browser_timeout', 'browser_protocol' => now()->addMinutes(min(60, 2 ** min(5, $failureCount))),
            'browser_unavailable', 'browser_process' => now()->addMinutes(15),
            'ai_timeout', 'ai_rate_limited' => now()->addMinutes(15),
            'recipe_mismatch', 'dom_unusable' => now()->addMinutes(10),
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

        $data = Validator::make($data, [
            'gallery_present' => ['required', 'boolean'],
            'expected_image_count' => ['required', 'integer', 'between:0,20'],
            'expected_count_evidence' => ['required', 'string', 'max:500'],
            'pre_click_selectors' => ['present', 'array', 'max:5'],
            'collect_selectors' => ['present', 'array', 'max:12'],
            'thumbnail_selectors' => ['present', 'array', 'max:8'],
            'open_selectors' => ['present', 'array', 'max:5'],
            'next_selectors' => ['present', 'array', 'max:5'],
            'pre_click_selectors.*' => ['string', 'max:300'],
            'collect_selectors.*' => ['string', 'max:300'],
            'thumbnail_selectors.*' => ['string', 'max:300'],
            'open_selectors.*' => ['string', 'max:300'],
            'next_selectors.*' => ['string', 'max:300'],
            'attributes' => ['present', 'array', 'max:12'],
            'attributes.*' => ['regex:/^(?:src|href|srcset|data-[a-z0-9_-]+)$/i', 'max:80'],
            'max_thumbnail_clicks' => ['required', 'integer', 'between:0,20'],
            'max_next_clicks' => ['required', 'integer', 'between:0,15'],
            'wait_after_click_ms' => ['required', 'integer', 'between:50,1000'],
            'confidence' => ['required', 'numeric', 'between:0,1'],
            'reason' => ['required', 'string', 'max:1000'],
        ])->validate();

        foreach (['pre_click_selectors', 'collect_selectors', 'thumbnail_selectors', 'open_selectors', 'next_selectors'] as $key) {
            $data[$key] = collect($data[$key] ?? [])
                ->filter(fn (mixed $selector): bool => is_string($selector) && $this->safeSelector($selector))
                ->map(fn (string $selector): string => trim($selector))
                ->unique()->values()->all();
        }

        $data['attributes'] = collect($data['attributes'] ?? [])
            ->map(fn (string $attribute): string => strtolower(trim($attribute)))
            ->unique()->values()->all();

        if ($data['collect_selectors'] === []) {
            throw new RuntimeException('AI не вернул безопасный селектор сбора изображений.');
        }

        return $data;
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
