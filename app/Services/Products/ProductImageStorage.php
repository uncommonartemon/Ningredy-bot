<?php

namespace App\Services\Products;

use App\Exceptions\ProductSearchCancelled;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductDraft;
use App\Models\ProductDraftMedia;
use App\Models\ProductSourceAttempt;
use App\Models\ProductSourcePageEvidence;
use App\Models\ProductVariant;
use App\Services\Ai\AiSettings;
use App\Services\Ai\ProductSearchCancellation;
use App\Services\Ai\ProductSearchCostBudget;
use App\Services\Ai\ProductSearchTimeBudget;
use App\Services\Photos\CandidateUrlOrdering;
use App\Services\Photos\GallerySourceContext;
use App\Services\Photos\ImageGeometry;
use App\Services\Photos\ImageRenditionDeduplicator;
use App\Services\Photos\ImageUrls;
use App\Services\ProductSearch\SourceQueueBuilder;
use GdImage;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class ProductImageStorage
{
    public function __construct(
        private readonly ProductImageResolver $resolver,
        private readonly ProductImageCandidateDiscovery $candidateDiscovery,
        private readonly ProductImageVisionVerifier $visionVerifier,
        private readonly ConfirmedProductGalleryVerifier $confirmedGalleryVerifier,
        private readonly ProductSourcePriority $sourcePriority,
        private readonly ProductSourceMetrics $sourceMetrics,
        private readonly ImagePerceptualHash $perceptualHash,
        private readonly ProductImageEncoder $encoder,
        private readonly ProductIdentityMatcher $identityMatcher,
        private readonly ProductSourceIdentityJudge $identityJudge,
        private readonly AiSettings $settings,
        private readonly ProductSearchTimeBudget $timeBudget,
        private readonly ProductSearchCostBudget $costBudget,
        private readonly ProductSourceAttemptRecorder $attempts,
        private readonly ProductGalleryRecipeTrainer $recipeTrainer,
        private readonly ProductGalleryRecipeRouter $recipeRouter,
        private readonly ProductSpecificationReconciler $specificationReconciler,
        private readonly ImageGeometry $imageGeometry,
        private readonly SourceQueueBuilder $sourceQueue,
        private readonly CandidateUrlOrdering $candidateUrls,
        private readonly GallerySourceContext $gallerySourceContext,
        private readonly ImageRenditionDeduplicator $renditions,
    ) {}

    /** @param array<int, int> $replaceMediaIds */
    public function store(Product $product, ProductVariant $variant, ProductDraft $draft, array $replaceMediaIds = []): int
    {
        $replacementMedia = $replaceMediaIds === []
            ? collect()
            : $product->media()
                ->where('type', 'image')
                ->whereIn('id', $replaceMediaIds)
                ->orderBy('sort_order')
                ->get(['id', 'disk', 'path', 'source_url', 'checksum']);
        $existingMedia = $product->media()
            ->where('type', 'image')
            ->when($replaceMediaIds !== [], fn ($query) => $query->whereNotIn('id', $replaceMediaIds))
            ->get(['source_url', 'checksum']);
        $existing = $existingMedia->count();
        $target = $this->targetImageCount($product);
        $remaining = $target - $existing;

        if ($remaining <= 0) {
            return 0;
        }

        $knownUrls = $this->cleanUrls($draft->image_urls ?? []);
        $excludedSourceUrls = collect([...$existingMedia, ...$replacementMedia])
            ->pluck('source_url')
            ->filter()
            ->unique()
            ->values()
            ->all();
        $replacementHashes = $this->perceptualHashesForMedia($replacementMedia);
        $initialUrls = array_values(array_diff($knownUrls, $excludedSourceUrls));
        $candidates = $this->downloadCandidates($initialUrls, $draft);
        $usedDiscovery = false;

        Log::info('Product image pipeline started.', [
            'draft_id' => $draft->id,
            'product_id' => $product->id,
            'target_images' => $target,
            'existing_images' => $existing,
            'initial_urls' => count($initialUrls),
            'initial_downloads' => count($candidates),
        ]);

        if ($candidates === [] && config('product-images.fallback_discovery', true)) {
            [$candidates, $usedDiscovery] = $this->discoverCandidates(
                $draft,
                array_values(array_unique([...$knownUrls, ...$excludedSourceUrls])),
            );
        }

        $selected = $this->selectFromCandidates($draft, $candidates, $remaining);

        if (
            count($selected) < $remaining
            && ! $usedDiscovery
            && config('product-images.fallback_discovery', true)
            && config('product-images.discover_after_rejection', true)
        ) {
            try {
                [$additionalCandidates, $usedDiscovery] = $this->discoverCandidates(
                    $draft,
                    array_values(array_unique([...$knownUrls, ...$excludedSourceUrls])),
                );
                $additionalCandidates = $this->removeDuplicateCandidates($candidates, $additionalCandidates);
                $additionalSelected = $this->selectFromCandidates($draft, $additionalCandidates, $remaining - count($selected));
            } catch (Throwable $exception) {
                $this->destroy($candidates);

                throw $exception;
            }

            $candidates = [...$candidates, ...$additionalCandidates];
            $selected = [...$selected, ...$additionalSelected];
        }

        $selected = $this->removeNearDuplicates($selected, $replacementHashes);

        $this->destroyUnselected($candidates, $selected);

        Log::info('Product image candidates reviewed.', [
            'draft_id' => $draft->id,
            'product_id' => $product->id,
            'downloaded' => count($candidates),
            'selected' => count($selected),
            'used_discovery' => $usedDiscovery,
        ]);

        $roles = ['primary', 'secondary', 'detail'];
        $stored = 0;
        $storedChecksums = array_fill_keys(
            $existingMedia->pluck('checksum')->filter()->values()->all(),
            true,
        );

        foreach ($selected as $candidate) {
            $path = null;

            try {
                $converted = $this->encoder->toWebp($candidate['image']);
                $encoded = $converted['bytes'];
                $checksum = hash('sha256', $encoded);

                if (isset($storedChecksums[$checksum])) {
                    continue;
                }

                $role = $roles[$existing + $stored] ?? 'detail';
                $path = "products/{$product->id}/{$role}-".substr($checksum, 0, 12).'.webp';

                if (! Storage::disk('public')->put($path, $encoded)) {
                    throw new \RuntimeException("Could not write product image: {$path}");
                }

                $product->media()->create([
                    'product_variant_id' => $variant->id,
                    'type' => 'image',
                    'disk' => 'public',
                    'path' => $path,
                    'role' => $role,
                    'url' => '/storage/'.str_replace('\\', '/', $path),
                    'source_url' => $candidate['source_url'],
                    'photo_source_context' => $candidate['page_source_context'] ?? null,
                    'alt' => $product->title,
                    'mime_type' => 'image/webp',
                    'width' => $converted['width'],
                    'height' => $converted['height'],
                    'file_size' => strlen($encoded),
                    'checksum' => $checksum,
                    'verification_status' => $candidate['verification_status'] ?? 'verified',
                    'verification_score' => isset($candidate['vision_score'])
                        ? $candidate['vision_score'] / 100
                        : ($candidate['verification_score'] ?? null),
                    'verification_model' => $candidate['vision_model'] ?? $candidate['verification_model'] ?? null,
                    'verification_notes' => $candidate['vision_reason'] ?? $candidate['verification_notes'] ?? null,
                    'verified_at' => now(),
                    'sort_order' => $existing + $stored,
                    'is_primary' => $existing === 0 && $stored === 0,
                ]);
                $storedChecksums[$checksum] = true;
                $stored++;
            } catch (Throwable $exception) {
                if ($path) {
                    Storage::disk('public')->delete($path);
                }

                report($exception);
            } finally {
                imagedestroy($candidate['image']);
            }
        }

        return $stored;
    }

    /** @param null|callable(string): void $progress */
    public function stage(
        ProductDraft $draft,
        ?callable $progress = null,
        ?int $telegramUpdateId = null,
        array $cycleSources = [],
        array $cycleExcludedSourceUrls = [],
    ): int {
        $startedAt = microtime(true);
        $updateId = $telegramUpdateId ?? $draft->telegram_update_id;
        $failure = null;
        $cycle = $this->attempts->record([
            'telegram_update_id' => $updateId, 'product_draft_id' => $draft->id,
            'product_url' => $draft->primary_source_url ?? '',
            'actor' => 'server', 'phase' => 'gallery_cycle', 'action' => 'cycle_started',
            'status' => 'running',
            'input' => ['gallery_status' => $draft->gallery_status, 'source_count' => count($draft->sources ?? [])],
        ]);
        try {
            app(ProductSearchCancellation::class)->throwIfCancelled($updateId);

            $recoveringCard = false;
            while (true) {
                app(ProductSearchCancellation::class)->throwIfCancelled($updateId);
                $stored = $this->stageCycle(
                    $draft, $progress, $telegramUpdateId, $cycleSources, $cycleExcludedSourceUrls,
                    skipOutstandingReconciliation: $recoveringCard,
                );
                $draft->refresh();
                $result = $recoveringCard ? $draft->media()->count() : $stored;

                if (! $draft->reconciliationPending()
                    || $draft->gallery_search_stop_reason !== 'specifications_unreconciled'
                    || ! $this->settings->specificationReconciliationEnabled()
                    || ! $this->settings->fallbackSourcesEnabled()) {
                    return $result;
                }

                $savedSource = $draft->photo_source_context ?? [];
                $lastTextFailure = $draft->card_repair_state['last_failure'] ?? null;
                if (ProductPhotoApplicability::isAccepted($draft, $savedSource, $savedSource['_photo_applicability'] ?? [])
                    && $draft->gallery_confirmed_sufficient && $draft->media()->exists()
                    && is_array($lastTextFailure)
                    && ($lastTextFailure['repair_context']['recovery_action'] ?? null) !== 'try_other_source') {
                    // Assembly has already tried targeted text tools and its fallback model.
                    // A text gap must not buy the same photo discovery cycle again.
                    $this->attempts->record([
                        'telegram_update_id' => $updateId, 'product_draft_id' => $draft->id,
                        'product_url' => $draft->primary_source_url, 'actor' => 'server',
                        'phase' => 'card_assembly', 'action' => 'preserve_gallery_after_text_repair',
                        'status' => 'interrupted', 'decision' => 'return_saved_result',
                        'message' => 'Фото проверены и сохранены; адресное восстановление характеристик завершено без готовой карточки.',
                        'output' => ['saved_file_count' => $draft->media()->count(),
                            'repair_state' => $draft->card_repair_state],
                    ]);
                    $progress?->__invoke('Проверенные фото сохранены. Остались вопросы к характеристикам; повторно искать ту же галерею не требуется.');
                    if ($this->costBudget->exceeded($updateId)) {
                        $draft->update(['gallery_search_stop_reason' => 'cost_budget']);
                    } elseif (! $this->timeBudget->canStart($updateId, 20)) {
                        $draft->update(['gallery_search_stop_reason' => 'time_budget']);
                    }

                    return $result;
                }

                // Exhausting one source's assembly repairs is not exhausting
                // the request. Another source gets the SAME remaining budget.
                $stopReason = match (true) {
                    $this->costBudget->exceeded($updateId) => 'cost_budget',
                    ! $this->timeBudget->canStart($updateId, 20) => 'time_budget',
                    default => null,
                };
                if ($stopReason !== null) {
                    $draft->update(['gallery_search_stop_reason' => $stopReason]);

                    return $result;
                }
                // Unpriced/budgetless runs retain bounded local recovery;
                // they cannot authorize an open-ended paid search loop.
                if (! $updateId || $this->settings->maxSearchCostUsd() <= 0
                    || $this->costBudget->unmeasurable($updateId)) {
                    return $result;
                }

                $failedSource = trim((string) $draft->primary_source_url);
                $cycleExcludedSourceUrls = array_values(array_unique(array_filter([
                    ...$cycleExcludedSourceUrls,
                    ...$this->terminalSourceUrlsForContinuation($draft),
                    $failedSource,
                ])));
                $this->attempts->record([
                    'telegram_update_id' => $updateId, 'product_draft_id' => $draft->id,
                    'product_url' => $failedSource, 'actor' => 'server',
                    'phase' => 'card_assembly', 'action' => 'recover_with_another_source',
                    'status' => 'interrupted', 'decision' => 'continue_search',
                    'message' => 'Сборка карточки по этому источнику не завершена; продолжаю поиск других источников в оставшемся бюджете.',
                    'output' => ['saved_file_count' => $draft->media()->count(),
                        'excluded_source_urls' => $cycleExcludedSourceUrls,
                        'failure_context' => $draft->gallery_notes,
                        'estimated_search_cost_usd' => $this->costBudget->spent($updateId)],
                ]);
                $progress?->__invoke('Карточку по выбранному источнику собрать не удалось. Фото сохранены; ищу другой источник в оставшемся бюджете.');
                $cycleSources = [];
                $recoveringCard = true;
            }
        } catch (Throwable $exception) {
            $failure = $exception;
            throw $exception;
        } finally {
            // Observability must never replace an original error or invalidate saved photos.
            try {
                $state = $draft->fresh() ?? $draft;
                $cancelled = $failure instanceof ProductSearchCancelled;
                $status = $cancelled ? 'cancelled' : ($failure !== null ? 'interrupted' : ($state->gallery_status ?: 'unknown'));
                $count = $state->media()->count();
                $reason = $cancelled ? 'cancelled' : ($failure !== null ? 'exception' : ($state->gallery_search_stop_reason ?: 'none'));
                $summary = 'Запрос #'.$updateId.' · черновик #'.$state->id
                    .': цикл фото — '.$status.'; файлов сохранено: '.$count.'; остановка: '.$reason.'.';
                $this->attempts->record([
                    'telegram_update_id' => $updateId, 'product_draft_id' => $state->id,
                    'product_url' => $state->primary_source_url ?? '',
                    'actor' => 'server', 'phase' => 'gallery_cycle', 'action' => 'cycle_finished',
                    'status' => $status, 'decision' => $reason, 'message' => $summary,
                    'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                    'output' => [
                        'cycle_start_attempt_id' => $cycle?->id,
                        'saved_file_count' => $count,
                        'gallery_confirmed_sufficient' => (bool) $state->gallery_confirmed_sufficient,
                        'specifications_reconciled_source_url' => $state->specifications_reconciled_source_url,
                        'specifications_reconciliation_attempts' => $state->specifications_reconciliation_attempts,
                        'estimated_search_cost_usd' => $this->costBudget->spent($updateId),
                        'exception_class' => $failure ? $failure::class : null,
                        'exception_message' => $failure ? mb_substr($failure->getMessage(), 0, 5000) : null,
                    ],
                ]);
                // Normal completion already emits the richer final source/method line.
                // Keep that line last; only an exception needs this extra Telegram outcome.
                if ($failure !== null && ! $cancelled) {
                    $progress?->__invoke('Цикл фото прерван ошибкой. Запрос #'.$updateId
                        .', черновик #'.$state->id.', сохранено файлов: '.$count.'. Причина записана в журнал.');
                }
            } catch (Throwable $reportingFailure) {
                report($reportingFailure);
            }
        }
    }

    /** @param null|callable(string): void $progress */
    private function stageCycle(
        ProductDraft $draft,
        ?callable $progress = null,
        ?int $telegramUpdateId = null,
        array $cycleSources = [],
        array $cycleExcludedSourceUrls = [],
        bool $skipOutstandingReconciliation = false,
    ): int {
        // A restage/top-up triggered by a button press minutes or hours after
        // the draft's original search must get its own fresh time budget -
        // defaulting to the draft's original telegram_update_id would make
        // ProductSearchTimeBudget measure elapsed time since that original,
        // already-finished search and report zero time left immediately.
        $telegramUpdateId ??= $draft->telegram_update_id;
        $this->lastDegradedDomains = [];
        // The previous staged gallery is replaced only after a new one was
        // stored successfully, so a failed search never leaves the draft
        // without photos (same philosophy as completeRefind).
        $previousMedia = $draft->media()->get();
        $excludedHashes = array_values(array_filter($draft->excluded_gallery_hashes ?? [], 'is_string'));

        $target = $this->targetDraftImageCount($draft);
        $minimumCompleteGallerySize = min($this->minimumVerifiedImages($draft), $target);

        // A source whose content check could not run keeps its photographs and
        // marks them pending. Continuing the search used to begin that source
        // again from the top - open the page, run the recipe, download every
        // frame - to arrive back at the one step that had actually failed. The
        // frames are already on disk; what is missing is the verdict on them.
        $completedSourceUrls = [];
        $resumed = $this->resumeInterruptedVerification($draft, $minimumCompleteGallerySize, $target, $telegramUpdateId, $progress, $completedSourceUrls);

        if ($resumed !== null) {
            return $resumed;
        }
        if (ProductPhotoKeyboardContext::pendingSources($draft->photo_source_context ?? []) !== []
            && $draft->media()->where('verification_status', 'pending')->exists()) {
            // A late text fact requires checking retained pixels, not reopening their source.
            return $draft->media()->count();
        }

        // A completed check may have rejected some pending files while keeping
        // a verified partial set. Do not later resurrect the stale collection.
        $previousMedia = $draft->media()->get();
        $previousGalleryState = $draft->only([
            'primary_source_url', 'image_urls', 'gallery_status', 'gallery_confirmed_sufficient',
            'gallery_notes', 'images_staged_at', 'gallery_search_stop_reason', 'photo_source_context',
        ]);
        $cycleExcludedSourceUrls = array_values(array_unique([...$cycleExcludedSourceUrls, ...$completedSourceUrls]));

        // Symmetric to the resumed-verification short-circuit just above:
        // photos are already sufficient and the sole outstanding thing is
        // reconciliation itself - "Continue" must return to exactly that,
        // never re-open the source, retrain a recipe, or re-run discovery.
        $reconciliationResumed = $skipOutstandingReconciliation
            ? null
            : $this->resumeOutstandingReconciliation($draft, $telegramUpdateId, $progress);

        if ($reconciliationResumed !== null) {
            return $reconciliationResumed;
        }
        $gallerySearchStrategy = $this->gallerySearchStrategy($draft);
        $activeRecipeOnly = $gallerySearchStrategy === Category::GALLERY_SEARCH_VISION_FIRST;
        $progress?->__invoke(match ($gallerySearchStrategy) {
            Category::GALLERY_SEARCH_VISION_FIRST => 'Стратегия категории: Vision-first — использую статичные фото и готовые рецепты доменов без обучения новых рецептов.',
            Category::GALLERY_SEARCH_PLAYWRIGHT_FIRST => 'Стратегия категории: Playwright-first — сначала пытаюсь раскрыть полную галерею карточки.',
            default => 'Стратегия категории: автоматически — использую общий режим поиска фотографий.',
        });
        [$sourceQueue, $cardSources] = $this->buildSourceQueue(
            $draft,
            $cycleSources,
            $cycleExcludedSourceUrls,
            $activeRecipeOnly,
            $minimumCompleteGallerySize,
            $telegramUpdateId,
            $progress,
        );

        $selected = [];
        $chosenSource = null;
        $chosenMethod = null;
        // What the winning source's verification actually concluded. Carried
        // from the place that decided it to the place that records it, because
        // re-deriving it at the end from the method and a per-frame structural
        // flag is what let a partial result - and a set whose check had timed
        // out - both introduce themselves as complete.
        $outcome = null;
        // One object holds everything known about the best set that has not
        // won: its frames, its page, how it was produced and what its
        // verification concluded. These used to be five variables updated
        // together by hand in four places, which is how one of them came to
        // disagree with the others.
        $reserve = null;
        $deferredVisionSets = [];
        $reviewedSourceUrls = [];

        foreach ($sourceQueue as $sourceIndex => $source) {
            app(ProductSearchCancellation::class)->throwIfCancelled($telegramUpdateId);
            if (isset($reviewedSourceUrls[rtrim((string) $source['url'], '/')])) {
                // A complete traversal already reached a real pixel verdict.
                // The queue's later training pass cannot repair the shop's images.
                continue;
            }
            // A source is atomic: once its HTML/Playwright/recipe/Vision pass
            // starts, it is allowed to finish. The money limit is checked only
            // here, between sources, so a nearly-trained recipe is never
            // abandoned just because its final round crossed the threshold.
            if ($this->costBudget->exceeded($telegramUpdateId)) {
                $progress?->__invoke(sprintf(
                    'Бюджет поиска исчерпан (~$%.2f): текущий источник завершён, следующий не запускаю.',
                    $this->costBudget->limit(),
                ));
                break;
            }

            $reuseOnly = (bool) ($source['_reuse_only'] ?? $activeRecipeOnly);

            // This reservation exists to stop training new domains once most of
            // the money is spent, and a source that may only reuse an existing
            // recipe trains nothing - stopping there would skip the free half
            // of the search to protect a budget it does not spend.

            if (! $this->timeBudget->canStart($telegramUpdateId, 20)) {
                $progress?->__invoke('Резерв времени достигнут: новые источники больше не открываю, завершаю текущий результат.');
                break;
            }

            if ($progress) {
                $progress('Проверяю источник '.($sourceIndex + 1).'/'.$sourceQueue->count()
                    .($reuseOnly && ! $activeRecipeOnly ? ' (только готовый рецепт)' : '').': '.$source['url']);
            }

            if (config('product-images.source_preflight', true)
                && ! array_key_exists('_preflight_index', $source)
                && empty($source['_preflight_identity_evidence']) && empty($source['_observed_identity'])) {
                // Candidates beyond the queue's cheap preflight allowance still
                // need an observed page before a search-result title can become identity.
                try {
                    $preflight = $this->resolver->preflightSource($source, $telegramUpdateId);
                } catch (ProductSearchCancelled $exception) {
                    throw $exception;
                } catch (Throwable $exception) {
                    report($exception);
                    $preflight = [];
                }
                $source = [...$source, 'title' => null,
                    '_preflight_final_url' => $preflight['final_url'] ?? $source['url'],
                    '_preflight_identity_evidence' => $preflight['identity_evidence'] ?? '',
                    '_preflight_specification_text' => $preflight['specification_text'] ?? '',
                    '_preflight_page_identity' => $preflight['page_identity'] ?? null,
                    '_preflight_index' => $sourceIndex];
            }
            $source = $this->gallerySourceContext->forDraft($draft, $source, $minimumCompleteGallerySize);
            $originalSourceUrl = (string) $source['url'];
            $productPageUrl = is_string($source['_preflight_final_url'] ?? null)
                && filter_var($source['_preflight_final_url'], FILTER_VALIDATE_URL)
                    ? $source['_preflight_final_url']
                    : $originalSourceUrl;
            $redirectedSource = rtrim($productPageUrl, '/') !== rtrim($originalSourceUrl, '/');
            $finalSourceEvidence = [
                'url' => $productPageUrl,
                'title' => $source['title'] ?? null,
                '_preflight_final_url' => $productPageUrl,
                '_preflight_identity_evidence' => $source['_preflight_identity_evidence'] ?? null,
            ];
            $photoAssessment = app(ProductSourceIdentityGate::class)->assessPhotos(
                $draft, $redirectedSource ? $finalSourceEvidence : $source, $telegramUpdateId,
            );
            if ($redirectedSource) {
                $source = [...$source, ...$finalSourceEvidence];
            }
            $this->recordPhotoAssessment($draft, $source, $photoAssessment, $telegramUpdateId);
            if (($photoAssessment['verdict'] ?? null) === 'conflicting') {
                $progress?->__invoke('Источник пропущен: фотографии относятся к несовместимому товару. '.$photoAssessment['reason']);

                continue;
            }
            $sourceIdentityConfirmed = in_array($photoAssessment['verdict'] ?? null, ['exact', 'compatible'], true);
            $source['_photo_applicability'] = $photoAssessment;
            $configurationConflict = ! $sourceIdentityConfirmed
                && $this->identityMatcher->conflictsMemoryConfiguration($draft, $finalSourceEvidence);
            $urls = $this->cleanUrls($source['image_urls'] ?? [], $draft->color);

            if (($source['url'] ?? null) === $draft->primary_source_url) {
                $urls = array_values(array_unique([
                    ...$urls,
                    ...$this->cleanUrls($draft->image_urls ?? []),
                ]));
            }

            $sourceBlocked = false;
            $sourceFailureReported = false;
            $attemptCheckpoint = ProductSourceAttempt::query()->max('id') ?? 0;

            try {
                $resolvedUrls = $this->resolver->resolve(
                    [$source],
                    max(8, $target * 2),
                    function (string $level, string $message) use (&$sourceBlocked, &$sourceFailureReported, $progress): void {
                        $sourceFailureReported = $sourceFailureReported || in_array($level, ['warning', 'error', 'blocked'], true);
                        if ($level === 'blocked') {
                            $sourceBlocked = true;
                        }
                        if ($progress) {
                            $progress($message);
                        }
                    },
                    $telegramUpdateId,
                    activeRecipeOnly: $reuseOnly,
                );
            } finally {
                $this->associateAttemptsWithDraftSince($draft, $telegramUpdateId, $attemptCheckpoint);
            }

            app(ProductSearchCancellation::class)->throwIfCancelled($telegramUpdateId);

            $resolvedPageContext = $this->resolver->sourceContextForPage($originalSourceUrl) ?? collect($resolvedUrls)
                ->map(fn (string $imageUrl): ?array => $this->resolver->sourceContextForImage($imageUrl))
                ->first(fn (mixed $context): bool => is_array($context));
            if (is_array($resolvedPageContext)) {
                $resolvedFinalUrl = is_string($resolvedPageContext['url'] ?? null)
                    ? $resolvedPageContext['url']
                    : $productPageUrl;
                $runtimeSourceEvidence = [
                    ...$resolvedPageContext,
                    'url' => $resolvedFinalUrl,
                ];
                $photoAssessment = app(ProductSourceIdentityGate::class)->assessPhotos($draft, $runtimeSourceEvidence, $telegramUpdateId);
                $this->recordPhotoAssessment($draft, $runtimeSourceEvidence, $photoAssessment, $telegramUpdateId);
                if (($photoAssessment['verdict'] ?? null) === 'conflicting'
                    || (($photoAssessment['verdict'] ?? null) === 'uncertain' && ! ($photoAssessment['may_inspect_gallery'] ?? false))) {
                    $progress?->__invoke('Источник отложен: пригодность фотографий не подтверждена. '.$photoAssessment['reason']);

                    continue;
                }
                $productPageUrl = $resolvedFinalUrl;
                // The rendered page replaces the HTTP/search snapshot, including its verdict.
                $source = [...$source, ...$runtimeSourceEvidence, '_photo_applicability' => $photoAssessment];
                $sourceIdentityConfirmed = in_array($photoAssessment['verdict'] ?? null, ['exact', 'compatible'], true);
                $configurationConflict = ! $sourceIdentityConfirmed
                    && $this->identityMatcher->conflictsMemoryConfiguration($draft, $runtimeSourceEvidence);
            }
            if ($sourceBlocked) {
                $this->attempts->record([
                    'telegram_update_id' => $telegramUpdateId,
                    'product_draft_id' => $draft->id,
                    'product_url' => $source['url'],
                    'actor' => 'playwright',
                    'phase' => 'source_selection',
                    'action' => 'validate_source_access',
                    'status' => 'failed',
                    'decision' => 'reject_blocked_source',
                ]);
                if ($progress) {
                    $progress('Источник пропущен: ссылка ведёт на защитную заглушку, а не на товар.');
                }

                continue;
            }

            // Browser/DOM gallery URLs are more reliable than AI-provided thumbnails.
            $urls = array_values(array_unique([...$resolvedUrls, ...$urls]));
            $this->attempts->record([
                'telegram_update_id' => $telegramUpdateId,
                'product_draft_id' => $draft->id,
                'product_url' => $source['url'],
                'actor' => 'downloader',
                'phase' => 'image_download',
                'action' => 'download_candidates_started',
                'status' => 'started',
                'decision' => 'download_pending',
                'input' => ['candidate_urls' => count($urls)],
            ]);

            try {
                $allCandidates = $this->downloadCandidates($urls, $draft);
            } catch (Throwable $exception) {
                $this->attempts->record([
                    'telegram_update_id' => $telegramUpdateId,
                    'product_draft_id' => $draft->id,
                    'product_url' => $source['url'],
                    'actor' => 'downloader',
                    'phase' => 'image_download',
                    'action' => 'download_candidates',
                    'status' => 'interrupted',
                    'decision' => 'retry_source',
                    'input' => ['candidate_urls' => count($urls)],
                    'output' => [
                        'error' => mb_substr($exception->getMessage(), 0, 2000),
                        'rejected_candidates' => array_slice($this->lastDownloadRejections, 0, 20),
                    ],
                ]);
                report($exception);
                $progress?->__invoke('Скачивание изображений прервалось технической ошибкой; источник сохранён для повторной попытки, продолжаю поиск.');

                continue;
            }
            $downloadedCount = count($allCandidates);

            if ($allCandidates !== []) {
                $uniqueCandidates = $this->removeNearDuplicates(
                    $allCandidates,
                    $excludedHashes,
                    preserveConfirmedGalleryFrames: true,
                );
                $this->destroyUnselected($allCandidates, $uniqueCandidates);
                $allCandidates = array_map(fn (array $candidate): array => [
                    ...$candidate,
                    'source_identity_confirmed' => $sourceIdentityConfirmed,
                    'product_page_url' => $productPageUrl,
                    'page_source_context' => $source,
                ], $uniqueCandidates);
            }
            $this->attempts->record([
                'telegram_update_id' => $telegramUpdateId,
                'product_draft_id' => $draft->id,
                'product_url' => $source['url'],
                'actor' => 'downloader',
                'phase' => 'image_download',
                'action' => 'download_candidates',
                'status' => $allCandidates !== [] ? 'completed' : 'failed',
                'decision' => $allCandidates !== [] ? 'ready_for_selection' : 'skip_source',
                'input' => ['candidate_urls' => count($urls)],
                'output' => [
                    'downloaded_images' => $downloadedCount,
                    'unique_images' => count($allCandidates),
                    'rejection_summary' => $this->downloadRejectionSummary(),
                    'rejected_candidates' => array_slice($this->lastDownloadRejections, 0, 20),
                ],
            ]);

            if ($allCandidates === []) {
                if ($urls === [] && $sourceFailureReported) {
                    // The concrete failure was already shown; do not append
                    // another generic sentence about missing photographs.
                    continue;
                }
                // Two different endings wore the same sentence. "No suitable
                // image" reads as "we looked at the photographs and rejected
                // them", and it was printed just as loudly when the page had
                // never opened at all - hp.com, where the HTTP fetch timed out
                // and the browser failed on the protocol, reported it as a
                // verdict about photographs that were never seen.
                $progress?->__invoke($urls === []
                    ? 'Пригодные фото из этого источника не получены; причина указана на этапе проверки или обучения: '.$source['url']
                    : 'Скачано 0 из '.count($urls).' найденных ссылок - ни одна не прошла технические проверки: '.$source['url']);

                continue;
            }

            $confirmedGallery = collect($allCandidates)
                ->filter(fn (array $candidate): bool => (bool) ($candidate['confirmed_gallery'] ?? false))
                ->values();
            // Missing visual evidence has a useful next step right here:
            // inspect the downloaded originals before opening another shop.
            $needsPixelInspection = $this->modelAwaitingPixelInspection($draft, $source);
            $structurallyConfirmed = ($sourceIdentityConfirmed || $needsPixelInspection)
                && ! $configurationConflict
                && $confirmedGallery->count() >= $minimumCompleteGallerySize;

            if ($configurationConflict && $confirmedGallery->count() >= $minimumCompleteGallerySize) {
                $progress?->__invoke('Источник продаёт другую конфигурацию памяти: кадры проверю по одному, не целиком.');
            }
            $galleryVerification = $structurallyConfirmed
                ? $this->verifyConfirmedGallerySafely(
                    $productPageUrl,
                    $confirmedGallery,
                    $draft,
                    $minimumCompleteGallerySize,
                    $telegramUpdateId,
                )
                : null;
            $verifiedGallery = $galleryVerification !== null
                ? $galleryVerification['candidates']
                : $confirmedGallery;
            $trustPlaywrightGallery = $structurallyConfirmed
                && $verifiedGallery->count() >= $minimumCompleteGallerySize;

            if (! $trustPlaywrightGallery) {
                if ($galleryVerification !== null) {
                    $verifiedCandidates = $verifiedGallery->all();
                    $this->destroyUnselected($allCandidates, $verifiedCandidates);
                    $allCandidates = $verifiedCandidates;

                    if ($allCandidates === []) {
                        $progress?->__invoke('Gallery-агент не подтвердил, что слайдер целиком относится к этому товару; продолжаю со следующей карточкой.');

                        continue;
                    }
                }

                // Verify an already downloaded exact-source set before spending
                // the next time slice training another page. Canvas viewers can
                // otherwise consume all working time while these files wait.
                if ($galleryVerification === null && ($sourceIdentityConfirmed || $needsPixelInspection)
                    && ! $configurationConflict && count($allCandidates) >= $minimumCompleteGallerySize) {
                    $progress?->__invoke('Подходящий источник уже дал фотографии; проверяю их Vision до обучения следующей страницы.');
                    $partialTraversal = $confirmedGallery->isEmpty()
                        && collect($allCandidates)->contains(fn (array $frame): bool => (bool) ($frame['partial_gallery'] ?? false));
                    try {
                        $verified = ($partialTraversal || $needsPixelInspection)
                            ? $this->checkedGalleryFrames($allCandidates, $draft, $telegramUpdateId, $minimumCompleteGallerySize, $progress)
                            : $this->selectFromCandidates($draft, $allCandidates, $target, $telegramUpdateId);
                    } catch (ProductSearchCancelled $exception) {
                        $this->destroy($allCandidates);
                        throw $exception;
                    } catch (Throwable $exception) {
                        report($exception);
                        $this->destroy($allCandidates);
                        $progress?->__invoke('Vision временно недоступен; продолжаю поиск другим источником.');

                        continue;
                    }
                    if ($verified === null) {
                        $reserve = $this->keepBestReserve($reserve,
                            GallerySourceResult::interrupted($allCandidates, $target, $source, 'static'));

                        continue;
                    }
                    $verified = $this->removeNearDuplicates($verified, $excludedHashes);
                    $this->destroyUnselected($allCandidates, $verified);
                    $this->attempts->record([
                        'telegram_update_id' => $telegramUpdateId,
                        'product_draft_id' => $draft->id,
                        'product_url' => $source['url'],
                        'actor' => 'vision', 'phase' => 'image_verification',
                        'action' => 'verify_downloaded_exact_source',
                        'status' => $verified !== [] ? 'completed' : 'failed',
                        'input' => ['downloaded_images' => count($allCandidates)],
                        'output' => ['accepted_images' => count($verified)],
                    ]);
                    $result = GallerySourceResult::fromVerifiedFrames($verified, $minimumCompleteGallerySize, $target, $source, 'static');
                    // Target is a preferred upper count, not another minimum.
                    // These exact-source frames already passed Vision and the
                    // category minimum; do not withhold a usable card merely
                    // to fill the target. A partial traversal stays partial.
                    if ($result->isComplete()) {
                        $this->destroy($reserve?->candidates ?? []);
                        foreach ($deferredVisionSets as $set) {
                            $this->destroy($set['candidates']);
                        }
                        $deferredVisionSets = [];
                        $reserve = null;
                        $selected = $result->candidates;
                        $outcome = $partialTraversal
                            ? GalleryOutcome::Partial : $result->outcome;
                        $chosenSource = $result->source;
                        $chosenMethod = $result->method;
                        $progress?->__invoke('Источник дал '.count($selected).' проверенных фото: минимум категории выполнен, перехожу к сборке карточки.');

                        break;
                    }
                    $reserve = $this->keepBestReserve($reserve, $result);

                    continue;
                }

                // One source can reach this point twice - once in the pass that
                // may only reuse an existing recipe, once when it is allowed to
                // train - and two reserve sets of largely the same frames would
                // have Vision paid twice for a single page. Only the larger set
                // survives.
                $previousSetIndex = null;

                foreach ($deferredVisionSets as $index => $set) {
                    if (($set['source']['url'] ?? null) === ($source['url'] ?? null)) {
                        $previousSetIndex = $index;

                        break;
                    }
                }

                if ($previousSetIndex !== null) {
                    $previousCandidates = $deferredVisionSets[$previousSetIndex]['candidates'];

                    if (count($previousCandidates) >= count($allCandidates)) {
                        $this->destroy($allCandidates);
                        $progress?->__invoke('Этот источник уже лежит в резерве с не меньшим набором кадров; второй набор не сохраняю.');

                        continue;
                    }

                    $this->destroy($previousCandidates);
                    array_splice($deferredVisionSets, $previousSetIndex, 1);
                }

                $deferredVisionSets[] = [
                    'candidates' => $allCandidates,
                    'source' => $source,
                ];
                usort($deferredVisionSets, fn (array $left, array $right): int => count($right['candidates']) <=> count($left['candidates'])
                );
                $discardedSets = array_splice(
                    $deferredVisionSets,
                    max(1, (int) config('product-images.vision_source_sets', 3)),
                );
                foreach ($discardedSets as $discardedSet) {
                    $this->destroy($discardedSet['candidates']);
                }

                $this->attempts->record([
                    'telegram_update_id' => $telegramUpdateId,
                    'product_draft_id' => $draft->id,
                    'product_url' => $source['url'],
                    'actor' => 'playwright',
                    'phase' => 'image_verification',
                    'action' => 'verify_gallery',
                    'status' => 'deferred',
                    'decision' => $galleryVerification !== null
                        ? 'reject_unconfirmed_gallery_and_try_next_source'
                        : 'try_next_source_before_vision',
                    'input' => [
                        'downloaded_images' => count($allCandidates),
                        'confirmed_gallery_frames' => $confirmedGallery->count(),
                        'minimum_gallery_frames' => $minimumCompleteGallerySize,
                        'source_identity_confirmed' => $sourceIdentityConfirmed,
                        'configuration_conflict' => $configurationConflict,
                        'gallery_verification_attempted' => $galleryVerification !== null,
                    ],
                ]);
                $progress?->__invoke($galleryVerification !== null
                    ? 'Gallery-агент не подтвердил монолитный слайдер; продолжаю поиск без тихого покадрового отбора.'
                    : match (true) {
                        $configurationConflict => 'Заголовок или адрес карточки указывает другую конфигурацию памяти; фото сохраняю в резерв.',
                        ! $sourceIdentityConfirmed => 'Идентичность источника пока не подтверждена; фото сохраняю в резерв.',
                        default => 'Подтверждённых галерейных кадров: '.$confirmedGallery->count()
                            .' (нужно '.$minimumCompleteGallerySize.'); сохраняю набор в резерв и проверяю следующую страницу.',
                    });

                continue;
            }

            // Check current pixels as a batch: product content (with the
            // component-logo exception) and language share one provider call.
            // Checked before the maximum is applied: taking ten first and then
            // removing two would throw away the eleventh and twelfth frames,
            // which were fine and are now gone.
            $result = $this->confirmedGalleryResult(
                $verifiedGallery->all(),
                $draft,
                $telegramUpdateId,
                $minimumCompleteGallerySize,
                $target,
                [...$source, 'url' => $productPageUrl],
                'playwright',
                'Complete gallery traversal; current frames passed catalog quality, physical appearance, colour, keyboard and language review.',
                $progress,
            );

            if (! $result->isComplete()) {
                if ($result->outcome !== GalleryOutcome::Interrupted
                    && ! collect($verifiedGallery)->every(fn (array $frame): bool => (bool) ($frame['partial_gallery'] ?? false))) {
                    $reviewedSourceUrls[rtrim($originalSourceUrl, '/')] = true;
                    $reviewedSourceUrls[rtrim($productPageUrl, '/')] = true;
                }
                // Not a complete gallery any more, but the surviving frames are
                // real photographs of this product - kept as the reserve while
                // the search moves on, never destroyed.
                $reserve = $this->keepBestReserve($reserve, $result);
                $this->destroyUnselected($allCandidates, $reserve?->candidates ?? []);

                continue;
            }

            $selected = $result->candidates;
            $outcome = $result->outcome;
            $progress?->__invoke('Playwright и проверка кадров подтвердили пригодную галерею: принимаю все '.count($selected).' фото и прекращаю обход источников.');
            $this->attempts->record([
                'telegram_update_id' => $telegramUpdateId,
                'product_draft_id' => $draft->id,
                'product_url' => $source['url'],
                'actor' => 'playwright',
                'phase' => 'image_verification',
                'action' => 'verify_gallery',
                'status' => 'completed',
                'decision' => 'accept_agent_confirmed_atomic_gallery',
                'input' => ['downloaded_images' => count($allCandidates)],
                'output' => [
                    'accepted_images' => count($selected),
                    'accepted_urls' => collect($selected)->pluck('source_url')->values()->all(),
                ],
            ]);
            $this->destroyUnselected($allCandidates, $selected);

            $this->destroy($reserve?->candidates ?? []);
            $reserve = null;
            $chosenSource = $result->source ?? [...$source, 'url' => $productPageUrl];
            $chosenMethod = 'playwright';
            break;
        }

        if ($selected === [] && $deferredVisionSets !== []) {
            $progress?->__invoke('Подтверждённого слайдера нет; последовательно проверяю Vision независимые наборы лучших страниц.');

            foreach ($deferredVisionSets as $setIndex => $set) {
                app(ProductSearchCancellation::class)->throwIfCancelled($telegramUpdateId);
                $candidates = $set['candidates'];
                $source = $set['source'];
                $candidateCount = count($candidates);
                $verificationInterrupted = false;

                try {
                    // Recipe metadata is not a review of the current pixels.
                    $verified = $this->selectFromCandidates($draft, $candidates, $target, $telegramUpdateId);
                    $verified = $this->removeNearDuplicates($verified, $excludedHashes);
                    $this->destroyUnselected($candidates, $verified);
                } catch (ProductSearchCancelled $exception) {
                    $this->destroy($candidates);
                    throw $exception;
                } catch (Throwable $exception) {
                    report($exception);
                    $this->destroy($candidates);
                    $verified = [];
                    $verificationInterrupted = true;
                    $progress?->__invoke('Один набор Vision временно недоступен; пробую следующую страницу.');
                }

                $this->attempts->record([
                    'telegram_update_id' => $telegramUpdateId,
                    'product_draft_id' => $draft->id,
                    'product_url' => $source['url'] ?? null,
                    'actor' => 'vision',
                    'phase' => 'image_verification',
                    'action' => 'verify_deferred_source',
                    'status' => $verificationInterrupted
                        ? 'interrupted'
                        : ($verified !== [] ? 'completed' : 'failed'),
                    'decision' => $verificationInterrupted
                        ? 'retry_source_on_continuation'
                        : (count($verified) >= $minimumCompleteGallerySize
                            ? 'accept_images'
                            : 'try_next_independent_source'),
                    'input' => ['downloaded_images' => $candidateCount],
                    'output' => [
                        'accepted_images' => count($verified),
                        'accepted_urls' => collect($verified)->pluck('source_url')->values()->all(),
                    ],
                ]);

                $result = GallerySourceResult::fromVerifiedFrames(
                    $verified,
                    $minimumCompleteGallerySize,
                    $target,
                    $source,
                    'static',
                );

                if ($result->isComplete()) {
                    $this->destroy($reserve?->candidates ?? []);
                    $reserve = null;
                    $selected = $result->candidates;
                    $outcome = $result->outcome;
                    $chosenSource = $result->source;
                    $chosenMethod = $result->method;

                    foreach (array_slice($deferredVisionSets, $setIndex + 1) as $unusedSet) {
                        $this->destroy($unusedSet['candidates']);
                    }

                    break;
                }

                $reserve = $this->keepBestReserve($reserve, $result);
            }

            $deferredVisionSets = [];
        }

        if (
            $selected === []
            && $this->settings->fallbackSourcesEnabled()
            && $this->timeBudget->canStart($telegramUpdateId, 30)
            && ! $this->costBudget->exceeded($telegramUpdateId)
        ) {
            if ($progress) {
                $progress('Галереи указанных карточек не подошли. Автоматически ищу другие магазины в пределах оставшегося бюджета времени.');
            }
            $knownUrls = $this->cleanUrls([
                ...($draft->image_urls ?? []),
                ...($draft->excluded_gallery_image_urls ?? []),
                ...$cardSources->flatMap(fn (array $source): array => $source['image_urls'] ?? [])->all(),
            ]);
            $fallbackExcludedSourceUrls = $cycleExcludedSourceUrls;
            $fallbackRound = 0;
            // In production price and elapsed time are the only retry limits.
            // Tests, explicitly budgetless operation, and a search whose
            // model has no configured pricing (so costBudget->exceeded()
            // can never see it as over budget) all need a deterministic
            // round-count safety cap instead of relying purely on the time
            // limit for hours of paid discovery calls.
            $safetyRounds = max(1, (int) config('product-images.fallback_search_rounds', 3));

            while (true) {
                app(ProductSearchCancellation::class)->throwIfCancelled($telegramUpdateId);
                // Re-evaluate measurability after every discovery call. A
                // fresh continuation has no usage before its first AI request,
                // so spent() is temporarily null. Freezing that initial state
                // incorrectly imposed the three-round safety cap on a normal
                // paid production search even after cost became measurable.
                if ($this->fallbackSearchSafetyCapReached(
                    $fallbackRound,
                    $safetyRounds,
                    $telegramUpdateId,
                )) {
                    break;
                }

                if (! $this->timeBudget->canStart($telegramUpdateId, 30) || $this->costBudget->exceeded($telegramUpdateId)) {
                    break;
                }
                $fallbackRound++;
                $attemptCheckpoint = ProductSourceAttempt::query()->max('id') ?? 0;
                [$discoveredCandidates] = $this->discoverCandidates(
                    $draft,
                    $knownUrls,
                    true,
                    $progress,
                    $telegramUpdateId,
                    $fallbackExcludedSourceUrls,
                    $fallbackRound,
                );

                $attemptedRoundSourceUrls = ProductSourceAttempt::query()
                    ->where('id', '>', $attemptCheckpoint)
                    ->when($telegramUpdateId, fn ($query) => $query->where('telegram_update_id', $telegramUpdateId))
                    ->pluck('product_url')
                    ->filter(fn (mixed $url): bool => is_string($url) && filter_var($url, FILTER_VALIDATE_URL) !== false)
                    ->values()
                    ->all();
                $roundSourceUrls = collect([
                    ...$attemptedRoundSourceUrls,
                    ...collect($discoveredCandidates)
                        ->flatMap(fn (array $candidate): array => array_values(array_filter([
                            $candidate['page_source_url'] ?? null,
                            $candidate['source_url'] ?? null,
                        ], fn (mixed $url): bool => is_string($url) && filter_var($url, FILTER_VALIDATE_URL) !== false)))
                        ->all(),
                ])
                    ->unique()
                    ->values()
                    ->all();
                $knownUrls = $this->cleanUrls([
                    ...$knownUrls,
                    ...collect($discoveredCandidates)->pluck('source_url')->filter()->all(),
                ]);
                $fallbackExcludedSourceUrls = array_values(array_unique([
                    ...$fallbackExcludedSourceUrls,
                    ...$roundSourceUrls,
                ]));

                if ($discoveredCandidates === []) {
                    $rejectionSummary = collect($this->lastDownloadRejections)
                        ->map(fn (array $rejection): string => Str::before(
                            (string) ($rejection['reason'] ?? 'unknown'),
                            ' (',
                        ))
                        ->countBy()
                        ->map(fn (int $count, string $reason): string => "{$reason}: {$count}")
                        ->values()
                        ->implode(', ');
                    $details = $rejectionSummary !== '' ? " Причины: {$rejectionSummary}." : '';
                    if ($this->candidateDiscovery->hasTerminalFailure()) {
                        break;
                    }

                    // No round cap here on purpose. PROJECT_STRATEGY is explicit
                    // that an empty fallback round is not an outcome and does
                    // not end the search: each one excludes what it has already
                    // tried and searches again until the category minimum, the
                    // money limit or the time limit is reached. A cap was added
                    // after one search spent twenty-five minutes on a single
                    // photo, and it was the wrong lever - that run was not
                    // short of rounds, it was rejecting real photographs for
                    // being under the size bar, which the reasons below now
                    // say out loud.
                    $progress?->__invoke(
                        "Раунд резервного поиска {$fallbackRound} не дал загружаемых фото; "
                            ."исключаю проверенные источники и продолжаю поиск.{$details}",
                    );

                    continue;
                }

                $candidateGroups = collect($discoveredCandidates)
                    ->groupBy(function (array $candidate): string {
                        $pageUrl = $candidate['page_source_url'] ?? null;

                        if (is_string($pageUrl) && filter_var($pageUrl, FILTER_VALIDATE_URL)) {
                            return 'page:'.$pageUrl;
                        }

                        // A bare image-search URL has no deterministic product
                        // page relationship. Never merge several such results
                        // merely because they share Shopify/Akamai/a CDN host.
                        return 'image:'.sha1((string) ($candidate['source_url'] ?? 'unknown'));
                    });

                foreach ($candidateGroups as $groupKey => $group) {
                    app(ProductSearchCancellation::class)->throwIfCancelled($telegramUpdateId);
                    $groupPageUrl = str_starts_with((string) $groupKey, 'page:')
                        ? substr((string) $groupKey, 5)
                        : null;
                    $groupCandidates = $group->values()->all();
                    $host = $groupPageUrl ?: (string) ($groupCandidates[0]['source_url'] ?? $groupKey);
                    if ($progress) {
                        $progress("Проверяю найденную галерею: {$host}.");
                    }
                    // Discovery may itself open a newly found exact product
                    // page and successfully train a complete Playwright
                    // recipe. That result is already stronger than a loose
                    // Vision set: keep the page atomic and stop immediately
                    // instead of flattening it into the remaining fallback
                    // candidates and continuing to other stores.
                    $confirmedGroup = collect($groupCandidates)
                        ->filter(fn (array $candidate): bool => (bool) ($candidate['confirmed_gallery'] ?? false))
                        ->values();
                    $groupSource = is_array($groupCandidates[0]['page_source_context'] ?? null)
                        ? $groupCandidates[0]['page_source_context']
                        : ($groupPageUrl ? ['url' => $groupPageUrl] : null);
                    // Wholesale acceptance skips per-frame Vision entirely, so it
                    // is reserved for a source that agrees with the card it is
                    // about to illustrate. A shop selling the same model in a
                    // different configuration passes every identifier check by
                    // design (regional part numbers must not reject good cards),
                    // and its gallery then became the card's source while the
                    // card's own specifications said otherwise. Such a source is
                    // not rejected - its photos are usually the same chassis -
                    // it simply has to earn its frames one by one.
                    // Exact-tier only, and no judge call here: this is the
                    // broad exploratory fallback search, not a candidate
                    // research already vetted, and a shared family/chassis
                    // code is not enough on its own to grant wholesale trust
                    // sight unseen. A group that only manages a generic match
                    // is not discarded - it still earns its frames one at a
                    // time under Vision below, exactly like the main loop.
                    $groupAssessment = is_array($groupSource)
                        ? app(ProductSourceIdentityGate::class)->assessPhotos($draft, $groupSource, $telegramUpdateId)
                        : ['verdict' => 'unavailable'];
                    $groupIdentityConfirmed = in_array($groupAssessment['verdict'] ?? null, ['exact', 'compatible'], true);
                    if (is_array($groupSource)) {
                        $groupSource['_photo_applicability'] = $groupAssessment;
                    }
                    $groupGalleryVerification = null;

                    if (
                        $groupIdentityConfirmed
                        && $confirmedGroup->count() >= $minimumCompleteGallerySize
                        && $groupPageUrl
                    ) {
                        $groupGalleryVerification = $this->verifyConfirmedGallerySafely(
                            $groupPageUrl,
                            $confirmedGroup,
                            $draft,
                            $minimumCompleteGallerySize,
                            $telegramUpdateId,
                        );
                        $confirmedGroup = $groupGalleryVerification['candidates'];
                    }

                    if (
                        $groupIdentityConfirmed
                        && $confirmedGroup->count() >= $minimumCompleteGallerySize
                        && $groupPageUrl
                    ) {
                        // The same gate as the main loop, and now literally the
                        // same code: this branch used to answer the identical
                        // question with a bare `continue`, so a gallery the
                        // language rule had thinned survived one search and was
                        // thrown away by the other.
                        $result = $this->confirmedGalleryResult(
                            $confirmedGroup->map(fn (array $candidate): array => [
                                ...$candidate,
                                'source_identity_confirmed' => true,
                            ])->all(),
                            $draft,
                            $telegramUpdateId,
                            $minimumCompleteGallerySize,
                            $target,
                            $groupSource,
                            'fallback_playwright',
                            'Exact product source and coherent complete Playwright gallery confirmed by the gallery agent during fallback search.',
                            $progress,
                        );

                        if (! $result->isComplete()) {
                            $reserve = $this->keepBestReserve($reserve, $result);

                            continue;
                        }

                        $selected = $result->candidates;
                        $outcome = $result->outcome;
                        $this->destroy($reserve?->candidates ?? []);
                        $reserve = null;
                        $chosenSource = $groupSource;
                        $chosenMethod = 'fallback_playwright';
                        $this->attempts->record([
                            'telegram_update_id' => $telegramUpdateId,
                            'product_draft_id' => $draft->id,
                            'product_url' => $groupPageUrl,
                            'actor' => 'playwright',
                            'phase' => 'image_verification',
                            'action' => 'verify_discovered_gallery',
                            'status' => 'completed',
                            'decision' => 'accept_agent_confirmed_atomic_gallery',
                            'input' => ['downloaded_images' => count($groupCandidates)],
                            'output' => [
                                'accepted_images' => count($selected),
                                'accepted_urls' => collect($selected)->pluck('source_url')->values()->all(),
                            ],
                        ]);
                        $progress?->__invoke('Резервный поиск нашёл пригодную Playwright-галерею: принимаю все '.count($selected).' фото и прекращаю обход источников.');

                        break;
                    }

                    $verificationInterrupted = false;

                    try {
                        $verified = $groupGalleryVerification !== null
                            ? $confirmedGroup->all()
                            : $this->selectFromCandidates($draft, $groupCandidates, $target, $telegramUpdateId);
                    } catch (ProductSearchCancelled $exception) {
                        throw $exception;
                    } catch (Throwable $exception) {
                        report($exception);
                        $verified = [];
                        $verificationInterrupted = true;
                        $progress?->__invoke('Vision резервного поиска временно недоступен; завершаю с лучшим уже полученным результатом.');
                    }
                    $selected = $this->removeNearDuplicates($verified, $excludedHashes);
                    $this->attempts->record([
                        'telegram_update_id' => $telegramUpdateId,
                        'product_draft_id' => $draft->id,
                        'product_url' => $groupPageUrl,
                        'actor' => 'vision',
                        'phase' => 'image_verification',
                        'action' => 'verify_discovered_candidates',
                        'status' => $verificationInterrupted
                            ? 'interrupted'
                            : ($selected !== [] ? 'completed' : 'failed'),
                        'decision' => $verificationInterrupted
                            ? 'retry_source_on_continuation'
                            : (count($selected) >= $minimumCompleteGallerySize
                                ? 'accept_images'
                                : ($selected !== [] ? 'retain_partial_source' : 'reject_candidates')),
                        'input' => ['downloaded_images' => count($groupCandidates)],
                        'output' => [
                            'accepted_images' => count($selected),
                            'accepted_urls' => collect($selected)->pluck('source_url')->values()->all(),
                        ],
                    ]);

                    if ($verificationInterrupted) {
                        continue;
                    }

                    if ($selected !== []) {
                        // The same construction decides both branches, so
                        // "enough frames to win" is answered once instead of by
                        // a count here and a constructor there.
                        $result = GallerySourceResult::fromVerifiedFrames(
                            $selected,
                            $minimumCompleteGallerySize,
                            $target,
                            $groupPageUrl ? ['url' => $groupPageUrl] : null,
                            'fallback_discovery',
                        );

                        if (! $result->isComplete()) {
                            $reserve = $this->keepBestReserve($reserve, $result);
                            $selected = [];

                            continue;
                        }

                        $this->destroy($reserve?->candidates ?? []);
                        $reserve = null;
                        $selected = $result->candidates;
                        $outcome = $result->outcome;
                        $chosenSource = $result->source;
                        $chosenMethod = $result->method;
                        break;
                    }
                }

                $roundKeep = $selected !== []
                    ? $selected
                    : ($reserve?->method === 'fallback_discovery' ? $reserve->candidates : []);
                $this->destroyUnselected($discoveredCandidates, $roundKeep);

                if ($selected !== []) {
                    break;
                }
            }

            if ($selected === [] && $reserve?->hasFrames()) {
                $selected = $reserve->candidates;
                $chosenSource = $reserve->source;
                $chosenMethod = $reserve->method;
                $outcome = $reserve->outcome;
            }
        } elseif ($selected === [] && $progress) {
            $reason = $this->costBudget->exceeded($telegramUpdateId)
                ? sprintf(
                    'Бюджет поиска исчерпан (~$%.2f); новый резервный AI-поиск не запускаю.',
                    $this->costBudget->limit(),
                )
                : ($this->settings->fallbackSourcesEnabled()
                    ? 'Резерв времени достигнут; дополнительный поиск источников не запускаю.'
                    : 'Резервные источники выключены в настройках; дополнительный поиск не запускаю.');
            $progress($reason);
        }

        if ($selected === [] && $reserve?->hasFrames()) {
            $selected = $reserve->candidates;
            $chosenSource = $reserve->source;
            $chosenMethod = $reserve->method;
            $outcome = $reserve->outcome;
        }

        // Last resort only: every normal source and round produced nothing
        // usable. Before finishing with zero photos, fall back to the best
        // candidate that was otherwise acceptable (identity/color/score) but
        // deterministically rejected only for violating the category's photo
        // instruction. The override is explicitly labelled below so nobody
        // mistakes it for a normal, rule-compliant result.
        $categoryHintOverrideUsed = false;

        if ($selected === []) {
            $hintBlockedCandidate = $this->visionVerifier->takeHintBlockedCandidate($draft);

            if ($hintBlockedCandidate !== null) {
                $selected = [$hintBlockedCandidate];
                $chosenSource = $chosenSource ?? (is_string($hintBlockedCandidate['product_page_url'] ?? null)
                    ? ['url' => $hintBlockedCandidate['product_page_url']]
                    : null);
                $chosenMethod = $chosenMethod ?: 'category_hint_override';
                $categoryHintOverrideUsed = true;
            }
        }

        if ($selected !== [] && $chosenSource !== null && $outcome !== GalleryOutcome::Interrupted) {
            $chosenSource = $this->sourceAfterPixelReview($draft, $chosenSource, $selected);
        }

        // Read, not re-derived. This used to ask the method that produced the
        // frames and a structural flag the browser had set on each of them -
        // and confirmed_gallery=true survives on the one frame the language
        // rule left behind, so a partial result reported itself complete. A
        // verification that never ran did the same. Paths that predate the
        // explicit outcome fall back to the old shape of the question, minus
        // the flag that was answering it wrongly.
        $galleryIsPartial = $selected !== [] && match ($outcome) {
            GalleryOutcome::Complete => false,
            null => count($selected) < $minimumCompleteGallerySize
                || collect($selected)->every(fn (array $candidate): bool => (bool) ($candidate['partial_gallery'] ?? false)),
            default => true,
        };
        $roles = ['primary', 'secondary', 'detail'];
        $stored = 0;
        $checksums = [];
        $reusedMediaIds = [];
        $storedSourceUrls = [];
        $stagedMedia = [];
        $newFiles = [];

        foreach ($selected as $candidate) {
            $path = null;

            try {
                $converted = $this->encoder->toWebp($candidate['image']);
                $encoded = $converted['bytes'];
                $checksum = hash('sha256', $encoded);

                if (isset($checksums[$checksum])) {
                    continue;
                }

                $role = $roles[$stored] ?? 'detail';
                $existingMedia = $previousMedia->firstWhere('checksum', $checksum);

                if ($existingMedia) {
                    $stagedMedia[] = ['existing' => $existingMedia, 'attributes' => [
                        'source_url' => $candidate['source_url'],
                        'photo_source_context' => $chosenSource,
                        'role' => $role,
                        'width' => $converted['width'],
                        'height' => $converted['height'],
                        'file_size' => strlen($encoded),
                        'verification_status' => $candidate['verification_status'] ?? 'verified',
                        'gallery_traversal_complete' => ($candidate['confirmed_gallery'] ?? false)
                            && ! ($candidate['partial_gallery'] ?? false),
                        'verification_score' => isset($candidate['vision_score']) ? $candidate['vision_score'] / 100 : null,
                        'verification_model' => $candidate['vision_model'] ?? $candidate['verification_model'] ?? null,
                        'verification_notes' => $candidate['vision_reason'] ?? $candidate['verification_notes'] ?? null,
                        'sort_order' => $stored,
                        'is_primary' => $stored === 0,
                    ]];
                    $reusedMediaIds[] = $existingMedia->id;
                    $checksums[$checksum] = true;
                    $storedSourceUrls[] = $candidate['source_url'];
                    $stored++;

                    continue;
                }

                $path = "drafts/{$draft->id}/{$role}-".substr($checksum, 0, 12).'.webp';

                if (! Storage::disk('public')->put($path, $encoded)) {
                    throw new \RuntimeException("Could not write staged product image: {$path}");
                }

                $stagedMedia[] = ['existing' => null, 'attributes' => [
                    'disk' => 'public',
                    'path' => $path,
                    'source_url' => $candidate['source_url'],
                    'photo_source_context' => $chosenSource,
                    'role' => $role,
                    'mime_type' => 'image/webp',
                    'width' => $converted['width'],
                    'height' => $converted['height'],
                    'file_size' => strlen($encoded),
                    'checksum' => $checksum,
                    'verification_status' => $candidate['verification_status'] ?? 'verified',
                    'gallery_traversal_complete' => ($candidate['confirmed_gallery'] ?? false)
                        && ! ($candidate['partial_gallery'] ?? false),
                    'verification_score' => isset($candidate['vision_score']) ? $candidate['vision_score'] / 100 : null,
                    'verification_model' => $candidate['vision_model'] ?? $candidate['verification_model'] ?? null,
                    'verification_notes' => $candidate['vision_reason'] ?? $candidate['verification_notes'] ?? null,
                    'sort_order' => $stored,
                    'is_primary' => $stored === 0,
                ]];
                $newFiles[] = $path;
                $checksums[$checksum] = true;
                $storedSourceUrls[] = $candidate['source_url'];
                $stored++;
            } catch (Throwable $exception) {
                if ($path) {
                    Storage::disk('public')->delete($path);
                }

                report($exception);
            } finally {
                imagedestroy($candidate['image']);
            }
        }

        if ($stored === 0 && $previousMedia->isNotEmpty()) {
            // A failed write did not replace the old gallery or its source.
            $chosenSource = null;
        }

        $effectiveImageCount = $stored > 0 ? $stored : $previousMedia->count();
        // A round that added nothing new ($stored === 0) must not silently
        // treat a retained-but-still-below-minimum gallery as finished just
        // because it isn't empty. Only a gallery an earlier round already
        // confirmed complete (draft->gallery_status, read here before this
        // call's own update() below overwrites it) or one that already
        // reached the category minimum has genuinely nothing left to find.
        // Counted after the files are on disk, not before. A conversion or a
        // write can fail per frame, and declaring the gallery complete from the
        // number of frames chosen would call a half-written set finished.
        $galleryIsPartial = $galleryIsPartial || ($stored > 0 && $stored < $minimumCompleteGallerySize);
        // Frames kept from a check that never ran are photographs waiting for a
        // verdict, not a finished gallery. Without this, five pending frames
        // and a category minimum of three ended the next pass as a success by
        // arithmetic alone - the very masking the pending status exists to
        // prevent, arriving one pass later.
        $previousAwaitingVerification = $previousMedia->contains(
            fn ($media): bool => $media->verification_status === 'pending',
        );
        $searchIncomplete = match (true) {
            $stored > 0 => $galleryIsPartial,
            $previousMedia->isEmpty(), $previousAwaitingVerification => true,
            default => $draft->gallery_status !== 'complete' && ! $draft->gallery_confirmed_sufficient,
        };
        $retainedAssemblyPending = $skipOutstandingReconciliation
            && $stored === 0 && $previousMedia->isNotEmpty() && $draft->reconciliationPending();
        $stopReason = ($searchIncomplete || $retainedAssemblyPending)
            ? match (true) {
                $this->costBudget->exceeded($telegramUpdateId) => 'cost_budget',
                ! $this->timeBudget->canStart($telegramUpdateId, 20) => 'time_budget',
                // Every known and AI-discovered source was tried within
                // budget and time and none produced a usable gallery. This
                // is not a dead end: only the money/time limit is allowed to
                // stop a request for good, so this gets the same resumable
                // stop reason as a budget/time cutoff - a later continuation
                // cycle excludes already-tried URLs and explores genuinely
                // new candidates instead of repeating the same failure.
                default => 'exhausted',
            }
        : null;

        // The gallery may be wholesale-trusted (or Vision-confirmed frame by
        // frame) from a source that never had to literally contain the
        // draft's own dedicated identifier - by design, once the operator
        // did not pin an exact sku/model themselves (see
        // ProductIdentityMatcher::confirmsExactIdentifier() and
        // ProductSourceIdentityJudge). That source can genuinely be a
        // different real configuration than the specifications already
        // saved on this draft (a different GPU/RAM/storage sharing the same
        // product line) - autonomously accepting a different, still-suitable
        // variant is intended, but publishing its photos silently next to
        // specifications that were never verified against it is not. This is
        // recomputed fresh from whichever source actually won, regardless of
        // which of the several paths above chose it.
        $identityConfirmedExactly = $stored === 0
            || ! is_array($chosenSource)
            || $this->identityMatcher->confirmsExactIdentifier($draft, $chosenSource);

        // Whichever source actually won gets its specifications checked
        // against it too - not just its identity. A matching sku/model
        // proves this is the right product; it does not by itself prove the
        // RAM/GPU/storage already saved describe THIS page rather than
        // whatever page research originally read them from. See
        // ProductSpecificationReconciler's own docblock for the full
        // contract and PROJECT_STRATEGY.md's "Единый источник карточки".
        $chosenSourceUrl = trim((string) ($chosenSource['url'] ?? ''));
        // No photograph is not a reason to deliver nothing. With no gallery
        // at all, the card is assembled against the page research selected
        // and sent as a text card; approve() still refuses a draft without
        // media, so it cannot be published until photos exist. A card
        // already assembled against that page is not bought a second time,
        // and a page this run saw selling a different product is not the
        // card's source at all - the next page research found is.
        $textOnlySourceUrl = $stored === 0 && $previousMedia->isEmpty()
            ? $this->textCardSourceUrl($draft, $telegramUpdateId ?? $draft->telegram_update_id)
            : '';
        $assemblySource = match (true) {
            $stored > 0 && is_array($chosenSource) && $chosenSourceUrl !== '' => $chosenSource,
            $textOnlySourceUrl !== ''
                && $draft->specifications_reconciled_source_url !== $textOnlySourceUrl => ['url' => $textOnlySourceUrl],
            default => null,
        };
        $savedCandidates = array_column($stagedMedia, 'attributes');
        $previousGalleryIsBetter = $stored > 0 && $this->retainedGalleryOutranks(
            $draft, $previousMedia, $savedCandidates, $galleryIsPartial,
        );
        $candidateChecked = collect($savedCandidates)->every(fn (array $candidate): bool => in_array($candidate['verification_status'], ['verified', 'source_verified'], true));
        try {
            $reconciliationOutcome = $assemblySource !== null && (! $previousGalleryIsBetter || $candidateChecked)
                ? $this->reconcileSpecifications($draft, $assemblySource, $telegramUpdateId)
                : null;
        } catch (Throwable $exception) {
            if ($previousMedia->isEmpty()) {
                // The first gallery still survives a technical assembly error.
                if ($stored > 0) {
                    DB::transaction(function () use ($draft, $stagedMedia, $previousMedia, $reusedMediaIds, $chosenSourceUrl, $storedSourceUrls, $galleryIsPartial, $chosenSource): void {
                        $this->commitStagedGallery($draft, $stagedMedia, $previousMedia, $reusedMediaIds);
                        ProductDraft::withReconciliationWrite(fn () => $draft->update([
                            'primary_source_url' => $chosenSourceUrl ?: $draft->primary_source_url,
                            'photo_source_context' => $chosenSource,
                            'image_urls' => array_values(array_unique($storedSourceUrls)),
                            'gallery_status' => 'partial',
                            'gallery_confirmed_sufficient' => ! $galleryIsPartial,
                            'gallery_notes' => 'Фото сохранены; сборка карточки прервана технической ошибкой.',
                            'gallery_search_stop_reason' => 'specifications_unreconciled',
                            'specifications_reconciled_source_url' => null,
                            'specifications_reconciliation_attempts' => 0,
                            'images_staged_at' => now(),
                        ]));
                    });
                }
            } else {
                Storage::disk('public')->delete($newFiles);
                $draft->refresh();
                ProductDraft::withReconciliationWrite(fn () => $draft->update($previousGalleryState));
            }

            throw $exception;
        }

        $photoConflict = $reconciliationOutcome?->repairContext['photo_applicability_conflict'] ?? null;
        if (is_array($photoConflict) && is_array($chosenSource)) {
            $chosenSource = $this->photoConflictContext($draft, $chosenSource, $photoConflict, $telegramUpdateId);
            foreach ($stagedMedia as &$staged) {
                $staged['attributes']['photo_source_context'] = $chosenSource;
                $staged['attributes']['verification_status'] = 'rejected';
                $staged['attributes']['verification_notes'] = $photoConflict['reason'];
            }
            unset($staged);
            $galleryIsPartial = true;
            $candidateChecked = false;
        }

        // Compare files actually written, not candidate URLs: eight selected
        // frames with seven failed writes are still only one new photo.
        // Recovery must not turn seven checked photos into one just because
        // it came from a later source (request #387). Keep a single coherent
        // gallery and its metadata until a better result is ready to commit.
        if ($previousGalleryIsBetter && (! $candidateChecked || ! $reconciliationOutcome?->isReconciled())) {
            Storage::disk('public')->delete($newFiles);
            $draft->refresh();
            $stopReason = match (true) {
                $this->costBudget->exceeded($telegramUpdateId) => 'cost_budget',
                $reconciliationOutcome?->isBudgetExhausted(),
                ! $this->timeBudget->canStart($telegramUpdateId, 20) => 'time_budget',
                default => $draft->reconciliationPending() ? 'specifications_unreconciled' : null,
            };
            ProductDraft::withReconciliationWrite(fn () => $draft->update([
                ...$previousGalleryState, 'gallery_search_stop_reason' => $stopReason,
            ]));
            $this->attempts->record([
                'telegram_update_id' => $telegramUpdateId, 'product_draft_id' => $draft->id,
                'product_url' => $chosenSource['url'] ?? null, 'actor' => 'server',
                'phase' => 'gallery_selection', 'action' => 'retain_previous_gallery',
                'status' => 'completed', 'decision' => 'preserve_better_verified_gallery',
                'message' => 'Сохранена прежняя проверенная галерея: новый набор слабее и не дал согласованную карточку с проверенными фото.',
                'output' => [
                    'saved_file_count' => $previousMedia->count(), 'candidate_file_count' => $stored,
                    'retained_source_url' => $previousGalleryState['primary_source_url'],
                    'candidate_assembly_status' => $reconciliationOutcome?->status->value,
                    'candidate_assembly_reason' => $reconciliationOutcome?->summary ?? $reconciliationOutcome?->unavailableReason,
                ],
            ]);
            $progress?->__invoke('Сохранены ранее проверенные фото: '.$previousMedia->count()
                .'. Новый источник не дал готовую карточку с проверенными фото; прежнюю галерею оставляю.');

            return $previousMedia->count();
        }

        // A text card built from another research page makes that page the
        // card's source; otherwise the draft would still point at the page it
        // was not built from and read as unfinished.
        $textCardSourceUrl = $stored === 0 && $assemblySource !== null && $reconciliationOutcome?->isReconciled()
            ? $textOnlySourceUrl
            : null;
        $reconciliationPending = $reconciliationOutcome !== null && ! $reconciliationOutcome->isReconciled();
        $reconciliationResult = $this->reconciliationCardFields(
            $draft, $reconciliationOutcome, (string) ($assemblySource['url'] ?? $chosenSourceUrl), $telegramUpdateId,
        );

        $identityNote = $stored > 0 && ! $identityConfirmedExactly
            ? 'Дословное совпадение полного SKU/модели с источником фото не установлено. '
                .'Пригодность источника и готовность карточки определяются отдельными проверками.'
            : null;
        $reconciliationNote = $reconciliationPending
            ? 'Характеристики не сверены с источником фото'
                .($reconciliationOutcome->summary !== null ? ': '.$reconciliationOutcome->summary : '')
                .'. Публикация недоступна, пока сверка не завершится.'
            : null;
        $categoryHintNote = $stored > 0 && $categoryHintOverrideUsed
            ? 'Не найдено ни одного фото без нарушения подсказки категории. '
                .'Принято как крайний вариант фото, нарушающее подсказку (см. заметку проверки у фото).'
            : null;
        $partialNote = $stored > 0 && $galleryIsPartial
            ? 'После полного цикла поиска сохранён лучший частичный результат: '.$stored.' проверенных фото.'
            : null;
        $galleryNotes = implode(' ', array_filter([$categoryHintNote, $identityNote, $reconciliationNote, $partialNote]));

        $finalFields = [...[
            'primary_source_url' => $chosenSource['url'] ?? $textCardSourceUrl ?? $draft->primary_source_url,
            'photo_source_context' => $stored > 0 ? $chosenSource : $draft->photo_source_context,
            'image_urls' => $stored > 0 ? array_values(array_unique($storedSourceUrls)) : $draft->image_urls,
            'gallery_status' => $stored > 0
                ? (($galleryIsPartial || $reconciliationPending) ? 'partial' : 'complete')
                : ($previousMedia->isNotEmpty() ? $draft->gallery_status : 'missing'),
            // The gallery's own completeness, independent of whether
            // specifications reconciliation also succeeded - gallery_status
            // conflates the two once reconciliation is pending (both read
            // 'partial'), so resumeOutstandingReconciliation() needs this
            // separate, persisted fact rather than re-deriving "is the
            // gallery done" from a live media count later.
            'gallery_confirmed_sufficient' => $stored > 0
                ? ! $galleryIsPartial
                : ($previousMedia->isNotEmpty() ? $draft->gallery_confirmed_sufficient : false),
            'gallery_notes' => $galleryNotes !== '' ? $galleryNotes : ($retainedAssemblyPending ? $draft->gallery_notes : null),
            // An exhausted photo pass must not hide a stopped card assembly
            // and start another automatic queue cycle repeating its retries.
            'gallery_search_stop_reason' => $reconciliationPending
                ? ($reconciliationResult['stop_reason'] ?? $stopReason)
                : ($stopReason ?? $reconciliationResult['stop_reason']),
            'images_staged_at' => now(),
        ], ...$reconciliationResult['fields']];
        try {
            DB::transaction(function () use ($draft, $stagedMedia, $previousMedia, $reusedMediaIds, $stored, $finalFields): void {
                $keyboardStaging = $stored > 0 ? $stagedMedia : null;
                $finalFields = [...$finalFields, ...$this->reconciledKeyboardFields(
                    $draft, $finalFields, $finalFields['photo_source_context'] ?? [], $keyboardStaging,
                )];
                if ($stored > 0) {
                    $this->commitStagedGallery($draft, $keyboardStaging, $previousMedia, $reusedMediaIds);
                }
                ProductDraft::withReconciliationWrite(fn () => $draft->update($finalFields));
            });
        } catch (Throwable $exception) {
            Storage::disk('public')->delete($newFiles);

            throw $exception;
        }
        if ($stored > 0 && is_array($chosenSource) && is_string($chosenSource['url'] ?? null)) {
            $this->sourceMetrics->recordAcceptedGallery($chosenSource['url'], $stored);
        }

        if ($progress) {
            if ($this->lastDegradedDomains !== []) {
                $progress(
                    '⚠️ Рецепт для '.implode(', ', $this->lastDegradedDomains)
                    .' помечен на переобучение: подтверждённые в браузере фото не удалось скачать обычным запросом (сайт отдаёт их только внутри своей сессии).',
                );
            }

            if ($stored > 0) {
                $methodLabel = match ($chosenMethod) {
                    'playwright' => 'через Playwright-рецепт галереи',
                    'playwright_vision' => 'через Playwright-рецепт галереи (кадры проверены Vision)',
                    'fallback_playwright' => 'через Playwright-галерею карточки, найденной резервным поиском',
                    'fallback_playwright_vision' => 'через Playwright-галерею карточки, найденной резервным поиском (неоднозначный слайдер, кадры проверены Vision)',
                    'fallback_discovery' => 'через резервный поиск (источник не из карточек AI-исследования)',
                    default => 'из статичной HTML-галереи карточки',
                };
                $sourceUrl = is_array($chosenSource) && is_string($chosenSource['url'] ?? null)
                    ? $chosenSource['url']
                    : null;
                $progress(
                    "📦 Итог: ✅ {$stored} фото {$methodLabel}"
                    .($galleryIsPartial ? ' · ⚠️ частичный результат' : '').'.'
                    .($sourceUrl ? "\n🔗 {$sourceUrl}" : ''),
                );
            } else {
                $progress('📦 Итог: ❌ подходящая галерея не найдена ни по одному источнику.');
            }
        }

        return $stored;
    }

    /** Replace database references together, only after the gallery selection decision. */
    private function commitStagedGallery(ProductDraft $draft, array $stagedMedia, Collection $previousMedia, array $reusedMediaIds): void
    {
        DB::transaction(function () use ($draft, $stagedMedia, $previousMedia, $reusedMediaIds): void {
            foreach ($stagedMedia as $staged) {
                if ($staged['existing'] instanceof ProductDraftMedia) {
                    $staged['existing']->update($staged['attributes']);
                } else {
                    $draft->media()->create($staged['attributes']);
                }
            }
            if ($stagedMedia !== []) {
                foreach ($previousMedia->whereNotIn('id', $reusedMediaIds) as $media) {
                    $media->delete();
                }
            }
        });
    }

    /** A saved verified gallery remains a reserve, never a source of mixed frames. */
    private function retainedGalleryOutranks(
        ProductDraft $draft,
        Collection $previousMedia,
        array $selected,
        bool $galleryIsPartial,
    ): bool {
        if ($previousMedia->isEmpty()
            || trim((string) $draft->primary_source_url) === ''
            || $this->sourceExcludedForDraft($draft->primary_source_url, $draft)
            || $previousMedia->contains(fn (ProductDraftMedia $media): bool => ! in_array($media->verification_status, ['verified', 'source_verified'], true)
                || in_array($media->source_url, $draft->excluded_gallery_image_urls ?? [], true)
                || ! Storage::disk($media->disk)->exists($media->path))) {
            return false;
        }

        $candidateUnchecked = collect($selected)->contains(fn (array $candidate): bool => ! in_array($candidate['verification_status'] ?? 'verified', ['verified', 'source_verified'], true));

        return $candidateUnchecked
            || [$previousMedia->count(), (int) $draft->gallery_confirmed_sufficient]
                > [count($selected), (int) ! $galleryIsPartial];
    }

    /**
     * Persists whatever specification text was captured while the winning
     * source's page was actually open (see ProductImageResolver's
     * extractPageSpecificationText()/lastSpecificationText(), threaded here
     * through specificationTextForPage() and the source's own
     * _preflight_specification_text), then hands it to
     * ProductSpecificationReconciler. No fresh fetch happens here - see that
     * class's own docblock for why a fetch at this point would be the wrong
     * tool for exactly the sources this feature was built for.
     *
     * @param  array<string, mixed>  $chosenSource
     */
    private function reconcileSpecifications(ProductDraft $draft, array $chosenSource, ?int $telegramUpdateId): SpecificationReconciliationOutcome
    {
        app(ProductSearchCancellation::class)->throwIfCancelled($telegramUpdateId);
        $url = trim((string) ($chosenSource['url'] ?? ''));
        $liveText = $this->resolver->specificationTextForPage($url);
        $specificationText = trim((string) ($liveText ?? ($chosenSource['_preflight_specification_text'] ?? '')));

        if ($specificationText !== '') {
            ProductSourcePageEvidence::query()->updateOrCreate(
                ['product_draft_id' => $draft->id, 'url_hash' => hash('sha256', self::normalizeCandidateUrl($url))],
                ['url' => $url, 'specification_text' => $specificationText, 'captured_via' => $liveText !== null ? 'resolve' : 'preflight'],
            );
        }

        $source = [
            ...$chosenSource,
            'url' => $url,
            'title' => $chosenSource['title'] ?? null,
            '_preflight_identity_evidence' => $chosenSource['_preflight_identity_evidence'] ?? null,
        ];
        if (! ProductPhotoApplicability::isAccepted($draft, $source, $source['_photo_applicability'] ?? [])) {
            return app(ProductCardAssembly::class)->complete($draft, $source, $telegramUpdateId);
        }
        $state = $draft->card_repair_state ?? [];
        try {
            return app(ProductCardAssembly::class)->resumeFacts($draft, $source, $telegramUpdateId, $state);
        } finally {
            $draft->update(['card_repair_state' => $state]);
        }
    }

    /**
     * Turns one reconciliation outcome into the fields every one of the
     * three call sites (the main finalization block, resumeInterruptedVerification(),
     * resumeOutstandingReconciliation()) needs to write - kept in one place
     * so the whole-card update and the attempt-tracking logic exist exactly
     * once.
     *
     * $attemptedUrl is the source this specific attempt was made against;
     * compared against the draft's OWN in-memory (pre-update) primary_source_url
     * to tell "still retrying the same source" (accumulate the attempt
     * count) from "a genuinely new source" (start over) - a fresh source
     * deserves a fresh chance, not a stale count.
     *
     * budget_exhausted is mapped to the same cost_budget/time_budget stop
     * reasons the ordinary search already uses, specifically because those
     * two - unlike 'specifications_unreconciled' - already never auto-chain
     * anywhere in this codebase; every other failure reason is bounded
     * instead by the attempt counter ContinueDraftGallerySearch reads.
     *
     * @return array{fields: array<string, mixed>, stop_reason: ?string}
     */
    private function reconciliationCardFields(
        ProductDraft $draft,
        ?SpecificationReconciliationOutcome $outcome,
        string $attemptedUrl,
        ?int $telegramUpdateId,
    ): array {
        if ($outcome === null) {
            return ['fields' => [], 'stop_reason' => null];
        }

        if ($outcome->isReconciled()) {
            return [
                'fields' => [
                    'title' => $outcome->title ?? $draft->title,
                    'model' => $outcome->model ?? $draft->model,
                    'color' => $outcome->status === SpecificationReconciliationStatus::Reconciled ? $outcome->color : $draft->color,
                    'description' => $outcome->description ?? $draft->description,
                    'specifications' => $outcome->specifications ?? $draft->specifications,
                    'translations' => $outcome->translations ?? $draft->translations,
                    'specifications_reconciled_source_url' => $outcome->reconciledSourceUrl,
                    'specifications_reconciliation_attempts' => 0,
                ],
                'stop_reason' => null,
            ];
        }

        // Spent money never stops card assembly (see ProductCardAssembly), so
        // an exhausted assembly ran out of the job's time, whatever was spent.
        if ($outcome->isBudgetExhausted()) {
            return [
                'fields' => [],
                'stop_reason' => 'time_budget',
            ];
        }

        $sameSourceAsBefore = $attemptedUrl !== '' && $attemptedUrl === trim((string) $draft->primary_source_url);
        // New evidence this attempt itself found (the agent's own tool
        // uncovered something on this same source) is progress, not a
        // repeat of the exact same failed work - it must not count toward
        // the cap that stops automatic retries, the same way a genuinely
        // new source does not.
        $noNewProgress = $sameSourceAsBefore && ! $outcome->madeProgress;

        return [
            'fields' => [
                'specifications_reconciliation_attempts' => $noNewProgress
                    ? $draft->specifications_reconciliation_attempts + 1
                    : 1,
            ],
            'stop_reason' => 'specifications_unreconciled',
        ];
    }

    /**
     * The other half of "Continue" resuming reconciliation, not search" - a
     * fresh entry into stage() (a queued continuation, hours or days later)
     * for a draft whose only outstanding problem, last time, was
     * reconciliation itself.
     *
     * Deliberately NOT triggered by gallery_search_stop_reason being exactly
     * 'specifications_unreconciled' - real bug (2026-09-11): a reconciliation
     * attempt that failed specifically because the shared search budget was
     * exhausted is recorded as 'cost_budget'/'time_budget' (so it gets the
     * same non-auto-chaining behavior the ordinary search's own budget stop
     * already has), but that meant the very next continuation missed this
     * method entirely and fell through to a full, unnecessary re-search.
     * The real, sufficient condition is state, not the reason string: photos
     * are already enough, and specifications_reconciled_source_url does not
     * match the current source - true regardless of which stop reason got
     * recorded, and still false for an ordinary draft that has never
     * reached this point at all (a fresh draft's primary_source_url and
     * specifications_reconciled_source_url are both null, matching and
     * short-circuiting correctly).
     */
    private function resumeOutstandingReconciliation(
        ProductDraft $draft,
        ?int $telegramUpdateId,
        ?callable $progress,
    ): ?int {
        $primarySourceUrl = trim((string) $draft->primary_source_url);

        if ($primarySourceUrl === '' || $draft->specifications_reconciled_source_url === $primarySourceUrl) {
            return null;
        }

        // The gallery's own completeness must be a fact an earlier round
        // actually established (see gallery_confirmed_sufficient's own
        // docblock at its migration/finalization sites), not re-derived from
        // however many media rows happen to exist right now - a live count
        // cannot tell a gallery a prior round verified complete from one
        // that merely has enough rows on disk for reasons unrelated to that
        // verification (e.g. rows left over from a run that never finished).
        // The existence check on top is a sanity guard, not the criterion
        // itself: a confirmed-sufficient draft with no media left at all has
        // nothing to reconcile a card against.
        if (! $draft->gallery_confirmed_sufficient || ! $draft->media()->exists()) {
            // Photos themselves are still the actual outstanding problem -
            // let the ordinary search flow run instead of only retrying
            // reconciliation on what would still be an incomplete gallery.
            return null;
        }

        $progress?->__invoke('Повторно сверяю характеристики с уже выбранным источником фото — поиск и обучение рецепта не повторяются.');
        $outcome = $this->reconcileSpecifications($draft, [...($draft->photo_source_context ?? []), 'url' => $primarySourceUrl], $telegramUpdateId);
        $photoConflict = $outcome->repairContext['photo_applicability_conflict'] ?? null;
        if (is_array($photoConflict)) {
            $context = $this->photoConflictContext($draft, [...($draft->photo_source_context ?? []), 'url' => $primarySourceUrl], $photoConflict, $telegramUpdateId);
            DB::transaction(function () use ($draft, $context, $photoConflict): void {
                $draft->media()->update(['verification_status' => 'rejected',
                    'photo_source_context' => json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'verification_notes' => $photoConflict['reason']]);
                $draft->update(['photo_source_context' => $context, 'gallery_confirmed_sufficient' => false]);
            });
        }
        $reconciled = $outcome->isReconciled();
        $result = $this->reconciliationCardFields($draft, $outcome, $primarySourceUrl, $telegramUpdateId);
        DB::transaction(function () use ($draft, $reconciled, $result): void {
            $fields = [...[
                'gallery_status' => $reconciled ? 'complete' : 'partial',
                'gallery_search_stop_reason' => $result['stop_reason'],
                'images_staged_at' => now(),
            ], ...$result['fields']];
            $fields = [...$fields, ...$this->reconciledKeyboardFields($draft, $fields, $draft->photo_source_context ?? [])];
            ProductDraft::withReconciliationWrite(fn () => $draft->update($fields));
        });
        $progress?->__invoke(match (true) {
            $reconciled => 'Характеристики сверены с источником фото.',
            $outcome->isBudgetExhausted() => 'Бюджет поиска исчерпан; сверка характеристик приостановлена.',
            default => 'Характеристики пока не удалось сверить с источником фото; попробую снова позже.',
        });

        return $draft->media()->count();
    }

    private function buildSourceQueue(
        ProductDraft $draft,
        array $cycleSources,
        array $cycleExcludedSourceUrls,
        bool $activeRecipeOnly,
        int $minimumCompleteGallerySize,
        ?int $telegramUpdateId,
        ?callable $progress,
    ): array {
        return $this->sourceQueue->buildSourceQueue($draft, $cycleSources, $cycleExcludedSourceUrls, $activeRecipeOnly, $minimumCompleteGallerySize, $telegramUpdateId, $progress);
    }

    private function gallerySearchStrategy(ProductDraft $draft): string
    {
        $slug = trim((string) $draft->category);

        if ($slug === '') {
            return Category::GALLERY_SEARCH_AUTO;
        }

        $category = Category::query()->where('slug', $slug)->first();

        return $category?->gallerySearchStrategy() ?? Category::GALLERY_SEARCH_AUTO;
    }

    /**
     * A malformed/invalid AI response from the underlying Vision call must
     * not crash the entire multi-source search. Real case (2026-08-26): a
     * Rozetka gallery spot-check's Vision response was missing several
     * required fields (schema validation failed with "images.0.reason
     * обязательно для заполнения" and 8 more errors) - this was the one
     * confirmed-gallery Vision path in stage() without a surrounding
     * try/catch, so the exception propagated all the way out through
     * ResearchProduct's tool call, burning the whole search budget and
     * getting misattributed to the unrelated, already-completed research
     * AiRun row. Every other Vision call in this class already degrades
     * gracefully on failure (see the deferred-sets loop above); this makes
     * confirmedGalleryVerifier's calls do the same - an empty, unconfirmed
     * result, so the caller falls through to its existing "try the next
     * candidate" path exactly as if Vision had rejected everything.
     *
     * @param  Collection<int, array<string, mixed>>  $candidates
     * @return array{mode: string, candidates: Collection<int, array<string, mixed>>, cached: bool}
     */
    /**
     * The language gate for a gallery about to be accepted whole, plus the
     * bookkeeping that keeps its verdict visible.
     *
     * Frames it removes are destroyed here rather than left on disk, and the
     * removal is recorded, because "the card came back with four photos instead
     * of seven" is otherwise an unexplainable difference.
     *
     * Null means the question went unanswered, which is not the same as an
     * answer of "nothing found".
     *
     * @param  array<int, array<string, mixed>>  $candidates
     * @return array<int, array<string, mixed>>|null
     */
    /**
     * The reserve is whichever set is worth more, and the loser's pixels are
     * freed here rather than by whoever happens to remember.
     *
     * The one exception is a set the fallback discovery round owns: that loop
     * frees its own round candidates afterwards, and freeing them here too
     * would destroy the same image twice.
     */
    private function keepBestReserve(?GallerySourceResult $reserve, GallerySourceResult $candidate): ?GallerySourceResult
    {
        if (! $candidate->isBetterReserveThan($reserve)) {
            if ($candidate->method !== 'fallback_discovery') {
                $this->destroy($candidate->candidates);
            }

            return $reserve;
        }

        if ($reserve !== null && $reserve->method !== 'fallback_discovery') {
            $this->destroy($reserve->candidates);
        }

        return $candidate;
    }

    /**
     * A confirmed gallery becomes a verdict here, and only here.
     *
     * Both searches - the ranked sources and the fallback discovery - used to
     * ask this question with their own copy of the code, and the copies had
     * drifted: one kept the frames the language rule left behind, the other
     * dropped them with a bare `continue`. The same set therefore produced a
     * photograph in one search and nothing in the other.
     *
     * @param  array<int, array<string, mixed>>  $frames
     */
    private function confirmedGalleryResult(
        array $frames,
        ProductDraft $draft,
        ?int $telegramUpdateId,
        int $minimum,
        int $target,
        ?array $source,
        string $method,
        string $notes,
        ?callable $progress,
    ): GallerySourceResult {
        $checked = $this->checkedGalleryFrames($frames, $draft, $telegramUpdateId, $minimum, $progress);
        if ($checked !== null && $checked !== [] && $source !== null) {
            $source = $this->sourceAfterPixelReview($draft, $source, $checked);
            // A colour unknown in text needs positive pixel evidence. Views
            // that cannot show colour do not silently turn uncertainty into exact.
            if ($this->modelConfirmedAwaitingColour($source)) {
                $checked = null;
            }
        }
        $result = GallerySourceResult::fromVerifiedGallery(
            $checked,
            $frames,
            $minimum,
            $target,
            $source,
            $method,
            $notes,
        );

        if (! $result->isComplete()) {
            $this->attempts->record([
                'telegram_update_id' => $telegramUpdateId, 'product_draft_id' => $draft->id,
                'product_url' => $source['url'] ?? null, 'actor' => 'vision', 'phase' => 'image_verification',
                'action' => 'verify_gallery',
                'status' => $result->outcome === GalleryOutcome::Interrupted ? 'interrupted' : 'completed',
                'decision' => $result->reason,
                'input' => ['downloaded_images' => count($frames)],
                'output' => ['accepted_images' => $result->outcome === GalleryOutcome::Interrupted ? null : count($result->candidates)],
            ]);
        }

        return $result;
    }

    /**
     * Finish a verification that was interrupted rather than repeating the
     * search that produced it.
     *
     * Returns the number of photographs the draft now holds when the check
     * completed and the gallery is done, and null when there was nothing to
     * resume or the answer still is not available - in which case the ordinary
     * search runs exactly as before.
     */
    private function resumeInterruptedVerification(
        ProductDraft $draft,
        int $minimumCompleteGallerySize,
        int $maximumImages,
        ?int $telegramUpdateId,
        ?callable $progress,
        array &$completedSourceUrls,
    ): ?int {
        $pending = $draft->media()->where('verification_status', 'pending')->get();

        if ($pending->isEmpty()) {
            return null;
        }

        $candidates = [];

        foreach ($pending as $media) {
            $bytes = rescue(fn (): string|false => Storage::disk($media->disk)->get($media->path), false, false);
            $image = is_string($bytes) && $bytes !== '' ? @imagecreatefromstring($bytes) : false;

            if (! $image instanceof GdImage) {
                // A frame whose file is gone cannot be verified from here, and
                // guessing about the rest of a set is not verification.
                $this->destroy($candidates);

                return null;
            }

            $candidates[] = [
                'image' => $image,
                'source_url' => $media->source_url,
                'media_id' => $media->id,
                'confirmed_gallery' => true,
                'page_source_context' => $media->photo_source_context,
                'source_identity_confirmed' => ProductPhotoApplicability::isAccepted($draft, $media->photo_source_context ?? [],
                    $media->photo_source_context['_photo_applicability'] ?? []),
            ];
        }

        $progress?->__invoke('Возобновляю прерванную проверку уже скачанных фото: '.count($candidates).' шт.');
        $checked = $this->checkedGalleryFrames(
            $candidates,
            $draft,
            $telegramUpdateId,
            $minimumCompleteGallerySize,
            $progress,
        );
        $this->destroy($candidates);

        if ($checked === null) {
            // No verdict yet: retain every frame for a subsequent attempt.
            return null;
        }

        // Select the final album only AFTER checking the entire pending set.
        $checked = array_slice($checked, 0, max(1, $maximumImages));
        $keptIds = collect($checked)->pluck('media_id')->filter()->all();
        $retainedReviews = collect($draft->photo_source_context['_photo_visual_review']['frames'] ?? [])
            ->filter(fn (array $review): bool => ! in_array($review['source_url'] ?? null, $pending->pluck('source_url')->all(), true))
            ->map(fn (array $review): array => ['photo_visual_review' => $review])->values()->all();
        $reviewedContext = $this->sourceAfterPixelReview($draft, $draft->photo_source_context ?? [], [...$checked, ...$retainedReviews]);
        $draft->media()->whereIn('id', $keptIds)->get()->each(fn ($media) => $media->update([
            'verification_status' => 'source_verified',
            'verification_notes' => 'Проверка содержания завершена при продолжении поиска.',
            'photo_source_context' => $reviewedContext,
        ]));
        $draft->media()->whereIn('id', $pending->modelKeys())->whereNotIn('id', $keptIds)->get()
            ->each(fn ($media) => $media->delete());
        $stored = $draft->media()->count();
        $traversalComplete = $pending->every(fn ($media): bool => $media->gallery_traversal_complete === true);
        $gallerySufficient = $traversalComplete && count($keptIds) >= $minimumCompleteGallerySize;
        // Removing the former first frame must not leave an album without a
        // cover, or keep gaps in its ordering. Paths need not be renamed.
        foreach ($draft->media()->get()->values() as $index => $media) {
            $media->update(['sort_order' => $index, 'is_primary' => $index === 0,
                'role' => ['primary', 'secondary'][$index] ?? 'detail', 'photo_source_context' => $reviewedContext]);
        }
        $draft->update([
            'image_urls' => $draft->media()->orderBy('sort_order')->pluck('source_url')->filter()->values()->all(),
            'photo_source_context' => $reviewedContext,
            'gallery_status' => $stored > 0 ? 'partial' : 'missing',
            // Persist the gallery's own verdict before card assembly: if
            // assembly is cancelled, continuation must not reopen the browser.
            'gallery_confirmed_sufficient' => $gallerySufficient,
        ]);

        if (! $gallerySufficient) {
            // A real negative verdict must not remain pending. Keep the clean
            // partial, remove rejected files, and let ordinary discovery run.
            $sourceUrl = trim((string) $draft->primary_source_url);
            if ($traversalComplete && $sourceUrl !== '') {
                // We checked the whole already-finished gallery. Reopening it
                // immediately would buy the same negative verdict again. An
                // incomplete/unknown traversal is deliberately NOT exhausted.
                $completedSourceUrls[] = $sourceUrl;
                $this->attempts->record([
                    'telegram_update_id' => $telegramUpdateId, 'product_draft_id' => $draft->id,
                    'product_url' => $sourceUrl, 'actor' => 'vision', 'phase' => 'image_verification',
                    'action' => 'verify_gallery', 'status' => 'completed',
                    'decision' => 'verified_below_minimum',
                    'output' => ['accepted_images' => count($keptIds), 'resumed' => true],
                ]);
            }

            return null;
        }
        // This path has no $chosenSource of its own (it finishes a verdict
        // on frames a prior cycle already downloaded, never re-deriving
        // which source produced them) - the persisted column is what it has.
        $primarySourceUrl = trim((string) $draft->primary_source_url);
        $reconciliationOutcome = $gallerySufficient && $primarySourceUrl !== ''
            ? $this->reconcileSpecifications($draft, [...($draft->photo_source_context ?? []), 'url' => $primarySourceUrl], $telegramUpdateId)
            : null;
        $reconciliationPending = $reconciliationOutcome !== null && ! $reconciliationOutcome->isReconciled();
        $reconciliationResult = $this->reconciliationCardFields($draft, $reconciliationOutcome, $primarySourceUrl, $telegramUpdateId);
        DB::transaction(function () use ($draft, $gallerySufficient, $reconciliationPending, $reconciliationResult): void {
            $fields = [...[
                'gallery_status' => ($gallerySufficient && ! $reconciliationPending) ? 'complete' : 'partial',
                'gallery_confirmed_sufficient' => $gallerySufficient,
                'gallery_search_stop_reason' => ! $gallerySufficient ? 'exhausted' : $reconciliationResult['stop_reason'],
                'images_staged_at' => now(),
            ], ...$reconciliationResult['fields']];
            $fields = [...$fields, ...$this->reconciledKeyboardFields($draft, $fields, $draft->photo_source_context ?? [])];
            ProductDraft::withReconciliationWrite(fn () => $draft->update($fields));
        });
        $progress?->__invoke('Проверка завершена без повторного поиска: '.$stored.' фото подтверждено.');

        return $stored;
    }

    private function checkedGalleryFrames(
        array $candidates,
        ProductDraft $draft,
        ?int $telegramUpdateId,
        int $minimumImages,
        ?callable $progress,
    ): ?array {
        app(ProductSearchCancellation::class)->throwIfCancelled($telegramUpdateId);
        // Exact page identity is provenance, never a waiver for the current
        // pixels: UGC, another colour/body and visible keyboard differences all
        // require the same full review as a compatible source.
        $kept = $this->visionVerifier->reviewCompleteGallery($candidates, $draft, $telegramUpdateId);
        app(ProductSearchCancellation::class)->throwIfCancelled($telegramUpdateId);

        if ($kept === null) {
            // Accepting the gallery anyway would turn a timeout into "Vision
            // cleared it" - PROJECT_STRATEGY forbids a technical failure from
            // becoming a semantic verdict. The frames survive as a partial
            // result; what they do not get is confirmation.
            $this->attempts->record([
                'telegram_update_id' => $telegramUpdateId,
                'product_draft_id' => $draft->id,
                'actor' => 'vision',
                'phase' => 'image_verification',
                'action' => 'check_frame_content_and_language',
                'status' => 'interrupted',
                'decision' => 'gallery_check_unavailable',
                'input' => ['frames' => count($candidates)],
            ]);
            $progress?->__invoke('Проверка содержания и текста кадров не завершена; сохраняю найденное без подтверждения.');

            return null;
        }

        $pendingColourSource = collect($candidates)->pluck('page_source_context')
            ->first(fn ($context): bool => is_array($context) && $this->modelConfirmedAwaitingColour($context));
        if ($kept !== [] && $pendingColourSource !== null
            && $this->modelConfirmedAwaitingColour($this->sourceAfterPixelReview($draft, $pendingColourSource, $kept))) {
            // Do not destroy rejected-frame resources before the caller saves
            // the set as pending for a genuinely unfinished colour check.
            return null;
        }

        $removed = count($candidates) - count($kept);

        $enough = count($kept) >= $minimumImages;
        // Recorded on every outcome, the clean one included: "Vision looked and
        // allowed everything" is what an audit is most often asked for, and it
        // used to leave no trace at all.
        $this->attempts->record([
            'telegram_update_id' => $telegramUpdateId,
            'product_draft_id' => $draft->id,
            'actor' => 'vision',
            'phase' => 'image_verification',
            'action' => 'check_frame_content_and_language',
            'status' => $enough ? 'completed' : 'failed',
            'decision' => match (true) {
                $removed === 0 => 'accept_all_frames',
                $enough => 'drop_non_product_or_foreign_text_frames',
                $kept === [] => 'reject_all_frames',
                default => 'keep_partial_after_frame_review',
            },
            'output' => ['removed' => $removed, 'kept' => count($kept)],
        ]);

        if ($removed > 0) {
            // The rejected frames go; the clean ones stay, because
            // PROJECT_STRATEGY keeps what survives the language rule as a
            // partial result rather than discarding it with the gallery.
            $this->destroyUnselected($candidates, $kept);
            $progress?->__invoke($enough
                    ? "Vision исключил {$removed} кадр(а/ов) по требованиям каталога; остаётся ".count($kept).'.'
                    : "Vision исключил {$removed} кадр(а/ов) по требованиям каталога; пригодных кадров осталось "
                    .count($kept).' как частичный результат и проверяю следующий источник.');
        }

        return $kept;
    }

    private function modelAwaitingPixelInspection(ProductDraft $draft, array $source): bool
    {
        if ($this->modelConfirmedAwaitingColour($source)) {
            return true;
        }

        $assessment = $source['_photo_applicability'] ?? [];
        $checks = $assessment['physical_checks'] ?? [];
        $observed = $source['_preflight_page_identity'] ?? [];
        if (($assessment['verdict'] ?? null) !== 'uncertain'
            || ! ($assessment['plausible_target'] ?? false) || ! ($assessment['may_inspect_gallery'] ?? false)
            || blank($assessment['source_model'] ?? null) || ($checks['platform']['status'] ?? null) !== 'match'
            || ($checks['revision']['status'] ?? null) !== 'unknown'
            || collect($checks)->contains(fn (array $check): bool => ($check['status'] ?? null) === 'conflicting')
            || blank($observed['observed_url'] ?? null)
            || rtrim($observed['observed_url'], '/') !== rtrim($source['url'] ?? '', '/')) {
            return false;
        }

        // Only the observed main Product can corroborate a code, never search
        // labels or recommended products. This admits a pixel check; it does
        // not turn an unknown revision into exact identity or allow promotion
        // from a matching colour alone.
        $normalize = fn (mixed $value): string => mb_strtolower(trim((string) $value));
        $codes = collect($draft->specifications ?? [])
            ->filter(fn (mixed $spec): bool => is_array($spec)
                && in_array($spec['key'] ?? null, ['sku', 'mpn', 'ean', 'upc', 'gtin'], true))
            ->map(fn (array $spec): string => $normalize($spec['value'] ?? ''))->filter();

        return collect($observed['product'] ?? [])->only(['sku', 'mpn', 'gtin', 'gtin8', 'gtin12', 'gtin13', 'gtin14'])
            ->contains(fn (mixed $value): bool => is_scalar($value) && $codes->contains($normalize($value)));
    }

    private function modelConfirmedAwaitingColour(array $source): bool
    {
        $assessment = $source['_photo_applicability'] ?? [];
        $checks = $assessment['physical_checks'] ?? [];

        return ($assessment['verdict'] ?? null) === 'uncertain'
            && ($assessment['plausible_target'] ?? false)
            && ($assessment['may_inspect_gallery'] ?? false)
            && filled($assessment['source_model'] ?? null)
            && ($checks['platform']['status'] ?? null) === 'match'
            && ($checks['revision']['status'] ?? null) === 'match'
            && ($checks['color']['status'] ?? null) === 'unknown'
            && ! collect($checks)->contains(fn (array $check): bool => ($check['status'] ?? null) === 'conflicting');
    }

    private function sourceAfterPixelReview(ProductDraft $draft, array $source, array $frames): array
    {
        $reviews = collect($frames)->pluck('photo_visual_review')->filter(fn ($review): bool => is_array($review));
        $differences = $reviews->filter(fn (array $review): bool => ($review['keyboard_layout_match'] ?? null) === 'different'
            || (($review['keyboard_layout_match'] ?? null) === 'unknown'
                && ($review['keyboard_visibility'] ?? null) === 'visible_unreadable'
                && ($review['keyboard_requirement_scope'] ?? null) === 'product'))
            ->pluck('keyboard_layout_note')->filter()->unique()->values()->all();
        $source['_photo_visual_review'] = ['scope' => 'current_downloaded_frames',
            'keyboard_target' => ProductPhotoKeyboardContext::target($draft->specifications ?? []),
            'photo_requirement' => ProductPhotoKeyboardContext::photoRequirement(ProductInputContext::forDraft($draft)),
            'differences' => $differences, 'frames' => $reviews->values()->all()];
        unset($source['_photo_keyboard_reconciliation']);
        if ($this->modelConfirmedAwaitingColour($source)
            && $reviews->contains(fn (array $review): bool => ($review['color_observability'] ?? null) === 'clear'
                && ($review['color_match'] ?? false))) {
            $assessment = $source['_photo_applicability'];
            $assessment['verdict'] = 'exact';
            $assessment['reason'] = 'Observed full model and current catalog photographs confirm the selected colour.';
            $assessment['assessment_scope'] = 'source_metadata_and_current_pixels';
            $assessment['physical_checks']['color'] = ['status' => 'match',
                'evidence' => 'Current downloaded catalog frames visibly match the selected colour.'];
            $source['_photo_applicability'] = ProductPhotoApplicability::bind($draft, $source, $assessment);
        }

        return $source;
    }

    /** Called inside the existing card/media transaction; never downloads or re-encodes photos. */
    private function reconciledKeyboardFields(ProductDraft $draft, array $fields, array $source, ?array &$staged = null): array
    {
        if (! isset($fields['specifications'])) {
            return [];
        }
        $updated = ProductPhotoKeyboardContext::reconcile($source, $fields['specifications'], ProductInputContext::forDraft($draft));
        $pending = ProductPhotoKeyboardContext::pendingSources($updated);
        if ($pending !== []) {
            // Pixel review may cover more frames than the final album retained.
            $actualUrls = $staged !== null
                ? array_column(array_column($staged, 'attributes'), 'source_url')
                : $draft->media()->pluck('source_url')->all();
            $pending = array_values(array_filter($pending, fn (string $url): bool => $url === '*' || in_array($url, $actualUrls, true)));
            $updated['_photo_keyboard_reconciliation']['pending_source_urls'] = $pending;
            if ($pending === []) {
                $updated['_photo_keyboard_reconciliation']['status'] = 'not_compared';
            }
        }
        if ($updated === $source) {
            return [];
        }
        $attributes = fn (array $media): array => [
            'photo_source_context' => $updated,
            ...(in_array($media['verification_status'] ?? null, ['verified', 'source_verified'], true)
                && (in_array('*', $pending, true) || in_array($media['source_url'] ?? null, $pending, true))
                    ? ['verification_status' => 'pending'] : []),
        ];
        if ($staged !== null) {
            foreach ($staged as &$item) {
                $item['attributes'] = [...$item['attributes'], ...$attributes($item['attributes'])];
            }
            unset($item);
        } else {
            foreach ($draft->media()->get() as $media) {
                $media->update($attributes($media->getAttributes()));
            }
        }

        return ['photo_source_context' => $updated, ...($pending !== [] ? [
            'gallery_status' => 'partial', 'gallery_search_stop_reason' => 'photo_verification_pending',
        ] : [])];
    }

    private function verifyConfirmedGallerySafely(
        string $productPageUrl,
        Collection $candidates,
        ProductDraft $draft,
        int $minimumImages,
        ?int $telegramUpdateId,
    ): array {
        try {
            return $this->confirmedGalleryVerifier->verify(
                $productPageUrl,
                $candidates,
                $draft,
                $minimumImages,
                $telegramUpdateId,
            );
        } catch (ProductSearchCancelled $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);

            return ['mode' => ConfirmedProductGalleryVerifier::MODE_AMBIGUOUS, 'candidates' => collect(), 'cached' => false];
        }
    }

    private function minimumVerifiedImages(ProductDraft $draft): int
    {
        $slug = trim((string) $draft->category);

        if ($slug === '') {
            return 3;
        }

        return Category::query()->where('slug', $slug)->first()?->minimumVerifiedImages() ?? 3;
    }

    private function requiredImageWidth(ProductDraft $draft): int
    {
        $slug = trim((string) $draft->category);
        $override = $slug === '' ? null : Category::query()->where('slug', $slug)->first()?->minimumImageWidth();

        return $override ?? $this->settings->imageMinimumWidth();
    }

    private function requiredImageHeight(ProductDraft $draft): int
    {
        $slug = trim((string) $draft->category);
        $override = $slug === '' ? null : Category::query()->where('slug', $slug)->first()?->minimumImageHeight();

        return $override ?? $this->settings->imageMinimumHeight();
    }

    /** @param null|callable(string): void $progress */
    public function continueStage(ProductDraft $draft, ?callable $progress = null, ?int $telegramUpdateId = null): int
    {
        // Only URLs with a terminal end-to-end outcome are excluded. A
        // successful preflight, gallery-training round, source resolution,
        // completed download without final verification, or interrupted
        // Vision call is a checkpoint rather than proof that the source was
        // exhausted, so continuation is allowed to retry it.
        $attemptedUrls = $this->terminalSourceUrlsForContinuation($draft);
        $currentSource = $this->currentDraftSource($draft);
        $cycleSources = $currentSource
            ? [[...$currentSource, 'type' => 'retailer']]
            : [];

        return $this->stage(
            $draft,
            $progress,
            $telegramUpdateId,
            $cycleSources,
            $attemptedUrls,
        );
    }

    /** @return array<int, string> */
    private function terminalSourceUrlsForContinuation(ProductDraft $draft): array
    {
        $terminalVerificationActions = [
            'verify_gallery',
            'verify_deferred_source',
            'verify_discovered_gallery',
            'verify_discovered_candidates',
        ];

        return ProductSourceAttempt::query()
            ->where('product_draft_id', $draft->id)
            ->where(function ($query) use ($terminalVerificationActions): void {
                $query
                    ->where(function ($preflight): void {
                        $preflight->where('phase', 'gallery_preflight')
                            ->where('status', 'failed');
                    })
                    ->orWhere(function ($selection): void {
                        $selection->where('phase', 'source_selection')
                            ->where('status', 'failed');
                    })
                    ->orWhere(function ($download): void {
                        $download->where('phase', 'image_download')
                            ->where('status', 'failed');
                    })
                    ->orWhere(function ($fallbackDownload): void {
                        $fallbackDownload->where('phase', 'fallback_image_download')
                            ->where('status', 'failed');
                    })
                    ->orWhere(function ($verification) use ($terminalVerificationActions): void {
                        $verification->where('phase', 'image_verification')
                            ->whereIn('action', $terminalVerificationActions)
                            ->whereIn('status', ['completed', 'failed']);
                    });
            })
            ->orderBy('id')
            ->pluck('product_url')
            ->filter(fn (mixed $url): bool => is_string($url) && filter_var($url, FILTER_VALIDATE_URL) !== false)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Replace one staged photo without touching the rest of the draft gallery.
     *
     * @param  null|callable(string): void  $progress
     */
    public function replaceDraftMedia(ProductDraft $draft, ProductDraftMedia $media, ?callable $progress = null, ?int $telegramUpdateId = null, ?callable $beforeSave = null): ProductDraftMedia
    {
        throw_unless(
            $draft->status === 'pending_review' && $media->product_draft_id === $draft->id,
            \RuntimeException::class,
            'Draft photo is no longer available.',
        );

        $telegramUpdateId ??= $draft->telegram_update_id;
        $existingMedia = $draft->media()->get();
        $existingUrls = $this->cleanUrls($existingMedia->pluck('source_url')->all());
        $excludedHashes = $this->perceptualHashesForMedia($existingMedia);
        $currentSource = $this->currentDraftSource($draft);
        $sources = $currentSource ? [$currentSource] : [];
        $selected = [];

        foreach ($sources as $sourceIndex => $source) {
            if (
                ! is_array($source)
                || ! is_string($source['url'] ?? null)
                || $this->sourceExcludedForDraft($source['url'], $draft)
            ) {
                continue;
            }

            if ($progress) {
                $progress('Источник '.($sourceIndex + 1).': '.(parse_url($source['url'], PHP_URL_HOST) ?: $source['url']));
            }
            // Replacing one photo is a targeted, cheap correction, not a full
            // gallery search - training/reapplying a whole Playwright recipe
            // for a single image is disproportionate, so this stays static-
            // HTML-only. A genuinely gallery-wide problem has its own,
            // heavier "Все фото" action (full restage) for that.
            $resolvedUrls = $this->resolver->resolve(
                [$source],
                10,
                $progress ? fn (string $level, string $message) => $progress($message) : null,
                $telegramUpdateId,
                staticOnly: true,
            );
            if ($this->resolvedSourceConflicts($draft, $source['url'], $telegramUpdateId, $progress)) {
                continue;
            }
            $urls = array_values(array_diff($this->cleanUrls([
                ...($source['image_urls'] ?? []),
                ...$resolvedUrls,
            ]), $existingUrls));
            $candidates = $this->downloadCandidates($urls, $draft);

            if ($candidates === []) {
                continue;
            }

            $verified = $this->selectFromCandidatesSafely($draft, $candidates, 1, $telegramUpdateId, $progress);
            $selected = $this->removeNearDuplicates($verified, $excludedHashes);
            $this->destroyUnselected($candidates, $selected);

            if ($selected !== []) {
                break;
            }
        }

        if ($selected === []) {
            if ($progress) {
                $progress('Страница источника не дала новых фото; ищу по модели и бренду через AI веб-поиск.');
            }
            // The static scrape of the known source page(s) just failed - a
            // second static pass of the same page(s) would almost certainly
            // repeat that same failure. discoverCandidates() (skipping known
            // sources) runs the same AI web-search-by-model used for full
            // gallery discovery/top-up, then the identical vision check below
            // still guards against a wrong model/color/duplicate getting in.
            [$candidates] = $this->discoverCandidates($draft, $existingUrls, true, $progress, $telegramUpdateId);
            $verified = $this->selectFromCandidatesSafely($draft, $candidates, 1, $telegramUpdateId, $progress);
            $selected = $this->removeNearDuplicates($verified, $excludedHashes);
            $this->destroyUnselected($candidates, $selected);
        }

        throw_if($selected === [], \RuntimeException::class, 'Не найдено новое непохожее фото той же модели и цвета.');
        $candidate = $selected[0];
        $newPath = null;

        try {
            if ($beforeSave) {
                $beforeSave();
            }
            $converted = $this->encoder->toWebp($candidate['image']);
            $checksum = hash('sha256', $converted['bytes']);
            $newPath = "drafts/{$draft->id}/replacement-{$media->id}-".substr($checksum, 0, 12).'.webp';
            throw_unless(
                Storage::disk('public')->put($newPath, $converted['bytes']),
                \RuntimeException::class,
                'Could not store replacement draft photo.',
            );
            $oldDisk = $media->disk;
            $oldPath = $media->path;
            $media->update([
                'disk' => 'public',
                'path' => $newPath,
                'source_url' => $candidate['source_url'],
                'photo_source_context' => $candidate['page_source_context'] ?? null,
                'mime_type' => 'image/webp',
                'width' => $converted['width'],
                'height' => $converted['height'],
                'file_size' => strlen($converted['bytes']),
                'checksum' => $checksum,
                'verification_status' => 'verified',
                'verification_score' => isset($candidate['vision_score']) ? $candidate['vision_score'] / 100 : null,
                'verification_model' => $candidate['vision_model'] ?? null,
                'verification_notes' => $candidate['vision_reason'] ?? null,
            ]);

            if ($oldDisk && $oldPath && ($oldDisk !== 'public' || $oldPath !== $newPath)) {
                Storage::disk($oldDisk)->delete($oldPath);
            }

            return $media->fresh();
        } catch (Throwable $exception) {
            if ($newPath && $media->path !== $newPath) {
                Storage::disk('public')->delete($newPath);
            }

            throw $exception;
        } finally {
            foreach ($selected as $item) {
                if (($item['image'] ?? null) instanceof GdImage) {
                    imagedestroy($item['image']);
                }
            }
        }
    }

    /**
     * Permanently rejects the currently staged gallery for this draft.
     * Both URLs and perceptual hashes are kept so the same photo cannot return
     * from another CDN size or another product page.
     */
    public function excludeCurrentDraftGallery(ProductDraft $draft): void
    {
        $media = $draft->media()->get();
        $imageUrls = $this->cleanUrls([
            ...($draft->excluded_gallery_image_urls ?? []),
            ...$media->pluck('source_url')->all(),
        ]);
        $sourceUrls = $this->cleanUrls([
            ...($draft->excluded_gallery_source_urls ?? []),
            $draft->primary_source_url,
            ...$media->pluck('source_url')->all(),
            ...ProductSourceAttempt::query()
                ->where('product_draft_id', $draft->id)
                ->pluck('product_url')
                ->all(),
        ]);
        $hashes = array_values(array_unique([
            ...array_filter($draft->excluded_gallery_hashes ?? [], 'is_string'),
            ...$this->perceptualHashesForMedia($media),
        ]));

        $draft->update([
            'excluded_gallery_source_urls' => $sourceUrls,
            'excluded_gallery_image_urls' => $imageUrls,
            'excluded_gallery_hashes' => $hashes,
        ]);
    }

    /**
     * Append additional distinct photos to a still-pending draft without
     * touching what's already staged - used when the initial search only
     * landed one or two images and the operator wants the gallery topped
     * up to its normal target instead of redoing the whole search.
     *
     * @param  null|callable(string): void  $progress
     */
    public function topUpDraftMedia(ProductDraft $draft, ?callable $progress = null, ?int $telegramUpdateId = null): int
    {
        throw_unless($draft->status === 'pending_review', \RuntimeException::class, 'Draft is no longer pending review.');

        $telegramUpdateId ??= $draft->telegram_update_id;
        $existingMedia = $draft->media()->get();
        $existing = $existingMedia->count();
        $remaining = $this->targetDraftImageCount($draft) - $existing;

        if ($remaining <= 0) {
            return 0;
        }

        $existingUrls = $this->cleanUrls($existingMedia->pluck('source_url')->all());
        $excludedHashes = $this->perceptualHashesForMedia($existingMedia);
        $currentSource = $this->currentDraftSource($draft);
        $sources = $currentSource ? [$currentSource] : [];
        $activeRecipeOnly = $this->gallerySearchStrategy($draft) === Category::GALLERY_SEARCH_VISION_FIRST;
        $selected = [];

        foreach ($sources as $sourceIndex => $source) {
            if (count($selected) >= $remaining) {
                break;
            }

            if (
                ! is_array($source)
                || ! is_string($source['url'] ?? null)
                || $this->sourceExcludedForDraft($source['url'], $draft)
            ) {
                continue;
            }

            if ($progress) {
                $progress('Источник '.($sourceIndex + 1).': '.(parse_url($source['url'], PHP_URL_HOST) ?: $source['url']));
            }

            $needed = $remaining - count($selected);
            $knownUrls = array_values(array_unique([...$existingUrls, ...collect($selected)->pluck('source_url')->all()]));
            $attemptCheckpoint = ProductSourceAttempt::query()->max('id') ?? 0;
            try {
                $resolvedUrls = $this->resolver->resolve(
                    [$this->gallerySourceContext->forDraft($draft, $source)],
                    max(4, $needed * 2),
                    $progress ? fn (string $level, string $message) => $progress($message) : null,
                    $telegramUpdateId,
                    activeRecipeOnly: $activeRecipeOnly,
                );
            } finally {
                $this->attempts->associateWithDraftSince($draft, $telegramUpdateId, $attemptCheckpoint);
            }
            if ($this->resolvedSourceConflicts($draft, $source['url'], $telegramUpdateId, $progress)) {
                continue;
            }
            $urls = array_values(array_diff($this->cleanUrls([
                ...($source['image_urls'] ?? []),
                ...$resolvedUrls,
            ]), $knownUrls));
            $candidates = $this->downloadCandidates($urls, $draft);

            if ($candidates === []) {
                continue;
            }

            $verified = $this->selectFromCandidatesSafely($draft, $candidates, $needed, $telegramUpdateId, $progress);
            $newlySelected = $this->removeNearDuplicates($verified, [
                ...$excludedHashes,
                ...$this->perceptualHashesForCandidates($selected),
            ]);
            $this->destroyUnselected($candidates, $newlySelected);
            $selected = [...$selected, ...$newlySelected];
        }

        // Top-up keeps the chosen gallery. Reopening it here used to repeat the
        // same training after a terminal result, under a misleading "new search"
        // message. Choosing a different gallery belongs to the replacement flow.

        $roles = ['primary', 'secondary', 'detail'];
        $stored = 0;

        foreach ($selected as $candidate) {
            $path = null;

            try {
                $converted = $this->encoder->toWebp($candidate['image']);
                $encoded = $converted['bytes'];
                $checksum = hash('sha256', $encoded);
                $role = $roles[$existing + $stored] ?? 'detail';
                $path = "drafts/{$draft->id}/{$role}-".substr($checksum, 0, 12).'.webp';

                if (! Storage::disk('public')->put($path, $encoded)) {
                    throw new \RuntimeException("Could not write staged product image: {$path}");
                }

                $draft->media()->create([
                    'disk' => 'public',
                    'path' => $path,
                    'source_url' => $candidate['source_url'],
                    'photo_source_context' => $candidate['page_source_context'] ?? null,
                    'role' => $role,
                    'mime_type' => 'image/webp',
                    'width' => $converted['width'],
                    'height' => $converted['height'],
                    'file_size' => strlen($encoded),
                    'checksum' => $checksum,
                    'verification_status' => $candidate['verification_status'] ?? 'verified',
                    'verification_score' => isset($candidate['vision_score'])
                        ? $candidate['vision_score'] / 100
                        : ($candidate['verification_score'] ?? null),
                    'verification_model' => $candidate['vision_model'] ?? $candidate['verification_model'] ?? null,
                    'verification_notes' => $candidate['vision_reason'] ?? $candidate['verification_notes'] ?? null,
                    'sort_order' => $existing + $stored,
                    'is_primary' => false,
                ]);
                $stored++;
            } catch (Throwable $exception) {
                if ($path) {
                    Storage::disk('public')->delete($path);
                }

                report($exception);
            } finally {
                imagedestroy($candidate['image']);
            }
        }

        return $stored;
    }

    public function adoptStaged(Product $product, ProductVariant $variant, ProductDraft $draft): int
    {
        $staged = $draft->media()->get();
        $existing = $product->media()->where('type', 'image')->count();
        $stored = 0;

        foreach ($staged as $media) {
            $path = null;

            try {
                if (! $media->disk || ! $media->path || ! Storage::disk($media->disk)->exists($media->path)) {
                    continue;
                }

                if ($product->media()->where('checksum', $media->checksum)->exists()) {
                    $media->delete();

                    continue;
                }

                $encoded = Storage::disk($media->disk)->get($media->path);
                $role = $media->role ?: ($stored === 0 ? 'primary' : 'detail');
                $path = "products/{$product->id}/{$role}-".substr($media->checksum, 0, 12).'.webp';

                if (! Storage::disk('public')->put($path, $encoded)) {
                    throw new \RuntimeException("Could not adopt staged product image: {$path}");
                }

                $product->media()->create([
                    'product_variant_id' => $variant->id,
                    'type' => 'image',
                    'disk' => 'public',
                    'path' => $path,
                    'role' => $role,
                    'url' => '/storage/'.str_replace('\\', '/', $path),
                    'source_url' => $media->source_url,
                    'photo_source_context' => $media->photo_source_context,
                    'alt' => $product->title,
                    'mime_type' => $media->mime_type,
                    'width' => $media->width,
                    'height' => $media->height,
                    'file_size' => $media->file_size,
                    'checksum' => $media->checksum,
                    'verification_status' => $media->verification_status,
                    'verification_score' => $media->verification_score,
                    'verification_model' => $media->verification_model,
                    'verification_notes' => $media->verification_notes,
                    'verified_at' => now(),
                    'sort_order' => $existing + $stored,
                    'is_primary' => $existing === 0 && $stored === 0,
                ]);
                $stored++;
                $media->delete();
            } catch (Throwable $exception) {
                if ($path) {
                    Storage::disk('public')->delete($path);
                }

                report($exception);
            }
        }

        return $stored;
    }

    /**
     * Whether a source's identity is confirmed enough to grant wholesale
     * gallery trust (skip per-frame Vision entirely).
     *
     * A literal match on the exact settled value (model, or a dedicated sku/
     * mpn/ean/upc/gtin specification) is confirmed for free - no AI call.
     * Anything weaker - a shared chassis/family code a whole line of
     * different SKUs has in common, or no textual match at all - is not
     * evidence the wrong configuration was found, it is evidence the cheap
     * check could not tell. ProductSourceIdentityJudge is asked to look at
     * the same evidence against what the operator actually asked for (not
     * only what research settled on), so this works identically whether or
     * not the operator ever typed a SKU themselves. A conflicting verdict
     * rejects; every other outcome (including a technical failure, which
     * ProductSourceIdentityJudge itself already degrades to 'uncertain')
     * proceeds without the wholesale-trust privilege rather than being
     * treated as proof of a mismatch.
     *
     * @param  array<string, mixed>  $source
     */
    private function photoConflictContext(ProductDraft $draft, array $source, array $conflict, ?int $updateId): array
    {
        $source['_photo_applicability'] = ProductPhotoApplicability::bind($draft, $source, [
            ...($source['_photo_applicability'] ?? []), 'verdict' => 'conflicting',
            'may_inspect_gallery' => false, 'reason' => $conflict['reason'],
            'conflict_evidence' => $conflict,
        ]);
        $this->recordPhotoAssessment($draft, $source, $source['_photo_applicability'], $updateId);

        return $source;
    }

    private function recordPhotoAssessment(ProductDraft $draft, array $source, array $assessment, ?int $updateId): void
    {
        $this->attempts->record([
            'telegram_update_id' => $updateId, 'product_draft_id' => $draft->id,
            'product_url' => $source['url'] ?? '', 'actor' => 'identity_validator',
            'phase' => 'source_selection', 'action' => 'assess_photo_applicability',
            'status' => in_array($assessment['verdict'] ?? null, ['exact', 'compatible'], true) ? 'completed' : 'interrupted',
            'decision' => $assessment['verdict'] ?? 'unavailable',
            'message' => $assessment['reason'] ?? null, 'output' => $assessment,
        ]);
    }

    private function identityConfirmation(ProductDraft $draft, array $source, ?int $telegramUpdateId): string
    {
        if ($this->identityMatcher->confirmsExactIdentifier($draft, $source)) {
            return 'confirmed';
        }

        return $this->identityJudge->judge($draft, $source, $telegramUpdateId);
    }

    private function sourceExcludedForDraft(string $url, ProductDraft $draft): bool
    {
        return $this->sourceQueue->sourceExcludedForDraft($url, $draft);
    }

    /** @return array<string, mixed>|null */
    private function currentDraftSource(ProductDraft $draft): ?array
    {
        $primaryUrl = is_string($draft->primary_source_url) ? trim($draft->primary_source_url) : '';

        if ($primaryUrl === '') {
            return null;
        }

        $source = collect($draft->sources ?? [])->first(
            fn (mixed $source): bool => is_array($source)
                && is_string($source['url'] ?? null)
                && rtrim($source['url'], '/') === rtrim($primaryUrl, '/'),
        );

        if (is_array($source)) {
            return $source;
        }

        return [
            'url' => $primaryUrl,
            'type' => 'web',
            'image_urls' => $draft->image_urls ?? [],
        ];
    }

    /** A zero-photo browser result must not revive seeds from a conflicting page. */
    private function resolvedSourceConflicts(ProductDraft $draft, string $url, ?int $runId, ?callable $progress): bool
    {
        $context = $this->resolver->sourceContextForPage($url);
        if ($context === null || app(ProductSourceIdentityGate::class)->assess($draft, $context, $runId) !== 'conflicting') {
            return false;
        }
        $this->attempts->record([
            'telegram_update_id' => $runId, 'product_draft_id' => $draft->id,
            'product_url' => $context['url'] ?? $url, 'actor' => 'identity_validator',
            'phase' => 'source_selection', 'action' => 'validate_resolved_source_before_seed_merge',
            'status' => 'failed', 'decision' => 'reject_runtime_identifier_mismatch',
            'input' => ['url' => $url], 'output' => $context,
        ]);
        $progress?->__invoke('Источник открыл другую комплектацию; старые ссылки на его фото не использую.');

        return true;
    }

    /**
     * The page a text card is assembled against: the one research chose, or
     * the next page research found when this run saw the chosen one selling
     * another product (request #94 left the operator no card at all).
     */
    private function textCardSourceUrl(ProductDraft $draft, ?int $runId): string
    {
        return (string) collect([$draft->primary_source_url, ...collect($draft->sources ?? [])->pluck('url')->all()])
            ->filter(fn (mixed $url): bool => is_string($url) && trim($url) !== '')
            ->map(fn (string $url): string => trim($url))
            ->unique()
            ->first(fn (string $url): bool => ! $this->rejectedAsDifferentProduct($draft, $url, $runId), '');
    }

    /** Whether this run already saw the page (as asked for or as landed on) selling another product. */
    private function rejectedAsDifferentProduct(ProductDraft $draft, string $url, ?int $runId): bool
    {
        $url = rtrim($url, '/');

        return ProductSourceAttempt::query()
            ->where('product_draft_id', $draft->id)
            ->where('telegram_update_id', $runId)
            ->where('decision', 'reject_runtime_identifier_mismatch')
            ->get(['product_url', 'input'])
            ->contains(fn (ProductSourceAttempt $attempt): bool => in_array($url, [
                rtrim((string) $attempt->product_url, '/'),
                rtrim((string) ($attempt->input['url'] ?? ''), '/'),
            ], true));
    }

    private function knownShopsFirst(Collection $sources): Collection
    {
        return $this->sourceQueue->knownShopsFirst($sources);
    }

    private function limitPerHost(Collection $sources, ?callable $progress): Collection
    {
        return $this->sourceQueue->limitPerHost($sources, $progress);
    }

    private function cleanUrls(array $urls, ?string $variantHint = null): array
    {
        return $this->candidateUrls->cleanUrls($urls, $variantHint);
    }

    /**
     * Populated by downloadCandidates() with why each dropped URL never
     * became a candidate - a side channel rather than a return-value change,
     * since most of downloadCandidates()'s several callers only need the
     * survivors and only stage()'s attempt-recording currently reads this.
     *
     * @var array<int, array{url: string, reason: string}>
     */
    private array $lastDownloadRejections = [];

    /** @var array<int, string> Domains degraded this call by degradeRecipesAfterTechnicalFailure(). */
    private array $lastDegradedDomains = [];

    private function downscaleForWork(GdImage $image): GdImage
    {
        return $this->imageGeometry->downscaleForWork($image);
    }

    private function downloadCandidates(array $urls, ProductDraft $draft): array
    {
        $candidates = [];
        $checksums = [];
        $limit = (int) config('product-images.download_candidates', 8);
        $sourcePagesByUrl = [];
        $sourceContextsByUrl = [];
        $this->lastDownloadRejections = [];
        // Each request below already has its own connect/read timeout, but a
        // long run of slow-yet-not-instantly-failing URLs from one source can
        // still add up past any reasonable budget with no single failure to
        // point to - observed live on 2026-09-01 (Dell XPS 13 search): a
        // worker held this loop open for 9+ minutes downloading one source's
        // confirmed frames. This bounds the whole loop, not one request.
        $downloadDeadline = microtime(true) + (float) config('product-images.http.download_wall_clock_cap_seconds', 90);

        foreach ($urls as $originalUrl) {
            if (! is_string($originalUrl)) {
                continue;
            }

            $key = self::normalizeCandidateUrl($originalUrl);
            $context = $this->candidateDiscovery->sourceContextForImage($originalUrl)
                ?? $this->resolver->sourceContextForImage($originalUrl);
            $pageUrl = is_array($context) && is_string($context['url'] ?? null)
                ? $context['url']
                : $this->candidateDiscovery->sourcePageForImage($originalUrl);

            if ($pageUrl) {
                $sourcePagesByUrl[$key] = $pageUrl;
            }
            if (is_array($context)) {
                $sourceContextsByUrl[$key] = $context;
            }
        }

        $urls = array_values(array_diff(
            $this->cleanUrls($urls),
            $this->cleanUrls($draft->excluded_gallery_image_urls ?? []),
        ));
        $urls = $this->sourcePriority->sortUrls($urls, $draft->brand, $draft->sources ?? [], $sourcePagesByUrl);
        // A complete gallery returned by a freshly trained/cached recipe must
        // not be pushed past download_candidates by loose static URLs from an
        // earlier, historically stronger domain. Preserve source-priority
        // order inside each confidence tier, but always download confirmed
        // (then partial) Playwright frames first.
        $rankedUrls = collect($urls)
            ->values()
            ->map(fn (string $url, int $index): array => [
                'url' => $url,
                'index' => $index,
                'gallery_rank' => $this->resolver->isConfirmedGalleryImage($url)
                    ? 2
                    : ($this->resolver->isPartialGalleryImage($url) ? 1 : 0),
            ]);
        $urls = $rankedUrls
            ->sort(fn (array $left, array $right): int => ($right['gallery_rank'] <=> $left['gallery_rank'])
                ?: ($left['index'] <=> $right['index'])
            )
            ->pluck('url')
            ->all();

        // download_candidates bounds unverified scraping work; it must never
        // truncate a gallery that Playwright has already validated as one
        // atomic slider. This was observable on B&H: training published 11
        // verified frames, the generic limit downloaded only 10, and the
        // category minimum of 10 was then missed after downstream dedupe.
        $confirmedUrlCount = collect($urls)
            ->filter(fn (string $url): bool => $this->resolver->isConfirmedGalleryImage($url))
            ->count();
        $effectiveLimit = max($limit, $confirmedUrlCount);

        $confirmedDownloads = 0;
        $confirmedStopThreshold = $this->minimumVerifiedImages($draft);
        // Every accepted candidate keeps its decoded GdImage alive for the rest
        // of the loop, and a decoded truecolor bitmap costs about four bytes per
        // pixel - so isSafeToDecode()'s 20MP per-image ceiling is ~80MB each.
        // That was survivable while the loop stopped at download_candidates (10),
        // but a confirmed Playwright gallery deliberately lifts that cap to keep
        // the slider whole, and an 18-frame gallery then decoded 18 bitmaps at
        // once and killed the worker outright (observed live 2026-09-02: memory
        // exhausted at imagecreatefromstring, whole search lost). Budgeting the
        // retained pixels turns that into a partial gallery instead.
        $decodedPixelBudget = max(1_000_000, (int) config('product-images.decoded_pixel_budget', 60_000_000));
        $decodedPixels = 0;

        foreach ($urls as $url) {
            if (count($candidates) >= $effectiveLimit) {
                break;
            }

            if (
                ! $this->resolver->isConfirmedGalleryImage($url)
                && $confirmedDownloads >= $confirmedStopThreshold
            ) {
                break;
            }

            if (microtime(true) >= $downloadDeadline) {
                $this->lastDownloadRejections[] = ['url' => $url, 'reason' => 'download_wall_clock_budget_exceeded'];

                break;
            }

            $failureReason = null;
            $refererUrl = $sourcePagesByUrl[$url] ?? null;
            $download = $this->resolver->download($url, failureReason: $failureReason, refererUrl: $refererUrl);

            if (! $download) {
                $this->lastDownloadRejections[] = ['url' => $url, 'reason' => $failureReason ?? 'unknown'];

                continue;
            }

            $requiredWidth = $this->requiredImageWidth($draft);
            $requiredHeight = $this->requiredImageHeight($draft);

            $dimensionRejection = $this->dimensionRejectionReason(
                $download['width'],
                $download['height'],
                $requiredWidth,
                $requiredHeight,
                allowExtremeAspectRatio: $this->resolver->isConfirmedGalleryImage($url),
            );

            if ($dimensionRejection !== null) {
                $this->lastDownloadRejections[] = [
                    'url' => $url,
                    'reason' => $dimensionRejection,
                ];

                continue;
            }

            if (! $this->encoder->isSafeToDecode($download['width'], $download['height'])) {
                Log::warning('Product image candidate skipped: too large to safely decode.', [
                    'source_url' => $download['source_url'],
                    'width' => $download['width'],
                    'height' => $download['height'],
                ]);
                $this->lastDownloadRejections[] = ['url' => $url, 'reason' => 'unsafe_to_decode'];

                continue;
            }

            $checksum = hash('sha256', $download['bytes']);

            if (isset($checksums[$checksum])) {
                $this->lastDownloadRejections[] = ['url' => $url, 'reason' => 'duplicate_of_another_candidate'];

                continue;
            }

            $imagePixels = max(1, $download['width'] * $download['height']);

            // The first candidate is always attempted - isSafeToDecode() above
            // already bounds a single image, and returning nothing at all would
            // be worse than one big frame. Every later one has to fit the budget
            // alongside what is already being held.
            if ($decodedPixels > 0 && $decodedPixels + $imagePixels > $decodedPixelBudget) {
                $this->lastDownloadRejections[] = ['url' => $url, 'reason' => 'decoded_memory_budget_exceeded'];

                break;
            }

            $image = @imagecreatefromstring($download['bytes']);

            if (! $image instanceof GdImage) {
                $this->lastDownloadRejections[] = ['url' => $url, 'reason' => 'gd_decode_failed'];

                continue;
            }

            // Held at the size it will be stored at, not at the size it arrived.
            //
            // The encoder scales everything past 1600px on the way to disk
            // anyway, so a 5000x5000 photograph spent 100 MB of the memory
            // budget to become a 1600x1600 one. Live on dell.com: a recipe
            // collected thirteen frames, the budget ran out on the fourth, and
            // twenty-eight candidates were never even tried - the gallery was
            // truncated by memory, not by anything about the photographs.
            //
            // The measured width and height in $download are untouched, so the
            // category's minimum-size rule still judges the original.
            $image = $this->downscaleForWork($image);
            $decodedPixels += imagesx($image) * imagesy($image);

            // Exact tier only, same as ProductImageVisionVerifier's own local
            // fallback - a shared family/chassis fragment is a plausible
            // match, not a confirmed one (see ProductIdentityMatcher::
            // confirmsExactIdentifier()'s docblock). This flag survives
            // unmodified into Vision's source_supported OR-gate whenever the
            // caller never overwrites it with its own judge-backed decision
            // (topUpDraftMedia() and replaceDraftMedia() do not, unlike
            // stage()'s main per-source loop) - real production case
            // (2026-09-10): a page naming only "LOQ 15IRX10", never the
            // draft's own dedicated SKU, used to let every photo through
            // topUpDraftMedia() on this alone even after Vision itself found
            // no exact match.
            $pageContext = $sourceContextsByUrl[$url] ?? null;
            $pageIdentityConfirmed = is_array($pageContext) && (
                ProductPhotoApplicability::isAccepted($draft, $pageContext, $pageContext['_photo_applicability'] ?? [])
                || (! $this->identityMatcher->conflictsSource($draft, $pageContext)
                    && $this->identityMatcher->confirmsExactIdentifier($draft, $pageContext))
            );
            $candidates[] = [
                ...$download,
                'page_source_url' => $sourcePagesByUrl[$url]
                    ?? $this->candidateDiscovery->sourcePageForImage($url),
                'page_source_context' => $pageContext,
                'source_identity_confirmed' => $pageIdentityConfirmed,
                'image' => $image,
            ];
            if ((bool) ($download['confirmed_gallery'] ?? false)) {
                $confirmedDownloads++;
            }
            $checksums[$checksum] = true;
        }

        $this->degradeRecipesAfterTechnicalFailure($urls, $candidates, $sourcePagesByUrl);

        $unique = $this->renditions->unique($candidates);
        $keptIds = array_map(fn (array $candidate): int => spl_object_id($candidate['image']), $unique);
        foreach ($candidates as $candidate) {
            if (! in_array(spl_object_id($candidate['image']), $keptIds, true)) {
                $this->lastDownloadRejections[] = ['url' => $candidate['source_url'], 'reason' => 'duplicate_rendition'];
            }
        }
        $this->destroyUnselected($candidates, $unique);

        return $unique;
    }

    /** @return array<string, int> */
    private function downloadRejectionSummary(): array
    {
        return collect($this->lastDownloadRejections)
            ->map(fn (array $rejection): string => Str::before(
                (string) ($rejection['reason'] ?? 'unknown'),
                ' (',
            ))
            ->countBy()
            ->all();
    }

    /**
     * A recipe is marked "active" once its own in-browser probe loads the
     * candidate images successfully (see ProductGalleryRecipeTrainer) - but
     * that probe runs inside a live Playwright session, while this download
     * happens later over a plain HTTP request with no session/cookies. A CDN
     * that gates images by referrer/session can pass the former and 404 the
     * latter, and nothing before this ever reported that mismatch back to
     * the recipe, so it stayed "active" and would fail the exact same way on
     * every future draft for this domain. Only a genuine technical failure
     * (network/decode) counts - a quality rejection (too_small/
     * bad_aspect_ratio/unsafe_to_decode) says nothing about whether the
     * recipe's URLs are actually reachable. The threshold/cap that decides
     * what to do about a repeated failure lives in ProductGalleryRecipeTrainer,
     * next to the equivalent bookkeeping for training-time failures.
     *
     * @param  array<int, string>  $urls
     * @param  array<int, array<string, mixed>>  $candidates
     * @param  array<string, string>  $sourcePagesByUrl
     */
    private function degradeRecipesAfterTechnicalFailure(array $urls, array $candidates, array $sourcePagesByUrl): void
    {
        $confirmedUrls = collect($urls)->filter(fn (string $url): bool => $this->resolver->isConfirmedGalleryImage($url));

        if ($confirmedUrls->isEmpty()) {
            return;
        }

        // ProductGalleryRecipe.domain is always stored lowercase (see
        // ProductGalleryRecipeTrainer::train()'s own strtolower(parse_url())
        // - www is kept, unlike ProductSourcePriority::host()) - matching
        // that exact convention here, not a different one, is what makes
        // this lookup actually find the recipe instead of silently no-op.
        $sourcePagesFor = fn (Collection $urls): Collection => $urls
            ->map(fn (string $url): string => $sourcePagesByUrl[$url] ?? $url)
            ->filter(fn (string $url): bool => filter_var($url, FILTER_VALIDATE_URL) !== false)
            ->unique();

        $confirmedStored = collect($candidates)->contains(fn (array $candidate): bool => (bool) ($candidate['confirmed_gallery'] ?? false));

        if ($confirmedStored) {
            $sourcePagesFor($confirmedUrls)->each(fn (string $sourceUrl) => $this->recipeTrainer->recordConfirmedDownloadSuccess($sourceUrl));

            return;
        }

        $technicallyFailed = collect($this->lastDownloadRejections)->contains(
            fn (array $rejection): bool => $confirmedUrls->contains($rejection['url'])
                && ! str_starts_with($rejection['reason'], 'too_small')
                && ! str_starts_with($rejection['reason'], 'bad_aspect_ratio')
                && $rejection['reason'] !== 'unsafe_to_decode'
        );

        if (! $technicallyFailed) {
            return;
        }

        $sourcePagesFor($confirmedUrls)->each(function (string $sourceUrl): void {
            if ($this->recipeTrainer->recordConfirmedDownloadFailure($sourceUrl)) {
                $this->lastDegradedDomains[] = strtolower((string) parse_url($sourceUrl, PHP_URL_HOST));
            }
        });
    }

    /** @param array<int, string> $existingUrls @return array{array<int, array<string, mixed>>, bool} */
    private function discoverCandidates(
        ProductDraft $draft,
        array $existingUrls,
        bool $skipKnownSources = false,
        ?callable $progress = null,
        ?int $telegramUpdateId = null,
        array $additionalExcludedSourceUrls = [],
        int $searchAttempt = 0,
    ): array {
        $attemptCheckpoint = ProductSourceAttempt::query()->max('id') ?? 0;

        try {
            $discovered = ($skipKnownSources || $progress)
                ? $this->candidateDiscovery->find(
                    $draft,
                    $existingUrls,
                    $skipKnownSources,
                    $progress,
                    $telegramUpdateId,
                    $additionalExcludedSourceUrls,
                    $searchAttempt,
                )
                : $this->candidateDiscovery->find(
                    $draft,
                    $existingUrls,
                    telegramUpdateId: $telegramUpdateId,
                    additionalExcludedSourceUrls: $additionalExcludedSourceUrls,
                    searchAttempt: $searchAttempt,
                );
        } finally {
            $this->associateAttemptsWithDraftSince($draft, $telegramUpdateId, $attemptCheckpoint);
        }
        // Keep exact URLs observed by the browser or page markup. Asset-key
        // canonicalization below is dedupe-only and must never become a
        // download target.
        $existingAssetKeys = collect($this->cleanUrls($existingUrls))
            ->map(fn (string $url): string => self::imageAssetKey($url))
            ->all();
        $newUrls = collect($discovered)
            ->filter(fn (mixed $url): bool => is_string($url) && filter_var($url, FILTER_VALIDATE_URL) !== false)
            ->reject(fn (string $url): bool => in_array(
                self::imageAssetKey(self::normalizeCandidateUrl($url)),
                $existingAssetKeys,
                true,
            ))
            ->unique(fn (string $url): string => self::imageAssetKey($url))
            ->values()
            ->all();
        // Download every logical product page independently. One wrong page
        // must not consume the global first-N download slots and starve a
        // later exact Playwright gallery that has already been found.
        $candidates = [];
        $downloadRejections = [];

        foreach ($this->groupDiscoveryUrlsBySource($newUrls) as $group) {
            $groupUrls = $group['urls'];
            $groupContext = $group['context'];
            $groupPageUrl = $group['page_url'];

            $groupAssessment = is_array($groupContext) && $groupPageUrl
                ? app(ProductSourceIdentityGate::class)->assessPhotos($draft, $groupContext, $telegramUpdateId)
                : ['verdict' => 'unavailable'];
            if (($groupAssessment['verdict'] ?? null) === 'conflicting'
                || (($groupAssessment['verdict'] ?? null) === 'uncertain' && ! ($groupAssessment['may_inspect_gallery'] ?? false))) {
                $this->recordPhotoAssessment($draft, $groupContext ?? [], $groupAssessment, $telegramUpdateId);
                $progress?->__invoke('Найденная страница пропущена: фотографии не подтверждены для выбранного товара · '.$groupPageUrl);

                continue;
            }
            if (is_array($groupContext)) {
                $groupContext['_photo_applicability'] = $groupAssessment;
            }
            $groupCandidates = array_map(fn (array $candidate): array => [
                ...$candidate, 'page_source_context' => $groupContext ?? ($candidate['page_source_context'] ?? null),
            ], $this->downloadCandidates($groupUrls, $draft));
            $downloadRejections = [...$downloadRejections, ...$this->lastDownloadRejections];
            $candidates = [...$candidates, ...$groupCandidates];
        }

        $this->lastDownloadRejections = $downloadRejections;

        if ($discovered !== []) {
            $draft->update(['image_urls' => array_values(array_unique([
                ...($draft->image_urls ?? []),
                ...$discovered,
            ]))]);
        }

        $this->attempts->record([
            'telegram_update_id' => $telegramUpdateId,
            'product_draft_id' => $draft->id,
            'product_url' => collect($newUrls)
                ->map(fn (string $url): ?string => $this->candidateDiscovery->sourcePageForImage($url))
                ->filter()
                ->first(),
            'actor' => 'downloader',
            'phase' => 'fallback_image_download',
            'action' => 'download_discovered_candidates',
            'status' => $candidates !== [] ? 'completed' : 'failed',
            'decision' => $candidates !== [] ? 'ready_for_selection' : 'continue_fallback_search',
            'input' => ['discovered_urls' => count($discovered), 'new_urls' => count($newUrls)],
            'output' => [
                'downloaded_images' => count($candidates),
                'rejected_candidates' => array_slice($this->lastDownloadRejections, 0, 20),
            ],
        ]);

        return [$candidates, true];
    }

    /**
     * Keep independent product pages atomic before download and Vision.
     * Bare image-search URLs without a page relationship intentionally form
     * one-item groups; sharing a CDN host is not proof of one product card.
     *
     * @param  array<int, string>  $urls
     * @return array<int, array{page_url: ?string, context: ?array, urls: array<int, string>}>
     */
    private function groupDiscoveryUrlsBySource(array $urls): array
    {
        return collect($urls)
            ->groupBy(function (string $url): string {
                $context = $this->candidateDiscovery->sourceContextForImage($url);
                $pageUrl = is_array($context) && is_string($context['url'] ?? null)
                    ? $context['url']
                    : $this->candidateDiscovery->sourcePageForImage($url);

                return is_string($pageUrl) && filter_var($pageUrl, FILTER_VALIDATE_URL)
                    ? 'page:'.rtrim($pageUrl, '/')
                    : 'image:'.sha1($url);
            })
            ->map(function ($group, string $key): array {
                $groupUrls = $group->values()->all();
                $context = collect($groupUrls)
                    ->map(fn (string $url): ?array => $this->candidateDiscovery->sourceContextForImage($url))
                    ->first(fn (mixed $value): bool => is_array($value));
                $pageUrl = str_starts_with($key, 'page:') ? substr($key, 5) : null;

                if (! is_array($context) && $pageUrl) {
                    $context = ['url' => $pageUrl];
                }

                return [
                    'page_url' => $pageUrl,
                    'context' => $context,
                    'urls' => $groupUrls,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * The result is deliberately recomputed at the top of every round. Cost
     * can be temporarily unmeasurable before the first AI call and measurable
     * immediately afterwards; callers must never cache this decision for the
     * lifetime of the search cycle.
     */
    private function fallbackSearchSafetyCapReached(
        int $completedRounds,
        int $safetyRounds,
        ?int $telegramUpdateId,
        ?bool $testingEnvironment = null,
    ): bool {
        $safetyLimited = ($testingEnvironment ?? app()->environment('testing'))
            || ! $telegramUpdateId
            || $this->costBudget->limit() <= 0
            || $this->costBudget->unmeasurable($telegramUpdateId);

        return $safetyLimited && $completedRounds >= $safetyRounds;
    }

    private function associateAttemptsWithDraftSince(
        ProductDraft $draft,
        ?int $telegramUpdateId,
        int $afterId,
    ): void {
        $this->attempts->associateWithDraftSince($draft, $telegramUpdateId, $afterId);
    }

    /**
     * Loose static/discovery candidates go through the same visual identity and quality check.
     *
     * Source type is deliberately irrelevant here: an official or marketplace URL
     * can still contain the wrong variant, a thumbnail, a lifestyle image, or a
     * duplicated angle. Domain ordering is handled upstream from measured success.
     *
     * @param  array<int, array<string, mixed>>  $candidates
     * @return array<int, array<string, mixed>>
     */
    private function selectFromCandidates(ProductDraft $draft, array $candidates, int $remaining, ?int $telegramUpdateId = null): array
    {
        return $this->verify($draft, $candidates, $remaining, $telegramUpdateId);
    }

    /**
     * Same audit found other selectFromCandidates() call sites
     * (replaceDraftMedia(), topUpDraftMedia()) with the same gap the
     * confirmed-gallery Vision crash had: no try/catch, so one broken
     * response would crash a single-photo replace or a top-up round
     * instead of just finding nothing new this round. Vision itself already
     * retries once on a structurally invalid response
     * (ProductImageVisionVerifier::reviewGalleryBatch()); this is the
     * second-layer safety net for whatever still gets through, matching
     * verifyConfirmedGallerySafely()'s "degrade to empty, let the caller's
     * existing not-found handling take over" approach.
     *
     * @param  array<int, array<string, mixed>>  $candidates
     * @return array<int, array<string, mixed>>
     */
    private function selectFromCandidatesSafely(
        ProductDraft $draft,
        array $candidates,
        int $remaining,
        ?int $telegramUpdateId,
        ?callable $progress,
    ): array {
        try {
            return $this->selectFromCandidates($draft, $candidates, $remaining, $telegramUpdateId);
        } catch (ProductSearchCancelled $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);
            $progress?->__invoke('Vision временно недоступен; считаю, что новых фото на этом шаге не найдено.');

            return [];
        }
    }

    /** @param array<int, array<string, mixed>> $candidates @return array<int, array<string, mixed>> */
    private function verify(ProductDraft $draft, array $candidates, int $remaining, ?int $telegramUpdateId = null): array
    {
        app(ProductSearchCancellation::class)->throwIfCancelled($telegramUpdateId);
        if ($candidates === []) {
            return [];
        }

        try {
            $selected = [];
            $batchSize = max(1, (int) config('product-images.vision_candidates', 4));
            $maxBatches = max(1, (int) config('product-images.vision_max_batches', 2));

            foreach (array_slice(array_chunk($candidates, $batchSize), 0, $maxBatches) as $batch) {
                app(ProductSearchCancellation::class)->throwIfCancelled($telegramUpdateId);
                $needed = $remaining - count($selected);

                if ($needed <= 0) {
                    break;
                }

                $verifiedBatch = $this->visionVerifier->select($draft, $batch, $needed, $telegramUpdateId);
                app(ProductSearchCancellation::class)->throwIfCancelled($telegramUpdateId);
                $selected = [...$selected, ...$verifiedBatch];
            }

            return $selected;
        } catch (Throwable $exception) {
            throw $exception;
        }
    }

    private function looksLikeJunk(string $url): bool
    {
        return $this->candidateUrls->looksLikeJunk($url);
    }

    /** @param array<int, array<string, mixed>> $candidates */
    private function destroy(array $candidates): void
    {
        foreach ($candidates as $candidate) {
            if (($candidate['image'] ?? null) instanceof GdImage) {
                imagedestroy($candidate['image']);
            }
        }
    }

    /** @param array<int, array<string, mixed>> $candidates @param array<int, array<string, mixed>> $selected */
    private function destroyUnselected(array $candidates, array $selected): void
    {
        $selectedIds = array_map(fn (array $candidate): int => spl_object_id($candidate['image']), $selected);

        foreach ($candidates as $candidate) {
            if (! in_array(spl_object_id($candidate['image']), $selectedIds, true)) {
                imagedestroy($candidate['image']);
            }
        }
    }

    /** @param array<int, array<string, mixed>> $candidates @return array<int, string> */
    private function perceptualHashesForCandidates(array $candidates): array
    {
        return collect($candidates)
            ->map(fn (array $candidate): string => $this->perceptualHash->hash($candidate['image']))
            ->unique()
            ->values()
            ->all();
    }

    /** @return array<int, string> */
    private function perceptualHashesForMedia($media): array
    {
        $hashes = [];

        foreach ($media as $item) {
            if (! $item->disk || ! $item->path) {
                continue;
            }

            try {
                $disk = Storage::disk($item->disk);

                if (! $disk->exists($item->path)) {
                    continue;
                }

                $image = @imagecreatefromstring($disk->get($item->path));

                if (! $image instanceof GdImage) {
                    continue;
                }

                try {
                    $hashes[] = $this->perceptualHash->hash($image);
                } finally {
                    imagedestroy($image);
                }
            } catch (Throwable $exception) {
                Log::warning('Could not fingerprint an existing product image.', [
                    'media_id' => $item->id,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        return array_values(array_unique($hashes));
    }

    /**
     * Vision-approved candidates are already sorted by source rank and score,
     * so the first occurrence of a near-duplicate set is the best one to keep.
     *
     * @param  array<int, array<string, mixed>>  $candidates
     * @return array<int, array<string, mixed>>
     */
    private function removeNearDuplicates(
        array $candidates,
        array $excludedHashes = [],
        bool $preserveConfirmedGalleryFrames = false,
    ): array {
        $threshold = (int) config('product-images.duplicate_hash_threshold', 6);
        $kept = [];
        $excludedHashes = array_values(array_filter($excludedHashes, fn (mixed $hash): bool => is_string($hash) && $hash !== ''));
        $keptHashes = [];

        foreach ($candidates as $candidate) {
            $hash = $this->perceptualHash->hash($candidate['image']);

            // Explicitly rejected/existing photos always win, including over a
            // confirmed gallery frame. The exception below applies only to
            // comparisons between frames of the same Playwright-confirmed
            // atomic gallery: different laptop angles can be perceptually
            // close and must not be silently removed after the browser has
            // already proved that they are separate slider items.
            foreach ($excludedHashes as $existingHash) {
                if ($this->perceptualHash->distance($hash, $existingHash) <= $threshold) {
                    Log::info('Near-duplicate product image dropped.', [
                        'source_url' => $candidate['source_url'] ?? null,
                    ]);

                    continue 2;
                }
            }

            if (! ($preserveConfirmedGalleryFrames && ($candidate['confirmed_gallery'] ?? false))) {
                foreach ($keptHashes as $existingHash) {
                    if ($this->perceptualHash->distance($hash, $existingHash) <= $threshold) {
                        Log::info('Near-duplicate product image dropped.', [
                            'source_url' => $candidate['source_url'] ?? null,
                        ]);

                        continue 2;
                    }
                }
            }

            $keptHashes[] = $hash;
            $kept[] = $candidate;
        }

        return $kept;
    }

    /**
     * @param  array<int, array<string, mixed>>  $existing
     * @param  array<int, array<string, mixed>>  $additional
     * @return array<int, array<string, mixed>>
     */
    private function removeDuplicateCandidates(array $existing, array $additional): array
    {
        $checksums = [];

        foreach ($existing as $candidate) {
            $checksums[hash('sha256', $candidate['bytes'])] = true;
        }

        return array_values(array_filter($additional, function (array $candidate) use (&$checksums): bool {
            $checksum = hash('sha256', $candidate['bytes']);

            if (isset($checksums[$checksum])) {
                imagedestroy($candidate['image']);

                return false;
            }

            $checksums[$checksum] = true;

            return true;
        }));
    }

    public static function normalizeCandidateUrl(string $url): string
    {
        return ImageUrls::normalizeCandidateUrl($url);
    }

    public static function imageAssetKey(string $url): string
    {
        return ImageUrls::imageAssetKey($url);
    }

    private static function candidateMatchesVariant(string $url, ?string $variantHint): bool
    {
        return ImageUrls::candidateMatchesVariant($url, $variantHint);
    }

    private static function candidateUrlQualityScore(string $url): int
    {
        return ImageUrls::candidateUrlQualityScore($url);
    }

    private function targetImageCount(Product $product): int
    {
        $default = max(1, (int) config('product-images.max_images', 3));
        $configured = config("product-images.max_images_by_type.{$product->product_type}");

        return max(1, (int) ($configured ?? $default));
    }

    private function targetDraftImageCount(ProductDraft $draft): int
    {
        $default = max(1, (int) config('product-images.max_images', 3));
        $configured = config("product-images.max_images_by_type.{$draft->product_type}");

        return max(1, (int) ($configured ?? $default));
    }

    private function hasUsefulDimensions(
        int $width,
        int $height,
        int $requiredWidth,
        int $requiredHeight,
    ): bool {
        return $this->imageGeometry->hasUsefulDimensions($width, $height, $requiredWidth, $requiredHeight);
    }

    private function dimensionRejectionReason(
        int $width,
        int $height,
        int $requiredWidth,
        int $requiredHeight,
        bool $allowExtremeAspectRatio = false,
    ): ?string {
        return $this->imageGeometry->dimensionRejectionReason($width, $height, $requiredWidth, $requiredHeight, $allowExtremeAspectRatio);
    }

    private function looksLikeProductPhotoShape(int $width, int $height): bool
    {
        return $this->imageGeometry->looksLikeProductPhotoShape($width, $height);
    }
}
