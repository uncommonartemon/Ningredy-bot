<?php

namespace App\Services\Products;

use App\Models\Product;
use App\Models\ProductDraft;
use App\Models\ProductDraftMedia;
use App\Models\ProductSourceAttempt;
use App\Models\ProductVariant;
use App\Services\Ai\AiSettings;
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
    public function stage(ProductDraft $draft, ?callable $progress = null, ?int $telegramUpdateId = null): int
    {
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
        $prioritizedSources = $this->sourcePriority->sortSources($draft->sources ?? [], $draft->brand);
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
                && ! $this->sourceExcludedForDraft($source['url'], $draft))
            ->values();

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

        foreach ($cardSources as $sourceIndex => $source) {
            if (! $this->timeBudget->canStart($telegramUpdateId, 20)) {
                $progress?->__invoke('Резерв времени достигнут: новые источники больше не открываю, завершаю текущий результат.');
                break;
            }

            if ($progress) {
                $progress('Проверяю источник '.($sourceIndex + 1).'/'.$cardSources->count().': '.$source['url']);
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
            );

            if ($sourceBlocked) {
                if ($progress) {
                    $progress('Источник пропущен: ссылка ведёт на защитную заглушку, а не на товар.');
                }

                continue;
            }

            // Browser/DOM gallery URLs are more reliable than AI-provided thumbnails.
            $urls = array_values(array_unique([...$resolvedUrls, ...$urls]));
            $allCandidates = $this->downloadCandidates($urls, $draft);
            $this->attempts->record([
                'telegram_update_id' => $telegramUpdateId,
                'product_draft_id' => $draft->id,
                'product_url' => $source['url'],
                'actor' => 'downloader',
                'phase' => 'image_download',
                'action' => 'download_candidates',
                'status' => $allCandidates !== [] ? 'completed' : 'failed',
                'decision' => $allCandidates !== [] ? 'send_to_vision' : 'skip_source',
                'input' => ['candidate_urls' => count($urls)],
                'output' => [
                    'downloaded_images' => count($allCandidates),
                    'rejected_candidates' => array_slice($this->lastDownloadRejections, 0, 20),
                ],
            ]);

            if ($allCandidates === []) {
                $progress?->__invoke('Не удалось скачать ни одного технически пригодного изображения: '.$source['url']);

                continue;
            }

            $sourceMethod = 'static';
            $selected = $this->selectFromCandidates($draft, $allCandidates, $target, $telegramUpdateId);
            $selected = $this->removeNearDuplicates($selected, $excludedHashes);
            $this->attempts->record([
                'telegram_update_id' => $telegramUpdateId,
                'product_draft_id' => $draft->id,
                'product_url' => $source['url'],
                'actor' => 'vision',
                'phase' => 'image_verification',
                'action' => 'verify_gallery',
                'status' => $selected !== [] ? 'completed' : 'failed',
                'decision' => $selected !== [] ? 'accept_images' : 'reject_source',
                'input' => ['downloaded_images' => count($allCandidates)],
                'output' => [
                    'accepted_images' => count($selected),
                    'accepted_urls' => collect($selected)->pluck('source_url')->values()->all(),
                ],
            ]);
            $this->destroyUnselected($allCandidates, $selected);

            // A confirmed carousel/slider page can pass its AI preflight as
            // "static_sufficient" and still yield fewer than 2 genuinely
            // distinct photos (e.g. the same shot at two CDN-resized URLs) -
            // that preflight call is cheap compared to a wasted source, so
            // retry once by forcing real Playwright interaction instead of
            // accepting the shortfall or moving straight to the next source.
            if (count($selected) < 2) {
                $progress?->__invoke('Мало разных фото после статичного анализа; принудительно прохожу интерактивную галерею: '.$source['url']);
                $forcedUrls = $this->resolver->resolve(
                    [$source],
                    max(8, $target * 2),
                    function (string $level, string $message) use ($progress): void {
                        $progress?->__invoke($message);
                    },
                    $telegramUpdateId,
                    forceInteractive: true,
                );
                $forcedCandidates = $this->downloadCandidates(
                    array_values(array_unique([...$forcedUrls, ...$urls])),
                    $draft,
                );

                if ($forcedCandidates !== []) {
                    $forcedSelected = $this->selectFromCandidates($draft, $forcedCandidates, $target, $telegramUpdateId);
                    $forcedSelected = $this->removeNearDuplicates($forcedSelected, $excludedHashes);
                    $this->destroyUnselected($forcedCandidates, $forcedSelected);

                    if (count($forcedSelected) > count($selected)) {
                        $this->destroy($selected);
                        $selected = $forcedSelected;
                        $sourceMethod = 'playwright';
                    } else {
                        $this->destroy($forcedSelected);
                    }
                }
            }

            if ($selected !== []) {
                $isPartial = count($selected) < 2 || collect($selected)
                    ->every(fn (array $candidate): bool => (bool) ($candidate['partial_gallery'] ?? false));

                if ($isPartial) {
                    if (count($selected) > count($partialSelected)) {
                        $this->destroy($partialSelected);
                        $partialSelected = $selected;
                        $partialSource = $source;
                        $partialMethod = $sourceMethod;
                        $partialFromDiscovery = false;
                    } else {
                        $this->destroy($selected);
                    }
                    $selected = [];
                    $progress?->__invoke('Источник дал только частичный проверенный результат; сохраняю его в резерв и продолжаю: '.$source['url']);

                    continue;
                }

                $this->destroy($partialSelected);
                $partialSelected = [];
                $chosenSource = $source;
                $chosenMethod = $sourceMethod;
                break;
            }

            $progress?->__invoke('Vision отклонил все скачанные изображения этой страницы: '.$source['url']);
        }

        if (
            $selected === []
            && $this->settings->fallbackSourcesEnabled()
            && $this->timeBudget->canStart($telegramUpdateId, 30)
        ) {
            if ($progress) {
                $progress('Галереи указанных карточек не подошли. Автоматически ищу другие магазины в пределах оставшегося бюджета времени.');
            }
            $knownUrls = $this->cleanUrls([
                ...($draft->image_urls ?? []),
                ...($draft->excluded_gallery_image_urls ?? []),
                ...$cardSources->flatMap(fn (array $source): array => $source['image_urls'] ?? [])->all(),
            ]);
            [$discoveredCandidates] = $this->discoverCandidates($draft, $knownUrls, true, $progress, $telegramUpdateId);
            $candidateGroups = collect($discoveredCandidates)
                ->groupBy(function (array $candidate): string {
                    $pageUrl = $candidate['page_source_url'] ?? null;

                    if (is_string($pageUrl) && filter_var($pageUrl, FILTER_VALIDATE_URL)) {
                        return 'page:'.$pageUrl;
                    }

                    return 'host:'.(ProductSourcePriority::host((string) ($candidate['source_url'] ?? '')) ?: 'unknown');
                });

            foreach ($candidateGroups as $groupKey => $group) {
                $groupPageUrl = str_starts_with((string) $groupKey, 'page:')
                    ? substr((string) $groupKey, 5)
                    : null;
                $host = $groupPageUrl ?: substr((string) $groupKey, 5);
                if ($progress) {
                    $progress("Проверяю найденную галерею: {$host}.");
                }
                $groupCandidates = $group->values()->all();
                $verified = $this->selectFromCandidates($draft, $groupCandidates, $target, $telegramUpdateId);
                $selected = $this->removeNearDuplicates($verified, $excludedHashes);

                if ($selected !== []) {
                    if (count($selected) < 2) {
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

                    if (! $partialFromDiscovery) {
                        $this->destroy($partialSelected);
                    }
                    $partialSelected = [];
                    $chosenSource = $groupPageUrl ? ['url' => $groupPageUrl] : null;
                    $chosenMethod = 'fallback_discovery';
                    break;
                }
            }

            if ($selected === [] && $partialSelected !== []) {
                $selected = $partialSelected;
                $chosenSource = $partialSource;
                $chosenMethod = $partialMethod;
            }
            $this->destroyUnselected($discoveredCandidates, $selected);
        } elseif ($selected === [] && $progress) {
            $reason = $this->settings->fallbackSourcesEnabled()
                ? 'Резерв времени достигнут; дополнительный поиск источников не запускаю.'
                : 'Резервные источники выключены в настройках; дополнительный поиск не запускаю.';
            $progress($reason);
        }

        if ($selected === [] && $partialSelected !== []) {
            $selected = $partialSelected;
            $chosenSource = $partialSource;
            $chosenMethod = $partialMethod;
        }

        $galleryIsPartial = $selected !== [] && (
            count($selected) < 2
            || collect($selected)->every(fn (array $candidate): bool => (bool) ($candidate['partial_gallery'] ?? false))
        );
        $roles = ['primary', 'secondary', 'detail'];
        $stored = 0;
        $checksums = [];
        $reusedMediaIds = [];

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
                        'verification_status' => 'verified',
                        'verification_score' => isset($candidate['vision_score']) ? $candidate['vision_score'] / 100 : null,
                        'verification_model' => $candidate['vision_model'] ?? null,
                        'verification_notes' => $candidate['vision_reason'] ?? null,
                        'sort_order' => $stored,
                        'is_primary' => $stored === 0,
                    ]);
                    $reusedMediaIds[] = $existingMedia->id;
                    $checksums[$checksum] = true;
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
                    'verification_status' => 'verified',
                    'verification_score' => isset($candidate['vision_score']) ? $candidate['vision_score'] / 100 : null,
                    'verification_model' => $candidate['vision_model'] ?? null,
                    'verification_notes' => $candidate['vision_reason'] ?? null,
                    'sort_order' => $stored,
                    'is_primary' => $stored === 0,
                ]);
                $checksums[$checksum] = true;
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

        $draft->update([
            'primary_source_url' => $chosenSource['url'] ?? $draft->primary_source_url,
            'gallery_status' => $stored > 0
                ? ($galleryIsPartial ? 'partial' : 'complete')
                : ($previousMedia->isNotEmpty() ? $draft->gallery_status : 'missing'),
            'gallery_notes' => $stored > 0 && $galleryIsPartial
                ? 'После полного цикла поиска сохранён лучший частичный результат: '.$stored.' проверенных фото.'
                : null,
            'images_staged_at' => now(),
        ]);

        if ($progress) {
            if ($stored > 0) {
                $methodLabel = match ($chosenMethod) {
                    'playwright' => 'через AI-переобучение Playwright-рецепта',
                    'fallback_discovery' => 'через резервный поиск (источник не из карточек AI-исследования)',
                    default => 'из статичной HTML-галереи карточки',
                };
                $host = is_array($chosenSource) && is_string($chosenSource['url'] ?? null)
                    ? (ProductSourcePriority::host($chosenSource['url']) ?: $chosenSource['url'])
                    : null;
                $progress(
                    "📦 Итог: {$stored} фото {$methodLabel}".($host ? " ({$host})" : '')
                    .($galleryIsPartial ? ' · частичный результат' : '').'.',
                );
            } else {
                $progress('📦 Итог: подходящая галерея не найдена ни по одному источнику.');
            }
        }

        return $stored;
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
            ->unique()
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
        $this->lastDownloadRejections = [];

        foreach ($urls as $originalUrl) {
            if (! is_string($originalUrl)) {
                continue;
            }

            $pageUrl = $this->candidateDiscovery->sourcePageForImage($originalUrl);

            if ($pageUrl) {
                $sourcePagesByUrl[self::normalizeCandidateUrl($originalUrl)] = $pageUrl;
            }
        }

        $urls = array_values(array_diff(
            $this->cleanUrls($urls),
            $this->cleanUrls($draft->excluded_gallery_image_urls ?? []),
        ));
        $urls = $this->sourcePriority->sortUrls($urls, $draft->brand, $draft->sources ?? [], $sourcePagesByUrl);

        foreach ($urls as $url) {
            if (count($candidates) >= $limit) {
                break;
            }

            $failureReason = null;
            $download = $this->resolver->download($url, failureReason: $failureReason);

            if (! $download) {
                $this->lastDownloadRejections[] = ['url' => $url, 'reason' => $failureReason ?? 'unknown'];

                continue;
            }

            $minimumSide = (($download['confirmed_gallery'] ?? false) || ($download['partial_gallery'] ?? false))
                ? (int) config('product-images.browser_fallback.confirmed_gallery_minimum_side', 400)
                : null;

            if (! $this->hasUsefulDimensions($download['width'], $download['height'], $minimumSide)) {
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

            $candidates[] = [
                ...$download,
                'page_source_url' => $sourcePagesByUrl[$url]
                    ?? $this->candidateDiscovery->sourcePageForImage($url),
                'image' => $image,
            ];
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
    ): array {
        $discovered = ($skipKnownSources || $progress)
            ? $this->candidateDiscovery->find($draft, $existingUrls, $skipKnownSources, $progress, $telegramUpdateId)
            : $this->candidateDiscovery->find($draft, $existingUrls, telegramUpdateId: $telegramUpdateId);
        $newUrls = array_values(array_diff($this->cleanUrls($discovered), $existingUrls));

        if ($discovered !== []) {
            $draft->update(['image_urls' => array_values(array_unique([
                ...($draft->image_urls ?? []),
                ...$discovered,
            ]))]);
        }

        return [$this->downloadCandidates($newUrls, $draft), true];
    }

    /**
     * Every publishable image goes through the same visual identity and quality check.
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
            // Once Vision has confirmed at least one photo from this page's
            // Playwright-confirmed gallery/slider, the rest of that same
            // gallery is trusted without a separate Vision call - retail
            // sites essentially never mix different products into one
            // product-page gallery, and the operator still reviews every
            // draft before it publishes. Scoped to a single verify() call
            // (one source's candidates), never across sources/pages.
            $galleryTrusted = false;

            foreach (array_slice(array_chunk($candidates, $batchSize), 0, $maxBatches) as $batch) {
                $needed = $remaining - count($selected);

                if ($needed <= 0) {
                    break;
                }

                if ($galleryTrusted) {
                    $trusted = collect($batch)
                        ->filter(fn (array $candidate): bool => $candidate['confirmed_gallery'] ?? false)
                        ->map(fn (array $candidate): array => [
                            ...$candidate,
                            'vision_reason' => 'Trusted without a separate Vision check: part of the same '
                                .'Playwright-confirmed gallery as an already Vision-verified photo of this product.',
                        ])
                        ->take($needed)
                        ->values()
                        ->all();
                    $selected = [...$selected, ...$trusted];

                    continue;
                }

                $verifiedBatch = $this->visionVerifier->select($draft, $batch, $needed, $telegramUpdateId);
                $selected = [...$selected, ...$verifiedBatch];
                $galleryTrusted = collect($verifiedBatch)->contains(fn (array $candidate): bool => $candidate['confirmed_gallery'] ?? false);
            }

            return $selected;
        } catch (Throwable $exception) {
            $this->destroy($candidates);

            throw $exception;
        }
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

    private function hasUsefulDimensions(int $width, int $height, ?int $minimumSide = null): bool
    {
        $ratio = $width / max($height, 1);

        return min($width, $height) >= ($minimumSide ?? (int) config('product-images.minimum_side', 320))
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
