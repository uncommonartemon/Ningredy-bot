<?php

namespace App\Services\Products;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductDraft;
use App\Models\ProductDraftMedia;
use App\Models\ProductGalleryRecipe;
use App\Models\ProductSourceAttempt;
use App\Models\ProductVariant;
use App\Services\Ai\AiSettings;
use App\Services\Ai\ProductSearchCostBudget;
use App\Services\Ai\ProductSearchTimeBudget;
use GdImage;
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
        private readonly ProductSourcePriority $sourcePriority,
        private readonly ProductSourceMetrics $sourceMetrics,
        private readonly ImagePerceptualHash $perceptualHash,
        private readonly ProductImageEncoder $encoder,
        private readonly ProductIdentityMatcher $identityMatcher,
        private readonly AiSettings $settings,
        private readonly ProductSearchTimeBudget $timeBudget,
        private readonly ProductSearchCostBudget $costBudget,
        private readonly ProductSourceAttemptRecorder $attempts,
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
        // A restage/top-up triggered by a button press minutes or hours after
        // the draft's original search must get its own fresh time budget -
        // defaulting to the draft's original telegram_update_id would make
        // ProductSearchTimeBudget measure elapsed time since that original,
        // already-finished search and report zero time left immediately.
        $telegramUpdateId ??= $draft->telegram_update_id;
        // The previous staged gallery is replaced only after a new one was
        // stored successfully, so a failed search never leaves the draft
        // without photos (same philosophy as completeRefind).
        $previousMedia = $draft->media()->get();
        $excludedHashes = array_values(array_filter($draft->excluded_gallery_hashes ?? [], 'is_string'));

        $target = $this->targetDraftImageCount($draft);
        $minimumCompleteGallerySize = min($this->minimumVerifiedImages($draft), $target);
        $gallerySearchStrategy = $this->gallerySearchStrategy($draft);
        $activeRecipeOnly = $gallerySearchStrategy === Category::GALLERY_SEARCH_VISION_FIRST;
        $progress?->__invoke(match ($gallerySearchStrategy) {
            Category::GALLERY_SEARCH_VISION_FIRST => 'Стратегия категории: Vision-first — использую статичные фото и готовые рецепты доменов без обучения новых рецептов.',
            Category::GALLERY_SEARCH_PLAYWRIGHT_FIRST => 'Стратегия категории: Playwright-first — сначала пытаюсь раскрыть полную галерею карточки.',
            default => 'Стратегия категории: автоматически — использую общий режим поиска фотографий.',
        });
        $prioritizedSources = collect($this->sourcePriority->sortSources([
            ...$cycleSources,
            ...($draft->sources ?? []),
        ], $draft->brand))
            ->unique(fn (array $source): string => rtrim((string) ($source['url'] ?? ''), '/'))
            ->values()
            ->all();
        // Historical reliability decides which source is selected before the
        // cycle starts, but a failed HTML/Playwright probe during that same
        // cycle must not immediately demote the chosen primary source before
        // its already-known direct image URLs are downloaded and checked by
        // Vision. Keep it first for this draft; only a real end-to-end gallery
        // failure is allowed to advance to the remaining ranked sources.
        if (is_string($draft->primary_source_url) && $draft->primary_source_url !== '') {
            $primarySources = array_values(array_filter(
                $prioritizedSources,
                fn (array $source): bool => ($source['url'] ?? null) === $draft->primary_source_url,
            ));
            $otherSources = array_values(array_filter(
                $prioritizedSources,
                fn (array $source): bool => ($source['url'] ?? null) !== $draft->primary_source_url,
            ));
            $prioritizedSources = [...$primarySources, ...$otherSources];
        }
        $cardSources = collect($prioritizedSources)
            ->filter(fn (mixed $source): bool => is_array($source)
                && is_string($source['url'] ?? null)
                && in_array($source['type'] ?? null, ['retailer', 'marketplace', 'manufacturer'], true)
                && ! $this->sourceExcludedForDraft($source['url'], $draft)
                && ! $this->sourceExcludedByUrls($source['url'], $cycleExcludedSourceUrls))
            ->values();

        if (config('product-images.source_preflight', true)) {
            $progress?->__invoke('Быстро проверяю доступность карточек, CAPTCHA/WAF, статические фото и готовые рецепты до запуска Playwright.');
            $cardSources = $cardSources
                ->map(function (array $source, int $index) use ($telegramUpdateId): array {
                    $preflight = $this->resolver->preflightSource($source, $telegramUpdateId);

                    return [
                        ...$source,
                        'image_urls' => array_values(array_unique([
                            ...$this->cleanUrls($preflight['static_image_urls'] ?? []),
                            ...$this->cleanUrls($source['image_urls'] ?? []),
                        ])),
                        '_preflight_blocked' => (bool) ($preflight['blocked'] ?? false),
                        '_preflight_unavailable' => (bool) ($preflight['unavailable'] ?? false),
                        '_preflight_active_recipe' => (bool) ($preflight['active_recipe'] ?? false),
                        // Set on a 401/403/429 or a detected JS/cookie gate:
                        // the cheap HTTP fetch was refused, but a real
                        // headless browser often gets through where it
                        // can't - was computed and then never read by
                        // anything downstream, so a bot-blocked official
                        // source (e.g. a manufacturer's own store) silently
                        // sank to the bottom on "zero evidence" exactly like
                        // a genuinely irrelevant one, with nothing left to
                        // tell them apart once ranked below a worse source
                        // that merely fetched without erroring.
                        '_preflight_browser_probe_required' => (bool) ($preflight['browser_probe_required'] ?? false),
                        '_preflight_final_url' => (string) ($preflight['final_url'] ?? $source['url']),
                        '_preflight_identity_evidence' => (string) ($preflight['identity_evidence'] ?? ''),
                        '_preflight_index' => $index,
                    ];
                })
                ->filter(fn (array $source): bool => ! $source['_preflight_blocked'] && ! $source['_preflight_unavailable'])
                ->sortByDesc(fn (array $source): array => [
                    $this->identityMatcher->supportsSource($draft, $source) ? 1 : 0,
                    $source['_preflight_active_recipe'] ? 1 : 0,
                    count($source['image_urls'] ?? []),
                    $source['_preflight_browser_probe_required'] ? 1 : 0,
                    -$source['_preflight_index'],
                ])
                ->values();
        }

        if (! $this->settings->fallbackSourcesEnabled()) {
            $cardSources = $cardSources->take(1)->values();
        }

        $selected = [];
        $chosenSource = null;
        $chosenMethod = null;
        $partialSelected = [];
        $partialSource = null;
        $partialMethod = null;
        $partialFromDiscovery = false;
        $deferredVisionSets = [];

        foreach ($cardSources as $sourceIndex => $source) {
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

            if (
                $sourceIndex > 0
                && $deferredVisionSets !== []
                && $this->costBudget->reachedFraction(
                    $telegramUpdateId,
                    (float) config('product-images.source_exploration_budget_fraction', 0.70),
                )
            ) {
                $progress?->__invoke('Резервирую остаток бюджета для Vision и резервного поиска; новые домены Playwright не обучаю.');
                break;
            }

            if (! $this->timeBudget->canStart($telegramUpdateId, 20)) {
                $progress?->__invoke('Резерв времени достигнут: новые источники больше не открываю, завершаю текущий результат.');
                break;
            }

            if ($progress) {
                $progress('Проверяю источник '.($sourceIndex + 1).'/'.$cardSources->count().': '.$source['url']);
            }

            $source['_minimum_verified_images'] = $minimumCompleteGallerySize;
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
            $sourceIdentityConfirmed = $this->identityMatcher->supportsSource($draft, $source)
                && (! $redirectedSource || $this->identityMatcher->supportsSource($draft, $finalSourceEvidence));

            if ($this->identityMatcher->conflictsSource($draft, $source)) {
                $this->attempts->record([
                    'telegram_update_id' => $telegramUpdateId,
                    'product_draft_id' => $draft->id,
                    'product_url' => $source['url'],
                    'actor' => 'identity_validator',
                    'phase' => 'source_selection',
                    'action' => 'validate_product_identity',
                    'status' => 'failed',
                    'decision' => 'reject_conflicting_identifier',
                    'output' => ['title' => $source['title'] ?? null],
                ]);
                $progress?->__invoke('Источник пропущен: URL, заголовок или HTML содержат конфликтующую модель/SKU.');

                continue;
            }

            if ($this->identityMatcher->requiresExactIdentifier($draft) && ! $sourceIdentityConfirmed) {
                $this->attempts->record([
                    'telegram_update_id' => $telegramUpdateId,
                    'product_draft_id' => $draft->id,
                    'product_url' => $source['url'],
                    'actor' => 'identity_validator',
                    'phase' => 'source_selection',
                    'action' => 'validate_product_identity',
                    'status' => 'failed',
                    'decision' => 'reject_unconfirmed_identifier',
                    'output' => ['title' => $source['title'] ?? null],
                ]);
                $progress?->__invoke('Источник пропущен: карточка не подтверждает точную модель или SKU выбранного товара.');

                continue;
            }

            $urls = $this->cleanUrls($source['image_urls'] ?? []);

            if (($source['url'] ?? null) === $draft->primary_source_url) {
                $urls = array_values(array_unique([
                    ...$urls,
                    ...$this->cleanUrls($draft->image_urls ?? []),
                ]));
            }

            $sourceBlocked = false;
            $resolvedUrls = $this->resolver->resolve(
                [$source],
                max(8, $target * 2),
                function (string $level, string $message) use (&$sourceBlocked, $progress): void {
                    if ($level === 'blocked') {
                        $sourceBlocked = true;
                    }
                    if ($progress) {
                        $progress($message);
                    }
                },
                $telegramUpdateId,
                activeRecipeOnly: $activeRecipeOnly || $sourceIndex > 0,
            );

            $resolvedPageContext = collect($resolvedUrls)
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
                $runtimeIdentityConfirmed = $this->identityMatcher->supportsSource($draft, $runtimeSourceEvidence);

                if ($this->identityMatcher->conflictsSource($draft, $runtimeSourceEvidence)
                    || ($this->identityMatcher->requiresExactIdentifier($draft) && ! $runtimeIdentityConfirmed)) {
                    $progress?->__invoke('Источник пропущен: конечная страница после редиректа не подтверждает точную модель/SKU.');

                    continue;
                }

                $productPageUrl = $resolvedFinalUrl;
                $sourceIdentityConfirmed = $sourceIdentityConfirmed && $runtimeIdentityConfirmed;
            }

            if ($sourceBlocked) {
                if ($progress) {
                    $progress('Источник пропущен: ссылка ведёт на защитную заглушку, а не на товар.');
                }

                continue;
            }

            // Browser/DOM gallery URLs are more reliable than AI-provided thumbnails.
            $urls = array_values(array_unique([...$resolvedUrls, ...$urls]));
            $allCandidates = $this->downloadCandidates($urls, $draft);
            $downloadedCount = count($allCandidates);

            if ($allCandidates !== []) {
                $uniqueCandidates = $this->removeNearDuplicates($allCandidates, $excludedHashes);
                $this->destroyUnselected($allCandidates, $uniqueCandidates);
                $allCandidates = array_map(fn (array $candidate): array => [
                    ...$candidate,
                    'source_identity_confirmed' => $sourceIdentityConfirmed,
                    'product_page_url' => $productPageUrl,
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
                    'rejected_candidates' => array_slice($this->lastDownloadRejections, 0, 20),
                ],
            ]);

            if ($allCandidates === []) {
                $progress?->__invoke('Не удалось скачать ни одного технически пригодного изображения: '.$source['url']);

                continue;
            }

            $confirmedGallery = collect($allCandidates)
                ->filter(fn (array $candidate): bool => (bool) ($candidate['confirmed_gallery'] ?? false))
                ->values();
            $structurallyConfirmed = $sourceIdentityConfirmed
                && $confirmedGallery->count() >= $minimumCompleteGallerySize;
            $trustPlaywrightGallery = $structurallyConfirmed
                && $this->confirmedGalleryContentIsProduct($productPageUrl, $confirmedGallery, $draft, $telegramUpdateId);

            if (! $trustPlaywrightGallery) {
                $deferredVisionSets[] = [
                    'candidates' => $allCandidates,
                    'source' => $source,
                ];
                usort($deferredVisionSets, fn (array $left, array $right): int =>
                    count($right['candidates']) <=> count($left['candidates'])
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
                    'decision' => 'try_next_source_before_vision',
                    'input' => ['downloaded_images' => count($allCandidates)],
                ]);
                $progress?->__invoke('Подтверждённый слайдер не получен; сохраняю набор в резерв и сначала проверяю следующую страницу.');

                continue;
            }

            $selected = $confirmedGallery
                ->take($target)
                ->map(fn (array $candidate): array => [
                    ...$candidate,
                    'verification_status' => 'source_verified',
                    'verification_notes' => 'Exact product source and complete Playwright-confirmed slider.',
                ])
                ->all();
            $progress?->__invoke('Playwright подтвердил единый слайдер точной карточки: принимаю '.count($selected).' разных фото без Vision.');
            $this->attempts->record([
                'telegram_update_id' => $telegramUpdateId,
                'product_draft_id' => $draft->id,
                'product_url' => $source['url'],
                'actor' => 'playwright',
                'phase' => 'image_verification',
                'action' => 'verify_gallery',
                'status' => 'completed',
                'decision' => 'accept_confirmed_slider_without_vision',
                'input' => ['downloaded_images' => count($allCandidates)],
                'output' => [
                    'accepted_images' => count($selected),
                    'accepted_urls' => collect($selected)->pluck('source_url')->values()->all(),
                ],
            ]);
            $this->destroyUnselected($allCandidates, $selected);

            $this->destroy($partialSelected);
            $partialSelected = [];
            $chosenSource = [...$source, 'url' => $productPageUrl];
            $chosenMethod = 'playwright';
            break;
        }

        if ($selected === [] && $deferredVisionSets !== []) {
            $progress?->__invoke('Подтверждённого слайдера нет; последовательно проверяю Vision независимые наборы лучших страниц.');

            foreach ($deferredVisionSets as $setIndex => $set) {
                $candidates = $set['candidates'];
                $source = $set['source'];
                $candidateCount = count($candidates);

                try {
                    $verified = $this->selectFromCandidates($draft, $candidates, $target, $telegramUpdateId);
                    $verified = $this->removeNearDuplicates($verified, $excludedHashes);
                    $this->destroyUnselected($candidates, $verified);
                } catch (Throwable $exception) {
                    report($exception);
                    $this->destroy($candidates);
                    $verified = [];
                    $progress?->__invoke('Один набор Vision временно недоступен; пробую следующую страницу.');
                }

                $this->attempts->record([
                    'telegram_update_id' => $telegramUpdateId,
                    'product_draft_id' => $draft->id,
                    'product_url' => $source['url'] ?? null,
                    'actor' => 'vision',
                    'phase' => 'image_verification',
                    'action' => 'verify_deferred_source',
                    'status' => $verified !== [] ? 'completed' : 'failed',
                    'decision' => count($verified) >= $minimumCompleteGallerySize
                        ? 'accept_images'
                        : 'try_next_independent_source',
                    'input' => ['downloaded_images' => $candidateCount],
                    'output' => [
                        'accepted_images' => count($verified),
                        'accepted_urls' => collect($verified)->pluck('source_url')->values()->all(),
                    ],
                ]);

                if (count($verified) >= $minimumCompleteGallerySize) {
                    $this->destroy($partialSelected);
                    $partialSelected = [];
                    $selected = $verified;
                    $chosenSource = $source;
                    $chosenMethod = 'static';

                    foreach (array_slice($deferredVisionSets, $setIndex + 1) as $unusedSet) {
                        $this->destroy($unusedSet['candidates']);
                    }

                    break;
                }

                if (count($verified) > count($partialSelected)) {
                    $this->destroy($partialSelected);
                    $partialSelected = $verified;
                    $partialSource = $source;
                    $partialMethod = 'static';
                    $partialFromDiscovery = false;
                } else {
                    $this->destroy($verified);
                }
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
            $safetyLimited = app()->environment('testing')
                || ! $telegramUpdateId
                || $this->costBudget->limit() <= 0
                || $this->costBudget->unmeasurable($telegramUpdateId);
            $safetyRounds = max(1, (int) config('product-images.fallback_search_rounds', 3));

            while (! $safetyLimited || $fallbackRound < $safetyRounds) {
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
                    $progress?->__invoke("Раунд резервного поиска {$fallbackRound} не дал загружаемых фото; исключаю проверенные источники и продолжаю поиск.{$details}");

                    if ($this->candidateDiscovery->hasTerminalFailure()) {
                        break;
                    }

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
                    $groupIdentityConfirmed = is_array($groupSource)
                        && ! $this->identityMatcher->conflictsSource($draft, $groupSource)
                        && $this->identityMatcher->supportsSource($draft, $groupSource);

                    if (
                        $groupIdentityConfirmed
                        && $confirmedGroup->count() >= $minimumCompleteGallerySize
                    ) {
                        $selected = $confirmedGroup
                            ->take($target)
                            ->map(fn (array $candidate): array => [
                                ...$candidate,
                                'source_identity_confirmed' => true,
                                'verification_status' => 'source_verified',
                                'verification_notes' => 'Exact product source and complete Playwright-confirmed slider discovered during fallback search.',
                            ])
                            ->all();
                        $this->destroy($partialSelected);
                        $partialSelected = [];
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
                            'decision' => 'accept_confirmed_slider_without_vision',
                            'input' => ['downloaded_images' => count($groupCandidates)],
                            'output' => [
                                'accepted_images' => count($selected),
                                'accepted_urls' => collect($selected)->pluck('source_url')->values()->all(),
                            ],
                        ]);
                        $progress?->__invoke('Резервный поиск нашёл и подтвердил Playwright-галерею точной карточки: принимаю '.count($selected).' фото и прекращаю обход источников.');

                        break;
                    }

                    try {
                        $verified = $this->selectFromCandidates($draft, $groupCandidates, $target, $telegramUpdateId);
                    } catch (Throwable $exception) {
                        report($exception);
                        $progress?->__invoke('Vision резервного поиска временно недоступен; завершаю с лучшим уже полученным результатом.');

                        continue;
                    }
                    $selected = $this->removeNearDuplicates($verified, $excludedHashes);

                    if ($selected !== []) {
                        if (count($selected) < $minimumCompleteGallerySize) {
                            if (count($selected) > count($partialSelected)) {
                                if (! $partialFromDiscovery) {
                                    $this->destroy($partialSelected);
                                }
                                $partialSelected = $selected;
                                $partialSource = $groupPageUrl ? ['url' => $groupPageUrl] : null;
                                $partialMethod = 'fallback_discovery';
                                $partialFromDiscovery = true;
                            }
                            $selected = [];

                            continue;
                        }

                        $this->destroy($partialSelected);
                        $partialSelected = [];
                        $chosenSource = $groupPageUrl ? ['url' => $groupPageUrl] : null;
                        $chosenMethod = 'fallback_discovery';
                        break;
                    }
                }

                $roundKeep = $selected !== []
                    ? $selected
                    : ($partialFromDiscovery ? $partialSelected : []);
                $this->destroyUnselected($discoveredCandidates, $roundKeep);

                if ($selected !== []) {
                    break;
                }
            }

            if ($selected === [] && $partialSelected !== []) {
                $selected = $partialSelected;
                $chosenSource = $partialSource;
                $chosenMethod = $partialMethod;
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

        if ($selected === [] && $partialSelected !== []) {
            $selected = $partialSelected;
            $chosenSource = $partialSource;
            $chosenMethod = $partialMethod;
        }

        $playwrightConfirmedComplete = in_array($chosenMethod, ['playwright', 'fallback_playwright'], true)
            && collect($selected)->every(fn (array $candidate): bool => (bool) ($candidate['confirmed_gallery'] ?? false));
        $galleryIsPartial = $selected !== [] && ! $playwrightConfirmedComplete && (
            count($selected) < $minimumCompleteGallerySize
            || collect($selected)->every(fn (array $candidate): bool => (bool) ($candidate['partial_gallery'] ?? false))
        );
        $roles = ['primary', 'secondary', 'detail'];
        $stored = 0;
        $checksums = [];
        $reusedMediaIds = [];
        $storedSourceUrls = [];

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
                    $existingMedia->update([
                        'source_url' => $candidate['source_url'],
                        'role' => $role,
                        'width' => $converted['width'],
                        'height' => $converted['height'],
                        'file_size' => strlen($encoded),
                        'verification_status' => $candidate['verification_status'] ?? 'verified',
                        'verification_score' => isset($candidate['vision_score']) ? $candidate['vision_score'] / 100 : null,
                        'verification_model' => $candidate['vision_model'] ?? $candidate['verification_model'] ?? null,
                        'verification_notes' => $candidate['vision_reason'] ?? $candidate['verification_notes'] ?? null,
                        'sort_order' => $stored,
                        'is_primary' => $stored === 0,
                    ]);
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

                $draft->media()->create([
                    'disk' => 'public',
                    'path' => $path,
                    'source_url' => $candidate['source_url'],
                    'role' => $role,
                    'mime_type' => 'image/webp',
                    'width' => $converted['width'],
                    'height' => $converted['height'],
                    'file_size' => strlen($encoded),
                    'checksum' => $checksum,
                    'verification_status' => $candidate['verification_status'] ?? 'verified',
                    'verification_score' => isset($candidate['vision_score']) ? $candidate['vision_score'] / 100 : null,
                    'verification_model' => $candidate['vision_model'] ?? $candidate['verification_model'] ?? null,
                    'verification_notes' => $candidate['vision_reason'] ?? $candidate['verification_notes'] ?? null,
                    'sort_order' => $stored,
                    'is_primary' => $stored === 0,
                ]);
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

        if ($stored > 0) {
            foreach ($previousMedia->whereNotIn('id', $reusedMediaIds) as $media) {
                $media->delete();
            }

            if (is_array($chosenSource) && is_string($chosenSource['url'] ?? null)) {
                $this->sourceMetrics->recordAcceptedGallery($chosenSource['url'], $stored);
            }
        }

        $effectiveImageCount = $stored > 0 ? $stored : $previousMedia->count();
        // A round that added nothing new ($stored === 0) must not silently
        // treat a retained-but-still-below-minimum gallery as finished just
        // because it isn't empty. Only a gallery an earlier round already
        // confirmed complete (draft->gallery_status, read here before this
        // call's own update() below overwrites it) or one that already
        // reached the category minimum has genuinely nothing left to find.
        $searchIncomplete = match (true) {
            $stored > 0 => $galleryIsPartial,
            $previousMedia->isEmpty() => true,
            default => $draft->gallery_status !== 'complete' && $previousMedia->count() < $minimumCompleteGallerySize,
        };
        $stopReason = $searchIncomplete
            ? match (true) {
                $this->costBudget->exceeded($telegramUpdateId) => 'cost_budget',
                ! $this->timeBudget->canStart($telegramUpdateId, 20) => 'time_budget',
                default => null,
            }
        : null;

        $draft->update([
            'primary_source_url' => $chosenSource['url'] ?? $draft->primary_source_url,
            'image_urls' => $stored > 0 ? array_values(array_unique($storedSourceUrls)) : $draft->image_urls,
            'gallery_status' => $stored > 0
                ? ($galleryIsPartial ? 'partial' : 'complete')
                : ($previousMedia->isNotEmpty() ? $draft->gallery_status : 'missing'),
            'gallery_notes' => $stored > 0 && $galleryIsPartial
                ? 'После полного цикла поиска сохранён лучший частичный результат: '.$stored.' проверенных фото.'
                : null,
            'gallery_search_stop_reason' => $stopReason,
            'images_staged_at' => now(),
        ]);

        if ($progress) {
            if ($stored > 0) {
                $methodLabel = match ($chosenMethod) {
                    'playwright' => 'через AI-переобучение Playwright-рецепта',
                    'fallback_playwright' => 'через Playwright-галерею карточки, найденной резервным поиском',
                    'fallback_discovery' => 'через резервный поиск (источник не из карточек AI-исследования)',
                    default => 'из статичной HTML-галереи карточки',
                };
                $sourceUrl = is_array($chosenSource) && is_string($chosenSource['url'] ?? null)
                    ? $chosenSource['url']
                    : null;
                $progress(
                    "📦 Итог: {$stored} фото {$methodLabel}"
                    .($galleryIsPartial ? ' · частичный результат' : '').'.'
                    .($sourceUrl ? "\n🔗 {$sourceUrl}" : ''),
                );
            } else {
                $progress('📦 Итог: подходящая галерея не найдена ни по одному источнику.');
            }
        }

        return $stored;
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

    private function minimumVerifiedImages(ProductDraft $draft): int
    {
        $slug = trim((string) $draft->category);

        if ($slug === '') {
            return 3;
        }

        return Category::query()->where('slug', $slug)->first()?->minimumVerifiedImages() ?? 3;
    }

    /** @param null|callable(string): void $progress */
    public function continueStage(ProductDraft $draft, ?callable $progress = null, ?int $telegramUpdateId = null): int
    {
        // A page counts as exhausted only after real work happened on it
        // (selection/resolution/training) or after a failed preflight proved
        // it blocked/unavailable. A merely successful cheap preflight must
        // not exclude a source that was never run through Playwright/Vision.
        $attemptedUrls = ProductSourceAttempt::query()
            ->where('product_draft_id', $draft->id)
            ->where(function ($query): void {
                $query->whereIn('phase', ['source_selection', 'source_resolution', 'gallery_training'])
                    ->orWhere(function ($preflight): void {
                        $preflight->where('phase', 'gallery_preflight')->where('status', 'failed');
                    });
            })
            ->orderBy('id')
            ->pluck('product_url')
            ->filter(fn (mixed $url): bool => is_string($url) && filter_var($url, FILTER_VALIDATE_URL) !== false)
            ->unique()
            ->values()
            ->all();

        // The last page may be the one whose recipe finished exactly as the
        // budget was crossed. Retry that checkpoint after the contract fix;
        // every older page/domain is already exhausted for this draft.
        array_pop($attemptedUrls);
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

    /**
     * Replace one staged photo without touching the rest of the draft gallery.
     *
     * @param  null|callable(string): void  $progress
     */
    public function replaceDraftMedia(ProductDraft $draft, ProductDraftMedia $media, ?callable $progress = null, ?int $telegramUpdateId = null): ProductDraftMedia
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
            $urls = array_values(array_diff($this->cleanUrls([
                ...($source['image_urls'] ?? []),
                ...$this->resolver->resolve(
                    [$source],
                    10,
                    $progress ? fn (string $level, string $message) => $progress($message) : null,
                    $telegramUpdateId,
                    staticOnly: true,
                ),
            ]), $existingUrls));
            $candidates = $this->downloadCandidates($urls, $draft);

            if ($candidates === []) {
                continue;
            }

            $verified = $this->selectFromCandidates($draft, $candidates, 1, $telegramUpdateId);
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
            $verified = $this->selectFromCandidates($draft, $candidates, 1, $telegramUpdateId);
            $selected = $this->removeNearDuplicates($verified, $excludedHashes);
            $this->destroyUnselected($candidates, $selected);
        }

        throw_if($selected === [], \RuntimeException::class, 'Не найдено новое непохожее фото той же модели и цвета.');
        $candidate = $selected[0];
        $newPath = null;

        try {
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
            $urls = array_values(array_diff($this->cleanUrls([
                ...($source['image_urls'] ?? []),
                ...$this->resolver->resolve(
                    [$source],
                    max(4, $needed * 2),
                    $progress ? fn (string $level, string $message) => $progress($message) : null,
                    $telegramUpdateId,
                ),
            ]), $knownUrls));
            $candidates = $this->downloadCandidates($urls, $draft);

            if ($candidates === []) {
                continue;
            }

            $verified = $this->selectFromCandidates($draft, $candidates, $needed, $telegramUpdateId);
            $newlySelected = $this->removeNearDuplicates($verified, [
                ...$excludedHashes,
                ...$this->perceptualHashesForCandidates($selected),
            ]);
            $this->destroyUnselected($candidates, $newlySelected);
            $selected = [...$selected, ...$newlySelected];
        }

        if (count($selected) < $remaining) {
            if ($progress) {
                $progress('Источники исчерпаны; выполняю дополнительный поиск.');
            }

            $knownUrls = array_values(array_unique([...$existingUrls, ...collect($selected)->pluck('source_url')->all()]));
            $candidates = $this->downloadCandidates(
                $this->currentDraftSourceUrls($draft, max(4, $remaining * 2), $progress, $telegramUpdateId),
                $draft,
            );
            $verified = $this->selectFromCandidates($draft, $candidates, $remaining - count($selected), $telegramUpdateId);
            $newlySelected = $this->removeNearDuplicates($verified, [
                ...$excludedHashes,
                ...$this->perceptualHashesForCandidates($selected),
            ]);
            $this->destroyUnselected($candidates, $newlySelected);
            $selected = [...$selected, ...$newlySelected];
        }

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

    private function sourceExcludedForDraft(string $url, ProductDraft $draft): bool
    {
        $host = ProductSourcePriority::host($url);

        if ($host === '') {
            return false;
        }

        return collect($draft->excluded_gallery_source_urls ?? [])
            ->filter(fn (mixed $excluded): bool => is_string($excluded))
            ->contains(fn (string $excluded): bool => ProductSourcePriority::hostsMatch(
                $host,
                ProductSourcePriority::host($excluded),
            ));
    }

    /** @param array<int, string> $excludedUrls */
    private function sourceExcludedByUrls(string $url, array $excludedUrls): bool
    {
        $host = ProductSourcePriority::host($url);

        if ($host === '') {
            return false;
        }

        return collect($excludedUrls)
            ->filter(fn (mixed $excluded): bool => is_string($excluded))
            ->contains(fn (string $excluded): bool => ProductSourcePriority::hostsMatch(
                $host,
                ProductSourcePriority::host($excluded),
            ));
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

    /** @return array<int, string> */
    private function currentDraftSourceUrls(ProductDraft $draft, int $limit, ?callable $progress = null, ?int $telegramUpdateId = null, bool $staticOnly = false): array
    {
        $source = $this->currentDraftSource($draft);

        if (! $source) {
            return [];
        }

        return $this->cleanUrls([
            ...($source['image_urls'] ?? []),
            ...$this->resolver->resolve(
                [$source],
                $limit,
                $progress ? fn (string $level, string $message) => $progress($message) : null,
                $telegramUpdateId ?? $draft->telegram_update_id,
                staticOnly: $staticOnly,
            ),
        ]);
    }

    /** @param array<int, mixed> $urls @return array<int, string> */
    private function cleanUrls(array $urls): array
    {
        $limit = (int) config('product-images.download_limit', 20);

        return collect($urls)
            ->filter(fn (mixed $url): bool => is_string($url))
            ->map(fn (string $url): string => self::normalizeCandidateUrl($url))
            ->filter(fn (string $url): bool => $url !== '' && ! $this->looksLikeJunk($url))
            ->sortByDesc(fn (string $url): int => self::candidateUrlQualityScore($url))
            ->unique(fn (string $url): string => self::imageAssetKey($url))
            ->take($limit)
            ->values()
            ->all();
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

    /** @param array<int, string> $urls @return array<int, array<string, mixed>> */
    private function downloadCandidates(array $urls, ProductDraft $draft): array
    {
        $candidates = [];
        $checksums = [];
        $limit = (int) config('product-images.download_candidates', 8);
        $sourcePagesByUrl = [];
        $sourceContextsByUrl = [];
        $this->lastDownloadRejections = [];

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
        $urls = collect($urls)
            ->values()
            ->map(fn (string $url, int $index): array => [
                'url' => $url,
                'index' => $index,
                'gallery_rank' => $this->resolver->isConfirmedGalleryImage($url)
                    ? 2
                    : ($this->resolver->isPartialGalleryImage($url) ? 1 : 0),
            ])
            ->sort(fn (array $left, array $right): int =>
                ($right['gallery_rank'] <=> $left['gallery_rank'])
                ?: ($left['index'] <=> $right['index'])
            )
            ->pluck('url')
            ->all();

        $confirmedDownloads = 0;
        $confirmedStopThreshold = $this->minimumVerifiedImages($draft);

        foreach ($urls as $url) {
            if (count($candidates) >= $limit) {
                break;
            }

            if (
                ! $this->resolver->isConfirmedGalleryImage($url)
                && $confirmedDownloads >= $confirmedStopThreshold
            ) {
                break;
            }

            $failureReason = null;
            $download = $this->resolver->download($url, failureReason: $failureReason);

            if (! $download) {
                $this->lastDownloadRejections[] = ['url' => $url, 'reason' => $failureReason ?? 'unknown'];

                continue;
            }

            $confirmedGallery = (bool) ($download['confirmed_gallery'] ?? false);
            $minimumSide = $confirmedGallery
                ? $this->settings->confirmedGalleryMinimumSide()
                : null;

            if (! $this->hasUsefulDimensions(
                $download['width'],
                $download['height'],
                $minimumSide,
                $confirmedGallery,
            )) {
                $this->lastDownloadRejections[] = ['url' => $url, 'reason' => "too_small ({$download['width']}x{$download['height']})"];

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

            $image = @imagecreatefromstring($download['bytes']);

            if (! $image instanceof GdImage) {
                $this->lastDownloadRejections[] = ['url' => $url, 'reason' => 'gd_decode_failed'];

                continue;
            }

            $pageContext = $sourceContextsByUrl[$url] ?? null;
            $pageIdentityConfirmed = is_array($pageContext)
                && ! $this->identityMatcher->conflictsSource($draft, $pageContext)
                && $this->identityMatcher->supportsSource($draft, $pageContext);
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

        return $candidates;
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
        $newUrls = array_values(array_diff($this->cleanUrls($discovered), $existingUrls));
        $candidates = $this->downloadCandidates($newUrls, $draft);

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

    /** @param array<int, array<string, mixed>> $candidates @return array<int, array<string, mixed>> */
    private function verify(ProductDraft $draft, array $candidates, int $remaining, ?int $telegramUpdateId = null): array
    {
        if ($candidates === []) {
            return [];
        }

        try {
            $selected = [];
            $batchSize = max(1, (int) config('product-images.vision_candidates', 4));
            $maxBatches = max(1, (int) config('product-images.vision_max_batches', 2));

            foreach (array_slice(array_chunk($candidates, $batchSize), 0, $maxBatches) as $batch) {
                $needed = $remaining - count($selected);

                if ($needed <= 0) {
                    break;
                }

                $verifiedBatch = $this->visionVerifier->select($draft, $batch, $needed, $telegramUpdateId);
                $selected = [...$selected, ...$verifiedBatch];
            }

            return $selected;
        } catch (Throwable $exception) {
            throw $exception;
        }
    }

    /**
     * A structurally complete, identity-confirmed Playwright slider still
     * needs its actual content sanity-checked: a slider can be fully
     * confirmed (right count, right SKU) while showing something other than
     * the product itself - a manufacturing/materials story slider, a
     * lifestyle scene, a feature screenshot - and nothing else in this fast
     * path looks at pixels. Whether that is true is a property of the
     * recipe/template (the same DOM position keeps showing the same kind of
     * content for every product on that domain), not of any one search, so
     * this checks Vision on exactly one representative frame only the first
     * time a given recipe's confirmed-slider path is actually exercised, and
     * persists the verdict onto the recipe row - content_verified_by_vision
     * is written only here, never by the AI trainer, precisely because the
     * trainer's own content_confirmed_product claim (free-text DOM
     * reasoning, no pixels) is what this exists to double-check: every
     * recipe gets exactly one real spot check regardless of what it claims,
     * so an overconfident trainer can't skip the check just by saying true.
     * Every later search against the same recipe reuses that verdict for
     * free. A fresh AI training round replaces the whole recipe JSON, which
     * naturally drops this key and re-arms the check for the new version.
     *
     * @param  \Illuminate\Support\Collection<int, array<string, mixed>>  $confirmedGallery
     */
    private function confirmedGalleryContentIsProduct(
        string $productPageUrl,
        \Illuminate\Support\Collection $confirmedGallery,
        ProductDraft $draft,
        ?int $telegramUpdateId,
    ): bool {
        if ($confirmedGallery->isEmpty()) {
            return true;
        }

        $host = strtolower((string) parse_url($productPageUrl, PHP_URL_HOST));
        $recipe = ProductGalleryRecipe::query()
            ->where('domain', $host)
            ->where('path_pattern', '*')
            ->first();
        $verified = $recipe?->recipe['content_verified_by_vision'] ?? null;

        if (is_bool($verified)) {
            return $verified;
        }

        $spotCheckCandidate = $confirmedGallery->first();
        $approved = $this->visionVerifier->select($draft, [$spotCheckCandidate], 1, $telegramUpdateId);
        $passed = $approved !== [];

        $recipe?->update(['recipe' => [...$recipe->recipe, 'content_verified_by_vision' => $passed]]);

        $this->attempts->record([
            'telegram_update_id' => $telegramUpdateId,
            'product_draft_id' => $draft->id,
            'product_url' => $productPageUrl,
            'actor' => 'vision',
            'phase' => 'image_verification',
            'action' => 'spot_check_confirmed_slider',
            'status' => 'completed',
            'decision' => $passed ? 'content_confirmed' : 'content_rejected',
            'input' => [
                'candidate_url' => $spotCheckCandidate['source_url'] ?? null,
                'ai_claimed' => $recipe?->recipe['content_confirmed_product'] ?? null,
            ],
        ]);

        return $passed;
    }

    private function looksLikeJunk(string $url): bool
    {
        return ImageUrlHeuristics::containsMarker($url, [
            ...ImageUrlHeuristics::COMMON_MARKERS,
            ...ImageUrlHeuristics::THUMBNAIL_MARKERS,
            ...ImageUrlHeuristics::TRACKING_MARKERS,
            ...ImageUrlHeuristics::ASSET_MARKERS,
            'avatar', 'icon-', '/icon/', '/flags/', 'locale-flag', '/blogs/', '/category/icons/', 'banner',
        ]);
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
    private function removeNearDuplicates(array $candidates, array $excludedHashes = []): array
    {
        $threshold = (int) config('product-images.duplicate_hash_threshold', 6);
        $kept = [];
        $hashes = array_values(array_filter($excludedHashes, fn (mixed $hash): bool => is_string($hash) && $hash !== ''));

        foreach ($candidates as $candidate) {
            $hash = $this->perceptualHash->hash($candidate['image']);

            foreach ($hashes as $existingHash) {
                if ($this->perceptualHash->distance($hash, $existingHash) <= $threshold) {
                    Log::info('Near-duplicate product image dropped.', [
                        'source_url' => $candidate['source_url'] ?? null,
                    ]);

                    continue 2;
                }
            }

            $hashes[] = $hash;
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

    /**
     * Pure URL rewrite, no instance state - also called statically from
     * ProductGalleryRecipeTrainer::preflight() so the "is this the same photo
     * at another size" judgment used for downloading candidates and the one
     * used for the AI preflight's static-gallery headcount never diverge.
     */
    public static function normalizeCandidateUrl(string $url): string
    {
        $url = trim($url);
        $host = Str::lower((string) parse_url($url, PHP_URL_HOST));

        if ($host === 'static.bhphoto.com' || str_ends_with($host, '.bhphoto.com')) {
            $url = preg_replace(
                '#/multiple_images/(?:thumbnails|images\d+x\d+)/#i',
                '/multiple_images/images2500x2500/',
                $url,
            ) ?: $url;

            return preg_replace(
                '#/images/(?:smallimages|images\d+x\d+)/#i',
                '/images/images2500x2500/',
                $url,
            ) ?: $url;
        }

        if (str_contains($host, 'dlcdnwebimgs.asus.com')) {
            return preg_replace('#//w(?:48|64|96|184)(?:\?|$)#i', '//w800', $url) ?: $url;
        }

        if ($host === 'm.media-amazon.com' || str_ends_with($host, '.media-amazon.com')) {
            return preg_replace('#\._[^/]+(?=\.(?:jpe?g|png|webp)(?:$|\?))#i', '', $url) ?: $url;
        }

        // Shopify's image CDN ("/cdn/shop/files|products/...", on both
        // cdn.shopify.com and every merchant's own domain proxying through
        // it) resizes two independent ways: a width/height query param on an
        // otherwise identical URL (real case: the same photo staged twice as
        // "two photos" from vishalperipherals.com because the only
        // difference was a trailing "&width=1445"), and a filename suffix
        // ("_180x", "_600x600", "_grande", "_1920x@2x") that the query-param
        // strip below never touches - mirrors scripts/product-gallery-utils.mjs
        // imageAssetKey()/SHOPIFY_SIZE_SUFFIX, which must stay in sync.
        if (str_ends_with($host, 'shopify.com') || preg_match('#/cdn/shop/(?:files|products)/#i', (string) parse_url($url, PHP_URL_PATH)) === 1) {
            $url = preg_replace(
                '/_(?:\d+x\d*|pico|icon|thumb|small|compact|medium|large|grande|original|master)(?:@\dx)?(?=\.(?:jpe?g|png|webp|gif)(?:$|\?))/i',
                '',
                $url,
            ) ?: $url;
            $url = preg_replace('/[?&]width=\d+/i', '', $url) ?: $url;

            return preg_replace('/[?&]height=\d+/i', '', $url) ?: $url;
        }

        // Adobe Scene7 Dynamic Media ("/is/image/...") sizes images via wid/hei
        // query params, not the URL path - Dell and other manufacturers reuse
        // the same thumbnail URL for every gallery size selector, so the raw
        // src is often a ~90px tab icon that fails the minimum-side check.
        if (preg_match('#/is/image/#i', (string) parse_url($url, PHP_URL_PATH)) === 1) {
            $url = preg_replace('/([?&])(wid|hei)=\d+/i', '$1$2=1500', $url) ?: $url;
            $url = preg_replace('/[?&]scl=\d+/i', '', $url) ?: $url;

            // Scene7 also supports a "named modifier" query form - the whole
            // query is a single $preset$ token (e.g. Samsung's "$1164_776_PNG$")
            // instead of key=value size params. Two pages/renditions of the
            // same photo end up on this same base path with only this preset
            // token differing (and sometimes percent-encoded vs not), which
            // downstream duplicate detection was missing: perceptual hashing
            // is scale-invariant in theory but still measured a real distance
            // of 10 between two such renditions of one identical Samsung
            // product photo, above the configured near-duplicate threshold of
            // 6. Stripping the modifier token here collapses every rendition
            // of one photo to the same URL before it is ever downloaded
            // twice, and omitting it entirely asks Scene7 for the original,
            // unmodified asset - usually the largest one available.
            return preg_replace('/[?&](?:\$|%24)[^&]*(?:\$|%24)=?/i', '', $url) ?: $url;
        }

        return $url;
    }

    /**
     * Identity of the physical gallery frame, independent from its CDN
     * rendition. Unlike normalizeCandidateUrl(), this key is never fetched.
     */
    public static function imageAssetKey(string $url): string
    {
        $normalized = self::normalizeCandidateUrl($url);
        $host = Str::lower((string) parse_url($normalized, PHP_URL_HOST));

        // LDLC encodes only the requested rendition in /r705/, /r1600/, ...;
        // the remainder of the path is the physical gallery frame.
        if ($host === 'media.ldlc.com' || str_ends_with($host, '.media.ldlc.com')) {
            $normalized = preg_replace('#/r\d{2,5}/#i', '/__rendition__/', $normalized) ?: $normalized;
        }
        $normalized = preg_replace(
            '/([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})(?:_(?:\d{2,5}|sea))?\.(?:jpe?g|png|webp|gif|avif)(?=$|\?)/i',
            '$1.__image__',
            $normalized,
        ) ?: $normalized;

        // A bare "WxH" or "Nw" path segment (BigCommerce Stencil:
        // /stencil/1280x1280/products/.../file.jpg vs /stencil/640w/... same
        // file - a real case that reached production undeduped) is a size
        // bucket, not part of the asset identity, wherever it sits in the
        // path - unlike the named buckets below it is rarely adjacent to the
        // filename.
        $normalized = preg_replace(
            '#/\d{2,5}(?:x\d{2,5}|w)/#i',
            '/__rendition__/',
            $normalized,
        ) ?: $normalized;

        return Str::lower(preg_replace(
            '#/(?:thumb(?:nail)?s?|small|medium|large|xlarge|xxlarge|original)/(?=[^/?]+(?:\?|$))#i',
            '/__rendition__/',
            $normalized,
        ) ?: $normalized);
    }

    private static function candidateUrlQualityScore(string $url): int
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        $directoryScore = 0;

        if (preg_match(
            '#/(thumb(?:nail)?s?|small|medium|large|xlarge|xxlarge|original)/(?=[^/?]+(?:\?|$))#i',
            $path,
            $matches,
        ) === 1) {
            $directoryScore = match (Str::lower($matches[1])) {
                'thumb', 'thumbnail', 'thumbnails' => 100,
                'small' => 300,
                'medium' => 600,
                'large' => 1000,
                'xlarge' => 1600,
                'xxlarge' => 2200,
                'original' => 3000,
                default => 0,
            };
        }

        $uuidRenditionScore = 0;

        if (preg_match(
            '/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}_(\d{2,5}|sea)(?=\.(?:jpe?g|png|webp|gif|avif)$)/i',
            $path,
            $matches,
        ) === 1) {
            $uuidRenditionScore = Str::lower($matches[1]) === 'sea'
                ? 2000
                : (int) $matches[1];
        }

        $queryScore = 0;
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        foreach (['w', 'width', 'wid', 'h', 'height', 'hei'] as $key) {
            if (isset($query[$key]) && is_numeric($query[$key])) {
                $queryScore = max($queryScore, min(4000, (int) $query[$key]));
            }
        }

        $pathRenditionScore = preg_match('#/r(\d{2,5})/#i', $path, $matches) === 1
            ? min(4000, (int) $matches[1])
            : 0;

        $sizeSegmentScore = match (true) {
            preg_match('#/(\d{2,5})x(\d{2,5})/#i', $path, $matches) === 1 => min(4000, max((int) $matches[1], (int) $matches[2])),
            preg_match('#/(\d{2,5})w/#i', $path, $matches) === 1 => min(4000, (int) $matches[1]),
            default => 0,
        };

        return max($directoryScore, $uuidRenditionScore, $queryScore, $pathRenditionScore, $sizeSegmentScore);
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
        ?int $minimumSide = null,
        bool $confirmedGallery = false,
    ): bool
    {
        $minimum = $minimumSide ?? $this->settings->imageMinimumSide();

        if ($confirmedGallery) {
            return max($width, $height) >= $minimum
                && min($width, $height) >= 100;
        }

        $ratio = $width / max($height, 1);

        return min($width, $height) >= $minimum
            && $ratio >= (float) config('product-images.minimum_ratio', 0.28)
            && $ratio <= (float) config('product-images.maximum_ratio', 3.5);
    }

    /**
     * Trusted-source candidates skip Vision entirely, so unlike the loose
     * bounds in hasUsefulDimensions() (which Vision still reviews), this is
     * the last line of defense against banner/swatch-strip images from
     * manufacturer spec pages (e.g. a 738x270 color-swatch banner, ratio
     * 2.73 - well inside the general 0.28-3.5 bound but not a product shot).
     */
    private function looksLikeProductPhotoShape(int $width, int $height): bool
    {
        $ratio = $width / max($height, 1);

        return $ratio >= 0.5 && $ratio <= 2.2;
    }
}
