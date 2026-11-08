<?php

namespace App\Services\Products;

use App\Exceptions\ProductSearchCancelled;
use App\Models\Category;
use App\Models\ProductDraft;
use App\Services\Ai\AiSettings;
use App\Services\Ai\ProductSearchCancellation;
use App\Services\Ai\ProductSearchTimeBudget;
use App\Services\Photos\ProductPhotoPolicy;
use App\Services\Vision\VisionCallContext;
use App\Services\Vision\VisionImagePreparer;
use App\Services\Vision\VisionResponse;
use App\Services\Vision\VisionService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Throwable;

class ProductImageVisionVerifier
{
    public function __construct(
        private readonly ProductIdentityMatcher $identityMatcher,
    ) {}

    /**
     * @param  array<int, array<string, mixed>>  $candidates
     * @return array<int, array<string, mixed>>
     */
    public function select(
        ProductDraft $draft,
        array $candidates,
        int $limit,
        ?int $telegramUpdateId = null,
    ): array {
        return $this->selectWithPolicy($draft, $candidates, $limit, $telegramUpdateId, false);
    }

    /**
     * Reviews every frame of an ambiguous Playwright carousel in one request.
     * Unusual angles, details and lifestyle shots stay when the product is the
     * clear subject; feature slides and internal illustrations do not. Several
     * views of one product in a clean frame are a product photo (operator, 2026-10-25).
     *
     * @param  array<int, array<string, mixed>>  $candidates
     * @return array<int, array<string, mixed>>
     */
    public function selectGalleryFrames(
        ProductDraft $draft,
        array $candidates,
        int $limit,
        ?int $telegramUpdateId = null,
    ): array {
        return $this->selectWithPolicy($draft, $candidates, $limit, $telegramUpdateId, true);
    }

    /**
     * @param  array<int, array<string, mixed>>  $candidates
     * @return array<int, array<string, mixed>>
     */
    private function selectWithPolicy(
        ProductDraft $draft,
        array $candidates,
        int $limit,
        ?int $telegramUpdateId,
        bool $galleryFramePolicy,
        ?array &$verdicts = null,
    ): array {
        if ($limit <= 0 || $candidates === []) {
            return [];
        }

        $maxCandidates = $galleryFramePolicy
            ? 40
            : (int) config('product-images.vision_candidates', 4);
        $minimumScore = $galleryFramePolicy
            ? 0
            : (int) config('product-images.vision_min_score', 60);
        $candidates = array_slice($candidates, 0, $maxCandidates);
        $candidates = array_map(function (array $candidate) use ($draft): array {
            $pageContext = is_array($candidate['page_source_context'] ?? null)
                ? $candidate['page_source_context']
                : null;
            // The exact tier only - a shared family/chassis fragment must
            // not grant source_identity_confirmed here either. Upstream
            // (ProductImageStorage) may have already overwritten this flag
            // with its own judge-backed, source-level decision (stage()'s
            // main per-source loop does); the || below preserves that, but
            // this local, judge-free fallback must not hand it out on a
            // weaker basis than upstream required.
            $pageIdentityConfirmed = $pageContext !== null && (
                ProductPhotoApplicability::isAccepted($draft, $pageContext, $pageContext['_photo_applicability'] ?? [])
                || (! $this->identityMatcher->conflictsSource($draft, $pageContext)
                    && $this->identityMatcher->confirmsExactIdentifier($draft, $pageContext))
            );
            // A page whose text names the line with nothing conflicting (operator,
            // 2026-10-29): Vision no longer has to prove the exact suffix from pixels.
            $pageLinePlausible = $pageContext !== null
                && ProductPhotoApplicability::isPlausibleLine($draft, $pageContext, $pageContext['_photo_applicability'] ?? []);

            return [
                ...$candidate,
                'product_page_url' => $candidate['product_page_url']
                    ?? ($pageContext['url'] ?? $candidate['page_source_url'] ?? null),
                'source_identity_confirmed' => (bool) ($candidate['source_identity_confirmed'] ?? false)
                    || $pageIdentityConfirmed,
                'source_line_plausible' => (bool) ($candidate['source_line_plausible'] ?? false) || $pageLinePlausible,
                // The maker's own page or a record matched by code shows the product it names;
                // a reseller's page shows whatever picture the reseller had.
                'maker_page' => $pageContext !== null && (SourceIdentityProvenance::confirmedByCode($pageContext)
                    || SourceIdentityProvenance::fromCatalog($pageContext)
                    || (bool) ($pageContext['_brand_catalog'] ?? false)
                    || ($pageContext['type'] ?? null) === 'manufacturer'),
            ];
        }, $candidates);
        // Operator, 2026-11-04: a component is identified by the markings on it, not by
        // the page it was pictured on. Live #379: a BC501 request published a PC801 drive
        // from a reseller page whose address carried the BC501 code; #380/#384: modules
        // and drives of other models came from "related products" of pages judged exact.
        $labelIdentity = ProductPhotoIdentity::fromLabel($draft);
        $settings = app(AiSettings::class);
        $timeBudget = app(ProductSearchTimeBudget::class);

        if (! $timeBudget->canStart($telegramUpdateId ?? $draft->telegram_update_id, 15)) {
            if ($galleryFramePolicy) {
                throw new \RuntimeException('Current gallery verification interrupted: insufficient time budget.');
            }

            return [];
        }

        $visionTimeout = $timeBudget->timeoutFor(
            $telegramUpdateId ?? $draft->telegram_update_id,
            $settings->imageVisionTimeoutSeconds(),
        );
        $provider = $settings->providerFor('product_image_vision');
        $model = $settings->modelFor('product_image_vision');
        $categoryHint = trim((string) Category::query()
            ->where('slug', trim((string) $draft->category))
            ->value('product_search_hint'));
        $prompt = $this->prompt($draft, $candidates, $categoryHint, $galleryFramePolicy, $labelIdentity);
        // A label is read only on a frame large and detailed enough to read it.
        $attachments = array_map(fn (array $candidate, int $index) => app(VisionImagePreparer::class)->thumbnail(
            $candidate['image'],
            'candidate-'.($index + 1).'.webp',
            $galleryFramePolicy || $labelIdentity ? 'high' : (string) config('product-images.vision_detail', 'low'),
            $galleryFramePolicy ? 2048 : ($labelIdentity ? 1600 : 640),
        ), $candidates, array_keys($candidates));

        // The mini model occasionally returns a structurally invalid batch -
        // a missing required field (e.g. "reason") or the wrong number of
        // image entries - that the enum/length coercion inside
        // reviewGalleryBatch() cannot repair. Real production case
        // (2026-08-26): a genuine Rozetka gallery review failed exactly
        // this way and, before this retry existed, permanently wrote off an
        // otherwise-good gallery (and crashed the whole multi-source
        // search) over one bad model response. One retry mirrors the same
        // self-correction ProductGalleryRecipeTrainer already gets across
        // its own invalid-JSON training rounds.
        $maxAttempts = 2;
        $data = null;
        $lastException = null;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            if ($attempt > 1) {
                if (! $timeBudget->canStart($telegramUpdateId ?? $draft->telegram_update_id, 15)) {
                    break;
                }
                $visionTimeout = $timeBudget->timeoutFor(
                    $telegramUpdateId ?? $draft->telegram_update_id,
                    $settings->imageVisionTimeoutSeconds(),
                );
            }
            try {
                $data = $this->reviewGalleryBatch(
                    $provider,
                    $model,
                    $prompt,
                    $attachments,
                    $visionTimeout,
                    $telegramUpdateId ?? $draft->telegram_update_id,
                    count($candidates),
                    $minimumScore,
                );
                if (($data['validation_issues'] ?? []) !== []) {
                    throw new \UnexpectedValueException(implode(' ', $data['validation_issues']));
                }
                $lastException = null;

                break;
            } catch (ProductSearchCancelled $exception) {
                throw $exception;
            } catch (Throwable $exception) {
                $lastException = $exception;
                // Return the actual contract failure on the bounded repair,
                // instead of paying to repeat the same blind request.
                $prompt .= PHP_EOL.json_encode(['validation_feedback' => mb_substr($exception->getMessage(), 0, 1500)], JSON_UNESCAPED_UNICODE);
            }
        }

        if ($lastException !== null) {
            report($lastException);
            // An unresolved frame must not erase other valid reviews in
            // the same paid batch. Preserve only non-contradictory frames;
            // if none remain, this is interruption, not a negative verdict.
            $consistent = array_values(array_filter($data['images'] ?? [],
                fn (array $review): bool => ! isset($data['validation_issues'][$review['index']])));
            if ($consistent === []) {
                throw $lastException;
            }
            $data['images'] = $consistent;
        }

        $blindReadings = $labelIdentity
            ? $this->blindLabelReadings($draft, $data['images'], $candidates, $attachments, $provider, $model, $telegramUpdateId ?? $draft->telegram_update_id)
            : [];
        $reviewed = collect($data['images'])
            ->map(function (array $review) use ($draft, $candidates, $labelIdentity, $blindReadings): array {
                $candidate = $candidates[$review['index'] - 1] ?? [];
                $sourceSupported = (bool) ($candidate['source_identity_confirmed'] ?? false)
                    || (bool) ($candidate['source_line_plausible'] ?? false)
                    || $this->identityMatcher->supports($draft, (string) ($candidate['source_url'] ?? ''));
                // Operator, 2026-10-29: Vision does not judge model exactness from pixels. A frame
                // refused only for an unconfirmed SKU or suffix, with nothing visible against it, is
                // the page's call: when the page names the line, the frame stands (live #362: four
                // clean silver CB514-4HT frames went out as "applicability to -375Z unconfirmed"
                // and the card ended with one photo). The doubt arrives as data, never parsed from prose.
                $identityDoubtOnly = ! $labelIdentity && ! $review['publishable']
                    && ($review['rejection_reason'] ?? null) === 'identity'
                    && $sourceSupported && $review['color_match']
                    && ($review['physical_match'] ?? null) !== 'conflicting';
                // What the label in the frame says is compared on the server, by code, not
                // by the reviewer's feeling: another code is another product (live #379).
                $reviewerMarking = trim((string) ($review['observed_marking'] ?? ''));
                $blind = $blindReadings[$review['index']] ?? null;
                $reviewerVerdict = $labelIdentity ? ProductPhotoIdentity::markingVerdict($draft, $reviewerMarking) : 'none';
                $markingVerdict = $blind === null ? $reviewerVerdict : ProductPhotoIdentity::blindVerdict($draft, $blind['marking'], $blind['brand']);
                // Where the reader agrees, the reviewer's spelling stands: the reader's may hold a slip.
                $observedMarking = $blind === null || $markingVerdict === $reviewerVerdict ? $reviewerMarking : trim($blind['brand'].' '.$blind['marking']);
                // The reviewer refuses a label that is not the requested code letter for letter;
                // the comparison above has settled that a sibling or the product's own name is
                // this part (live #402: the drive's real label, HFM512GDJTNG-8310 BA against
                // -8310A, was refused and its back with no code went out). A different form
                // factor is reported as body, not identity, and stays refused.
                // #203 at the colleague's: the label read M393A4K40BB1-CRC0Q against a requested
                // -CRCQ0, the comparison said match, and the reviewer's refusal still stood.
                $labelDoubtOnly = in_array($markingVerdict, ['match', 'sibling', 'name'], true) && ! $review['publishable']
                    && ($review['rejection_reason'] ?? null) === 'identity';
                // With no code read, a component frame is not exact however sure the reviewer
                // felt from the page (live #390: «источник подтверждает модель» with an empty
                // marking went out as exact); it is the maker's page, a labelled gallery
                // neighbour or the last resort that may still carry it.
                $exactMatch = match ($markingVerdict) {
                    'conflict' => false,
                    'match' => true,
                    // The name is on the frame; whether it is enough for this part is the reviewer's call.
                    'name' => (bool) $review['exact_match'],
                    // A logo carries no label by nature; the component logo policy judges it by brand.
                    default => (! $labelIdentity || ($review['kind'] ?? '') === 'logo') && (bool) $review['exact_match'],
                };

                return [
                    ...$review,
                    'exact_match' => $exactMatch,
                    'reviewer_exact_match' => (bool) $review['exact_match'],
                    'publishable' => $markingVerdict === 'conflict' ? false : ($review['publishable'] || $identityDoubtOnly || $labelDoubtOnly),
                    'rejection_reason' => $markingVerdict === 'conflict' ? 'identity' : ($labelDoubtOnly ? 'none' : ($review['rejection_reason'] ?? 'none')),
                    ...($labelDoubtOnly ? ['physical_match' => 'match'] : []),
                    'identity_doubt_overruled' => $identityDoubtOnly,
                    'hero' => $review['kind'] === 'product'
                        && in_array($review['view'], ['front', 'angle'], true),
                    'source_supported' => $sourceSupported,
                    'observed_marking' => $observedMarking,
                    'reviewer_marking' => $reviewerMarking,
                    'marking_verdict' => $markingVerdict,
                    'maker_page' => (bool) ($candidate['maker_page'] ?? false),
                ];
            });
        if ($labelIdentity) {
            $this->journalLabels($draft, $reviewed, $candidates, $telegramUpdateId ?? $draft->telegram_update_id);
        }
        if ($labelIdentity && $galleryFramePolicy) {
            // A product's own gallery shows one item from several sides: the frame without a
            // readable code next to a frame whose code matched (the drive from its connector
            // side) is that item. A page's static pictures get no such support - the pictures
            // of «related products» live on the same page (live #380, #384).
            $pageOf = fn (array $review): string => (string) ($candidates[$review['index'] - 1]['page_source_context']['url'] ?? '');
            $labelledPages = $reviewed
                ->filter(fn (array $review): bool => in_array($review['marking_verdict'], ['match', 'sibling'], true)
                    || ($review['marking_verdict'] === 'name' && $review['exact_match']))
                ->map($pageOf)->filter()->unique();
            $reviewed = $reviewed->map(fn (array $review): array => $review['marking_verdict'] === 'none' && $review['reviewer_exact_match']
                && $review['publishable'] && $pageOf($review) !== '' && $labelledPages->contains($pageOf($review))
                    ? [...$review, 'exact_match' => true, 'labelled_neighbour' => true]
                    : $review);
        }

        $allowedKinds = $galleryFramePolicy
            ? ['product', 'packaging', 'detail', 'banner']
            : ['product', 'packaging', 'detail'];
        if (ProductPhotoPolicy::allowsComponentLogos($draft)) {
            $allowedKinds[] = 'logo';
        }
        $operatorRequiresPhotoLayout = ProductPhotoKeyboardContext::explicitlyRequiresPhotos(ProductInputContext::forDraft($draft));
        $qualifies = fn (array $review): bool => $review['publishable']
            && ($review['catalog_photo'] ?? true)
            && ($review['physical_match'] ?? null) !== 'conflicting'
            && $this->keyboardReviewAllows($review, $operatorRequiresPhotoLayout)
            && (! filled($draft->color) || $review['color_match'])
            && in_array($review['kind'], $allowedKinds, true)
            && ($galleryFramePolicy || $review['score'] >= $minimumScore)
            // A component is proven by its own marking, or shown by its maker's own page or
            // record; a reseller's page only supports a frame whose label could be read.
            && ($labelIdentity
                ? ($review['exact_match'] || $review['marking_verdict'] === 'sibling' || ($review['source_supported'] && $review['maker_page']))
                : ($review['exact_match'] || $review['source_supported']));

        $reviews = $this->rankReviews($reviewed->filter(fn (array $review): bool => $qualifies($review)));
        // A card with no photo is a failure for the operator. When no frame of a component
        // proved its identity, the best clean frame from a page with the exact code goes
        // out as the one unproven photo, said so in its reason - a readable other code
        // never does (that frame was refused above).
        if ($labelIdentity && $reviews->isEmpty()) {
            $reviews = $this->rankReviews($reviewed->filter(fn (array $review): bool => $review['publishable']
                && ($review['catalog_photo'] ?? true) && ($review['physical_match'] ?? null) !== 'conflicting'
                && $review['source_supported'] && in_array($review['marking_verdict'], ['none', 'name'], true)
                && in_array($review['kind'], $allowedKinds, true) && ($galleryFramePolicy || $review['score'] >= $minimumScore)))
                ->take(1)->map(fn (array $review): array => [...$review, 'identity_unproven' => true]);
        }

        // Missing or invalid entries are unresolved, not negative reviews.
        // Keep explicit verdicts separate from the selected array so a later
        // failed batch cannot erase earlier work or reject unseen frames.
        $verdicts = ['reviews' => $reviewed->keyBy('index')->all(),
            'interruption_reason' => $lastException?->getMessage()];

        return $reviews->take($limit)->map(function (array $review) use ($candidates, $model): array {
            return [
                ...$candidates[$review['index'] - 1],
                'vision_kind' => $review['kind'],
                'vision_score' => $review['score'],
                'vision_reason' => match (true) {
                    (bool) ($review['identity_doubt_overruled'] ?? false) => 'Принят по странице-источнику: она называет эту линейку, '
                        .'а Vision сомневался только в точном суффиксе модели. '.$review['reason'],
                    (bool) ($review['identity_unproven'] ?? false) => 'Единственное фото без прочитанного кода: взято со страницы с точным кодом, '
                        .'потому что ни один кадр не подтвердил деталь по наклейке. '.$review['reason'],
                    ($review['marking_verdict'] ?? 'none') === 'match' => 'Код на наклейке совпадает с запрошенным: '.$review['observed_marking'].'. '.$review['reason'],
                    ($review['marking_verdict'] ?? 'none') === 'sibling' => 'На наклейке родственный код '.$review['observed_marking']
                        .' (та же модель, другая партия или суффикс). '.$review['reason'],
                    ($review['marking_verdict'] ?? 'none') === 'name' => 'На фото название этой модели: '.$review['observed_marking'].'; кода на наклейке нет. '.$review['reason'],
                    (bool) ($review['labelled_neighbour'] ?? false) => 'Код на этом кадре не прочитан, но в той же галерее товара есть кадр с совпавшим кодом. '.$review['reason'],
                    $review['source_supported'] && ! $review['exact_match'] && ($review['maker_page'] ?? false) => 'Фото со страницы производителя или из записи по коду; код на фото не прочитан. '.$review['reason'],
                    $review['source_supported'] && ! $review['exact_match'] => 'Exact identity is supported by the source URL. '.$review['reason'],
                    default => $review['reason'],
                },
                'vision_model' => $model,
                'photo_visual_review' => [
                    'color_match' => $review['color_match'],
                    'physical_match' => $review['physical_match'] ?? 'not_observable',
                    'color_observability' => $review['color_observability'] ?? 'uncertain',
                    'keyboard_visibility' => $review['keyboard_visibility'] ?? 'unknown',
                    'keyboard_layout_match' => $review['keyboard_layout_match'] ?? 'unknown',
                    'keyboard_requirement_source' => $review['keyboard_requirement_source'] ?? 'unknown',
                    'keyboard_requirement_scope' => $review['keyboard_requirement_scope'] ?? 'unknown',
                    'keyboard_layout' => $review['keyboard_layout'] ?? '',
                    'keyboard_layout_note' => $review['keyboard_layout_note'] ?? '',
                    'configuration_marking' => trim((string) ($review['configuration_marking'] ?? '')),
                    'observed_marking' => $review['observed_marking'] ?? '',
                    'marking_verdict' => $review['marking_verdict'] ?? 'none',
                    'identity_unproven' => (bool) ($review['identity_unproven'] ?? false),
                    'labelled_neighbour' => (bool) ($review['labelled_neighbour'] ?? false),
                    'source_url' => $candidates[$review['index'] - 1]['source_url'] ?? null,
                ],
            ];
        })->all();
    }

    /**
     * What each label said against the request. Without this a refusal looks like nothing
     * anywhere: live #409 lost ten good frames as «no verified frames», and the cause showed
     * only when the model's answers were set beside the server's verdicts by hand.
     *
     * @param  Collection<int, array<string, mixed>>  $reviewed
     * @param  array<int, array<string, mixed>>  $candidates
     */
    private function journalLabels(ProductDraft $draft, Collection $reviewed, array $candidates, ?int $telegramUpdateId): void
    {
        $read = $reviewed->filter(fn (array $review): bool => $review['marking_verdict'] !== 'none' || $review['reviewer_marking'] !== '')->values();
        if ($read->isEmpty()) {
            return;
        }
        $this->recorder()->record([
            'telegram_update_id' => $telegramUpdateId, 'product_draft_id' => $draft->id,
            'product_url' => (string) ($candidates[0]['product_page_url'] ?? ''),
            'actor' => 'server', 'phase' => 'image_verification', 'action' => 'compare_labels', 'status' => 'info',
            'decision' => 'compared',
            'message' => $read->map(fn (array $review): string => 'кадр '.$review['index'].': «'.$review['observed_marking'].'» — '.$review['marking_verdict'])->implode('; '),
            'output' => ['frames' => $read->map(fn (array $review): array => [
                'index' => $review['index'],
                'source_url' => (string) ($candidates[$review['index'] - 1]['source_url'] ?? ''),
                'reviewer_marking' => $review['reviewer_marking'],
                'read_marking' => $review['observed_marking'],
                'verdict' => $review['marking_verdict'],
                'reviewer_exact' => $review['reviewer_exact_match'],
                'publishable' => $review['publishable'],
            ])->all()],
        ]);
    }

    private function recorder(): ProductSourceAttemptRecorder
    {
        return app(ProductSourceAttemptRecorder::class);
    }

    /**
     * A reviewer told the requested code can see it where it is not printed (live #420:
     * «маркировка MQ04ABF100 видна на наклейке» on a drive labelled MDD, the shop's picture
     * of another product). A frame that would pass on its label is read again by a reader
     * who is not told what is wanted, and the server compares that reading. Frames of the
     * maker's own page do not depend on their label. A failed reading leaves the reviewer's.
     *
     * @param  array<int, array<string, mixed>>  $images
     * @param  array<int, array<string, mixed>>  $candidates
     * @param  array<int, mixed>  $attachments
     * @return array<int, array{brand: string, marking: string}> by the frame's number in the batch
     */
    private function blindLabelReadings(ProductDraft $draft, array $images, array $candidates, array $attachments, string $provider, string $model, ?int $telegramUpdateId): array
    {
        if (! config('product-images.blind_label_read', true)) {
            return [];
        }
        $frames = collect($images)->filter(fn (array $image): bool => ! ($candidates[$image['index'] - 1]['maker_page'] ?? false)
            && in_array($image['kind'] ?? '', ['product', 'detail', 'packaging'], true)
            && ($image['publishable'] || ($image['rejection_reason'] ?? null) === 'identity')
            && in_array(ProductPhotoIdentity::markingVerdict($draft, (string) ($image['observed_marking'] ?? '')), ['match', 'sibling', 'name'], true))->values();
        if ($frames->isEmpty()) {
            return [];
        }
        try {
            $read = app(VisionService::class)->readLabels(
                $frames->map(fn (array $image): mixed => $attachments[$image['index'] - 1])->all(),
                new VisionCallContext($telegramUpdateId, provider: $provider, model: $model),
            );
        } catch (ProductSearchCancelled $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);

            return [];
        }
        $readings = [];
        foreach ((array) ($read['images'] ?? []) as $reading) {
            $frame = is_array($reading) ? $frames->get((int) ($reading['index'] ?? 0) - 1) : null;
            if ($frame !== null) {
                $readings[$frame['index']] = ['brand' => trim((string) ($reading['brand'] ?? '')), 'marking' => trim((string) ($reading['marking'] ?? ''))];
            }
        }

        return $readings;
    }

    /**
     * One attempt at reviewing one batch - its own AiRun row per attempt, so
     * a retry never overwrites an earlier attempt's own completed/failed
     * status (the exact misattribution bug found and fixed in
     * ResearchProduct.php the same day this retry was added).
     *
     * @param  array<int, mixed>  $attachments
     * @return array{images: array<int, array<string, mixed>>}
     */
    private function reviewGalleryBatch(
        string $provider,
        string $model,
        string $prompt,
        array $attachments,
        int $visionTimeout,
        ?int $telegramUpdateId,
        int $candidateCount,
        int $minimumScore,
    ): array {
        return app(VisionService::class)->reviewGallery(
            $prompt,
            $attachments,
            new VisionCallContext($telegramUpdateId, $visionTimeout, provider: $provider, model: $model),
            function (array $normalizedResponse) use ($candidateCount, $minimumScore): VisionResponse {
                $normalizedResponse['images'] = collect($normalizedResponse['images'] ?? [])
                    ->filter(fn (mixed $image): bool => is_array($image))
                    ->map(function (array $image) use ($candidateCount): array {
                        if (is_string($image['reason'] ?? null)) {
                            $image['reason'] = mb_substr($image['reason'], 0, 1000);
                        }
                        // gallery_rank only orders accepted frames. A rank past the set is a
                        // slip in that field, not a failed examination - failing the whole
                        // review over it bought the same review again (live #104, #166, #183:
                        // "gallery_rank must be between 1 and N", one retry each).
                        if (is_numeric($image['gallery_rank'] ?? null)) {
                            $image['gallery_rank'] = max(1, min($candidateCount, (int) $image['gallery_rank']));
                        }

                        // The model occasionally free-associates a plausible but
                        // out-of-enum value for one of these two fixed-vocabulary
                        // fields (e.g. "lifestyle" instead of a listed kind).
                        // Failing validation over it would discard the entire
                        // already-paid review - and every already-downloaded
                        // photo - for a single cosmetic classification miss, so
                        // fall back to the deliberately noncommittal enum member;
                        // "uncertain" is excluded from every allowed-kind list
                        // downstream, so this only drops that one image instead
                        // of the whole batch.
                        if (! in_array($image['kind'] ?? null, ['product', 'packaging', 'detail', 'logo', 'banner', 'screenshot', 'unrelated', 'uncertain'], true)) {
                            $image['kind'] = 'uncertain';
                        }

                        if (! in_array($image['view'] ?? null, ['front', 'angle', 'side', 'back', 'detail', 'packaging', 'other'], true)) {
                            $image['view'] = 'other';
                        }

                        return $image;
                    })
                    ->filter(fn (array $image): bool => is_int($image['index'] ?? null)
                        && $image['index'] >= 1
                        && $image['index'] <= $candidateCount)
                    ->unique('index')
                    ->sortBy('index')
                    ->take($candidateCount)
                    ->values()
                    ->all();
                $data = Validator::make($normalizedResponse, [
                    'images' => ['required', 'array', 'size:'.$candidateCount],
                    'images.*.index' => ['required', 'integer', 'between:1,'.$candidateCount, 'distinct'],
                    'images.*.exact_match' => ['required', 'boolean'],
                    'images.*.color_match' => ['required', 'boolean'],
                    'images.*.physical_match' => ['sometimes', 'in:match,not_observable,conflicting'],
                    'images.*.color_observability' => ['sometimes', 'in:clear,not_visible,uncertain'],
                    'images.*.catalog_photo' => ['sometimes', 'boolean'],
                    'images.*.keyboard_visibility' => ['sometimes', 'in:readable,visible_unreadable,not_visible,not_applicable'],
                    'images.*.keyboard_layout_match' => ['sometimes', 'in:match,different,unknown,not_applicable'],
                    'images.*.keyboard_requirement_source' => ['sometimes', 'in:operator,evidence,none,unknown'],
                    'images.*.keyboard_requirement_scope' => ['sometimes', 'in:product,photos,none,unknown'],
                    'images.*.keyboard_layout' => ['sometimes', 'nullable', 'string', 'max:100'],
                    'images.*.keyboard_layout_note' => ['sometimes', 'nullable', 'string', 'max:500'],
                    'images.*.configuration_marking' => ['sometimes', 'nullable', 'string', 'max:100'],
                    'images.*.observed_marking' => ['sometimes', 'nullable', 'string', 'max:120'],
                    'images.*.publishable' => ['required', 'boolean'],
                    'images.*.rejection_reason' => ['sometimes', 'nullable', 'in:none,quality,not_product,graphic,text,used_item,duplicate,color,body,identity'],
                    'images.*.kind' => ['required', 'in:product,packaging,detail,logo,banner,screenshot,unrelated,uncertain'],
                    'images.*.view' => ['required', 'in:front,angle,side,back,detail,packaging,other'],
                    // Equal ranks are a tie, not a failed visual examination.
                    // Frame index remains unique; tie-breaking is deterministic.
                    'images.*.gallery_rank' => ['required', 'integer', 'between:1,'.$candidateCount],
                    'images.*.score' => ['required', 'integer', 'between:0,100'],
                    'images.*.reason' => ['required', 'string', 'max:1000'],
                ])->validate();

                $issues = [];
                foreach ($data['images'] as $review) {
                    if ($review['publishable'] && $review['exact_match'] && $review['color_match']
                        && ($review['kind'] === 'uncertain' || $review['score'] < $minimumScore)) {
                        $issues[$review['index']] = 'Conflicting Vision verdict for image '.$review['index']
                            .': publishable/exact_match/color_match=true but kind='.$review['kind'].' and score='
                            .$review['score'].'. Score uses 0..100; configured minimum is '.$minimumScore
                            .'. Reassess this inconsistency; do not automatically increase the score or approve the image.';
                    }
                }
                $data['validation_issues'] = $issues;

                return new VisionResponse($data, $issues === [] ? null : implode(' ', $issues));
            },
        );
    }

    private function keyboardReviewAllows(array $review, bool $operatorRequiresPhotoLayout): bool
    {
        $visibility = $review['keyboard_visibility'] ?? 'unknown';
        $match = $review['keyboard_layout_match'] ?? 'unknown';
        $origin = $review['keyboard_requirement_source'] ?? 'unknown';
        // Legacy evidence-only reviews describe the sold product, never an explicit photo restriction.
        $scope = $review['keyboard_requirement_scope'] ?? ($origin === 'evidence' ? 'product' : 'unknown');
        if (($operatorRequiresPhotoLayout || $scope === 'photos')
            && in_array($visibility, ['readable', 'visible_unreadable'], true)) {
            return $visibility === 'readable' && $match === 'match';
        }
        if ($match === 'different') {
            return $scope === 'product' && in_array($origin, ['operator', 'evidence'], true)
                && $visibility === 'readable'
                && filled($review['keyboard_layout'] ?? null)
                && filled($review['keyboard_layout_note'] ?? null);
        }
        if ($visibility === 'visible_unreadable' && $match === 'unknown') {
            return $scope === 'product' && in_array($origin, ['operator', 'evidence'], true)
                && ($review['physical_match'] ?? null) === 'match' && ($review['color_match'] ?? false)
                && blank($review['keyboard_layout'] ?? null)
                && filled($review['keyboard_layout_note'] ?? null);
        }

        return ! (in_array($visibility, ['readable', 'visible_unreadable'], true) && $match === 'unknown');
    }

    /** Every current full-size frame receives the same catalog policy, including exact sources. */
    public function reviewCompleteGallery(array $candidates, ProductDraft $draft, ?int $updateId = null): ?array
    {
        $accepted = [];
        try {
            foreach (array_chunk(array_values($candidates), 40) as $batch) {
                app(ProductSearchCancellation::class)->throwIfCancelled($updateId ?? $draft->telegram_update_id);
                if (! app(ProductSearchTimeBudget::class)->canStart($updateId ?? $draft->telegram_update_id, 15)) {
                    return null;
                }
                $accepted = [...$accepted, ...$this->selectGalleryFrames($draft, $batch, count($batch), $updateId)];
            }
        } catch (ProductSearchCancelled $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }

        return $accepted;
    }

    /** Explicit decisions for one paid batch; only consistent returned reviews are final. */
    public function reviewFrameBatch(array $candidates, ProductDraft $draft, ?int $updateId = null): GalleryBatchReview
    {
        if (count($candidates) > 40) {
            throw new \InvalidArgumentException('A Vision review batch may contain at most 40 frames.');
        }
        $indexed = [];
        foreach (array_values($candidates) as $index => $candidate) {
            $indexed[] = [...$candidate, '_review_index' => $index + 1];
        }
        $verdicts = null;
        try {
            $accepted = $this->selectWithPolicy($draft, $indexed, count($indexed), $updateId, true, $verdicts);
        } catch (ProductSearchCancelled $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);

            return new GalleryBatchReview([], [], $candidates, $exception->getMessage());
        }
        $acceptedIndices = array_column($accepted, '_review_index');
        $rejected = $pending = [];
        foreach ($indexed as $candidate) {
            $index = $candidate['_review_index'];
            if (in_array($index, $acceptedIndices, true)) {
                continue;
            }
            if (isset($verdicts['reviews'][$index])) {
                $rejected[] = [...$candidate, 'frame_review' => $verdicts['reviews'][$index]];
            } else {
                $pending[] = $candidate;
            }
        }
        $accepted = array_map(fn (array $candidate): array => [...$candidate,
            'frame_review' => $verdicts['reviews'][$candidate['_review_index']] ?? []], $accepted);

        return new GalleryBatchReview($accepted, $rejected, $pending, $verdicts['interruption_reason'] ?? null);
    }

    private function rankReviews($reviews)
    {
        return $reviews
            ->when(
                config('product-images.ranking', 'heuristic') === 'model',
                fn ($query) => $query->sortBy(fn (array $review): array => [
                    $review['gallery_rank'],
                    -$review['score'],
                    $review['index'],
                ]),
                fn ($query) => $query->sortByDesc(fn (array $review): array => [
                    $review['hero'] ? 1 : 0,
                    $review['exact_match'] ? 1 : 0,
                    $review['source_supported'] ? 1 : 0,
                    $review['kind'] === 'product' ? 1 : 0,
                    $review['score'],
                ]),
            )
            ->values();
    }

    /** @param array<int, array<string, mixed>> $candidates */
    private function prompt(ProductDraft $draft, array $candidates, string $categoryHint, bool $galleryFramePolicy = false, bool $labelIdentity = false): string
    {
        $count = count($candidates);
        $identityPolicy = $labelIdentity
            ? 'IDENTITY FROM MARKINGS: the requested product is itself the thing photographed. Requested codes: '
                .implode(', ', ProductPhotoIdentity::codes($draft) ?: ['none'])
                .'. Read the label and copy its part number or model marking into observed_marking; another code or capacity on the label is another product, the product\'s own model or series name is this product.'
                .' What is visible on such a product: '.(ProductPhotoIdentity::hint($draft) ?: 'the label with the part number and capacity, the form factor.')
            : 'Invisible RAM/SSD/clock differences do not disqualify photographs.';
        $specifications = collect($draft->specifications ?? [])->map(function (array $item): string {
            return trim(($item['name'] ?? '').': '.($item['value'] ?? ''), ': ');
        })->filter()->take(12)->implode('; ');
        $candidateSources = collect($candidates)->map(
            fn (array $candidate, int $index): string => '#'.($index + 1)
                .' source: '.($candidate['source_url'] ?? 'unknown')
                .' product_page: '.($candidate['product_page_url'] ?? 'unknown')
                .' exact_page_identity: '.(($candidate['source_identity_confirmed'] ?? false) ? 'yes' : 'no')
                .' photo_applicability: '.json_encode($candidate['page_source_context']['_photo_applicability'] ?? null, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                .' resolution: '.($candidate['width'] ?? '?').'x'.($candidate['height'] ?? '?')
                .(($candidate['confirmed_gallery'] ?? false)
                    ? ' confirmed_playwright_gallery_frame: yes; best resolution exposed by this complete gallery'
                    : ''),
        )->implode("\n");

        $qualityPolicy = $galleryFramePolicy
            ? <<<'POLICY'
            TRUSTED COMPLETE-GALLERY POLICY: These attachments are frames from one structurally complete product
            gallery. Use the supplied source identity/applicability evidence; do not guess an exact SKU from pixels.
            Be deliberately permissive: publishable=true when the product is meaningfully visible and image quality
            is usable. Preserve professional lifestyle scenes with people or rooms, feature
            text/graphics over a product photo, collages, unusual angles, a closed/folded/side/top view, ports, keyboard,
            internals, installed components, or a polished render. Promotional text alone is not a rejection reason.
            Classify a marketing composition with a prominent product as kind=product, kind=detail, or kind=banner.
            The score orders accepted frames only; decoration, an unusual angle or a low aesthetic score is not a
            rejection reason. Keep every usable frame without a real policy violation.

            Reject only when the requested product is absent or too tiny to be useful; the frame is pure text/chart/logo,
            UI screenshot, accessory-only, unrelated media, user-generated/used/damaged imagery; quality is genuinely
            unusable; or pixels visibly contradict the product type, model, revision, or required color. Page identity
            is valid positive evidence when pixels do not contradict it. Judge each attachment's own relevance and
            quality independently of the others - but still compare attachments against each other for the mandatory
            duplicate check below; that check is never skipped by this permissive policy.
            POLICY
            : <<<'POLICY'
            Set publishable=false for a thumbnail, blurry or pixelated/upscaled image, watermark or promotional text,
            badly cropped/truncated product, advertising composition, screenshot, logo, accessory-only shot, or image
            where the product is too small to be useful. Several views of the same product in one frame without
            advertising text or graphics over them are a product photo, not a rejection. Also reject user-generated
            used-item and auction photos, worn/damaged units, hands, rooms, or improvised phone shots even when the
            model matches.
            POLICY;
        $confirmedFramePolicy = $galleryFramePolicy
            ? <<<'POLICY'
            A candidate marked confirmed_playwright_gallery_frame was technically extracted from this exact product
            page. Apply the permissive carousel policy above: a prominent product with text, graphics, people, a room,
            or unusual framing remains publishable. Gallery membership supports provenance but never overrides a visible
            conflict, a frame without the product, or unusable quality.
            POLICY
            : <<<'POLICY'
            A candidate marked confirmed_playwright_gallery_frame is part of a complete gallery extracted and validated
            by Playwright from this exact product's page. Do not reject it solely because its clean source resolution
            is 400-599px, or solely because it shows an unusual camera angle or framing (side, top, closed lid, hinge,
            ports, keyboard deck, or another close-up of a distinctive part) instead of a clean front hero shot -
            classify those as kind=product or kind=detail, not uncertain or unrelated, and score them on sharpness and
            usefulness rather than penalizing the angle itself. Still reject a confirmed-gallery frame that is a logo,
            banner, screenshot, accessory-only shot, or that visibly conflicts on model or color - gallery membership
            is not identity or color evidence by itself, only a pass on resolution and camera angle.
            POLICY;

        $componentLogoPolicy = ProductPhotoPolicy::allowsComponentLogos($draft)
            ? 'COMPONENT EXCEPTION: relevant brand or product-family logos are allowed, including as the cover. Classify as kind=logo; judge correspondence using the requested product and source evidence. This overrides the generic logo rejection below, never permits missing-image placeholders or conflicting brands/families, and never proves an exact SKU from pixels alone.'
            : 'NO LOGO EXCEPTION: standalone brand/family logos are not acceptable for this product type.';
        $requestContext = json_encode(ProductRequestContext::forDraft($draft)->forAgent(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return <<<PROMPT
            Review {$count} attached candidate images for a public product catalog.
            Exact requested product: {$draft->title}
            Brand: {$draft->brand}
            Model: {$draft->model}
            Product type: {$draft->product_type}; category: {$draft->category}
            {$componentLogoPolicy}
            Required color/version: {$draft->color}
            Original request and evidenced configuration (including keyboard requirements): {$requestContext}
            Trusted category instruction: {$categoryHint}

            Judge every source type by identical rules. A manufacturer, marketplace, or retailer URL is evidence only
            and must never override a visible mismatch. A visibly different product color requires color_match=false.
            {$qualityPolicy}
            {$confirmedFramePolicy}
            A candidate marked exact_page_identity=yes has confirmed source identity or separately assessed photo
            applicability. A compatible verdict means another SKU may illustrate this target because its physical
            appearance matches. {$identityPolicy} Preserve and
            report any known keyboard-layout difference, without pretending the source is the target's exact offer.
            Record keyboard_visibility and keyboard_layout_match explicitly. A permitted, positively observed
            regional keyboard difference needs a factual Russian note. Not-visible keyboards never prove a layout.
            keyboard_requirement_source must distinguish an explicit original operator requirement (operator)
            from a label/specification fact alone (evidence). keyboard_requirement_scope is product for the sold
            item's layout, including an explicitly requested Czech laptop. A readable US keyboard on compatible
            same-body photos may satisfy product scope only with a factual notice that the sold item is Czech.
            Use photos scope only for an explicit requirement about the photographs themselves; a visible different
            layout cannot satisfy that restriction, even with a note. Unknown scope/provenance does not authorize
            a regional substitution. Neither scope permits guessing an unreadable layout or an unseen match.
            Under product scope, visible_unreadable legends do not prove a mismatch when the body and color match:
            keep keyboard_layout_match=unknown, keyboard_layout empty, and provide an honest Russian notice that
            the photo layout is unreadable while the sold item's layout is known. Never invent US/CZ or call it match.
            Under photos or unknown scope, unresolved visible layout remains ineligible; other views are separate.
            The source's exact model and gallery membership never override catalog_photo=false for UGC/auction
            phone shots or poor quality. Inspect chassis shape, revision, ports, finish and current pixels.
            Do not require that identifier to be visibly printed on the photographed
            product. Use that page evidence for identity unless the pixels visibly contradict it. It never overrides
            a visible conflict, wrong color, wrong product type, or insufficient quality.

            Mandatory image-text language rule: inspect only text rendered inside the attached image. Prominent or
            compositionally meaningful marketing text is publishable only in English or Czech; reject the frame when
            such text is in another language or script. A text-free photo is allowed. Ordinary brand/model/SKU labels
            printed on the product, universal technical abbreviations, and tiny incidental background text do not
            trigger this rejection.

            Prefer a sharp, clean, full-product hero view first, then distinct useful angles/details. Do not infer
            exact model, color, or quality from the URL when visible evidence conflicts.

            Mandatory duplicate check, performed after judging each image on its own: compare every attachment
            against every other one. Two attachments are the SAME photograph - not two different angles - when they
            show identical framing, pose, and lighting, even if their resolution, crop, aspect ratio, compression,
            or file format differs (a small thumbnail and a large hero render of that exact same shot are still one
            photograph). For every such group, keep only the sharpest/highest-resolution attachment as normal and
            set publishable=false on every other member of that group, with reason stating which image number it is
            a duplicate rendition of (e.g. "duplicate rendition of image 3, same shot at lower resolution"). This
            check applies regardless of how many total duplicates exist or how few genuinely distinct photos would
            remain - never keep more than one rendition of the same photograph merely to reach a higher count.
            Key specifications: {$specifications}
            Numbering follows attachment order: first attachment is image 1, etc.
            Candidate URLs and decoded resolutions:
            {$candidateSources}
            PROMPT;
    }

    /**
     * Current content and language, checked together in provider-sized batches.
     * Recipe provenance never replaces this call. Obvious non-product frames
     * are removed, subject to the explicit component-logo exception; useful
     * angles/details remain. The first survivor becomes the catalog cover.
     *
     * Null means the question could not be answered - a timeout, a malformed
     * reply, an exception, or unresolved content. PROJECT_STRATEGY forbids failure from
     * being dressed as a semantic verdict, and returning the frames unchanged
     * would do exactly that: the gallery would be published as though Vision
     * had cleared it. The caller keeps the frames but must not treat them as
     * confirmed.
     *
     * @param  array<int, array<string, mixed>>  $candidates
     * @return array<int, array<string, mixed>>|null
     */
    public function checkedGalleryFrames(
        array $candidates,
        ProductDraft $draft,
        ?int $telegramUpdateId = null,
    ): ?array {
        if ($candidates === []) {
            return $candidates;
        }

        $settings = app(AiSettings::class);
        $provider = $settings->providerFor('product_image_vision');
        $model = $settings->modelFor('product_image_vision');
        $cancellation = app(ProductSearchCancellation::class);
        $runId = $telegramUpdateId ?? $draft->telegram_update_id;
        $batches = array_chunk(array_values($candidates), 40);
        $accepted = [];
        $cover = null;

        foreach ($batches as $batchIndex => $frames) {
            $cancellation->throwIfCancelled($runId);
            $prompt = json_encode([
                'product' => trim((string) $draft->title),
                'category' => $draft->category,
                'product_type' => $draft->product_type,
                'allow_component_logos' => ProductPhotoPolicy::allowsComponentLogos($draft),
                'model' => $draft->model,
                'frames' => count($frames),
                'batch' => $batchIndex + 1,
                'batches' => count($batches),
                'indexing' => 'All frame indices are 1-based within this attached batch, not the whole gallery.',
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            try {
                $attachments = array_map(
                    fn (array $candidate, int $index) => app(VisionImagePreparer::class)->thumbnail(
                        $candidate['image'],
                        'frame-'.($index + 1).'.webp',
                        (string) config('product-images.vision_detail', 'low'),
                    ),
                    $frames,
                    array_keys($frames),
                );
                $data = app(VisionService::class)->checkGalleryFrames(
                    $prompt,
                    $attachments,
                    new VisionCallContext($runId, $settings->imageVisionTimeoutSeconds(), provider: $provider, model: $model),
                );
                if ($data['uncertain_content_frames'] !== []) {
                    // Preserve the set as pending. Uncertainty must not publish
                    // a logo as the cover, nor silently destroy a real photo.
                    return null;
                }
                $indices = [...$data['foreign_text_frames'], ...$data['non_product_frames']];
                $rejected = array_fill_keys(array_unique(array_map('intval', $indices)), true);
                foreach ($frames as $index => $candidate) {
                    if (! isset($rejected[$index + 1])) {
                        // The reviewer's cover pick leads the gallery; the rest keep source order.
                        $cover ??= ($data['cover_frame'] ?? 0) === $index + 1 ? count($accepted) : null;
                        $accepted[] = $candidate;
                    }
                }
            } catch (ProductSearchCancelled $exception) {
                throw $exception;
            } catch (Throwable $exception) {
                report($exception);

                return null;
            }
        }
        if ($cover !== null && $cover > 0) {
            array_unshift($accepted, ...array_splice($accepted, $cover, 1));
        }
        // A component's gallery frames are checked for what their labels say as well:
        // a reseller's own slider can hold another drive (live #379). Interruption stays
        // interruption; a frame refused by its label is simply not in the gallery.
        if ($accepted !== [] && ProductPhotoIdentity::fromLabel($draft) && ProductPhotoIdentity::codes($draft) !== []) {
            try {
                $accepted = $this->selectGalleryFrames($draft, $accepted, count($accepted), $telegramUpdateId);
            } catch (ProductSearchCancelled $exception) {
                throw $exception;
            } catch (Throwable $exception) {
                report($exception);

                return null;
            }
        }

        return $accepted;
    }
}
