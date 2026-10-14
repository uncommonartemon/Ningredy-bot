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
use GdImage;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Throwable;

class ProductImageVisionVerifier
{
    /**
     * Best packaging candidate blocked solely by a category "no box photos"
     * instruction, keyed by draft id. Held in memory only (never cached/
     * serialized - it carries a live GdImage) so it never survives past the
     * PHP process handling this draft's search cycle. Populated by
     * selectWithPolicy() as candidates are reviewed and consumed exactly
     * once via takeHintBlockedCandidate() as a last-resort fallback when a
     * whole search cycle otherwise finds nothing.
     *
     * @var array<int, array<string, mixed>>
     */
    private array $hintBlockedCandidates = [];

    public function __construct(
        private readonly ProductIdentityMatcher $identityMatcher,
    ) {}

    /**
     * Returns and clears the best packaging photo seen for this draft that
     * would otherwise have been accepted (matched identity/color/score) but
     * was deterministically rejected only because the category instruction
     * forbids packaging shots. Callers must use this strictly as a
     * last-resort: only once every normal source/round has produced nothing
     * usable, never to short-circuit the search early.
     *
     * @return array<string, mixed>|null
     */
    public function takeHintBlockedCandidate(ProductDraft $draft): ?array
    {
        $candidate = $this->hintBlockedCandidates[$draft->id] ?? null;
        unset($this->hintBlockedCandidates[$draft->id]);

        return $candidate;
    }

    private static function categoryHintForbidsPackaging(string $hint): bool
    {
        if ($hint === '') {
            return false;
        }

        $normalized = mb_strtolower($hint);
        $hasNegation = (bool) preg_match('/\b(без|не\s|нет|no|without)\b/u', $normalized);
        $mentionsPackaging = (bool) preg_match('/(коробк|упаковк|\bbox\b|packaging)/u', $normalized);

        return $hasNegation && $mentionsPackaging;
    }

    private function cloneGdImage(GdImage $image): GdImage
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $clone = imagecreatetruecolor($width, $height);
        imagealphablending($clone, false);
        imagesavealpha($clone, true);
        imagecopy($clone, $image, 0, 0, 0, 0, $width, $height);

        return $clone;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $qualifyingPackagingReviews
     * @param  array<int, array<string, mixed>>  $candidates
     */
    private function rememberHintBlockedCandidate(
        ProductDraft $draft,
        $qualifyingPackagingReviews,
        array $candidates,
        string $categoryHint,
        string $model,
    ): void {
        $best = $qualifyingPackagingReviews->sortByDesc('score')->first();

        if ($best === null) {
            return;
        }

        $existing = $this->hintBlockedCandidates[$draft->id] ?? null;

        if ($existing !== null && ($existing['vision_score'] ?? 0) >= $best['score']) {
            return;
        }

        $candidate = $candidates[$best['index'] - 1] ?? null;

        if (! isset($candidate['image']) || ! $candidate['image'] instanceof GdImage) {
            return;
        }

        if ($existing !== null && ($existing['image'] ?? null) instanceof GdImage) {
            imagedestroy($existing['image']);
        }

        $this->hintBlockedCandidates[$draft->id] = [
            ...$candidate,
            'image' => $this->cloneGdImage($candidate['image']),
            'verification_status' => 'hint_override',
            'vision_kind' => $best['kind'],
            'vision_score' => $best['score'],
            'vision_reason' => 'Принято как крайний вариант: подсказка категории ("'.$categoryHint.'") запрещает такие фото, '
                .'но по остальным источникам не нашлось ни одного подходящего без нарушения подсказки. '.$best['reason'],
            'vision_model' => $model,
        ];
    }

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
     * This policy deliberately accepts useful lifestyle/feature photography
     * when the requested product is still meaningfully visible.
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
    ): array {
        if ($limit <= 0 || $candidates === []) {
            return [];
        }

        $maxCandidates = $galleryFramePolicy
            ? AiSettings::GALLERY_MAX_IMAGE_COUNT
            : (int) config('product-images.vision_candidates', 4);
        $minimumScore = $galleryFramePolicy
            ? min(40, (int) config('product-images.vision_min_score', 60))
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
            $pageIdentityConfirmed = $pageContext !== null
                && ! $this->identityMatcher->conflictsSource($draft, $pageContext)
                && $this->identityMatcher->confirmsExactIdentifier($draft, $pageContext);

            return [
                ...$candidate,
                'product_page_url' => $candidate['product_page_url']
                    ?? ($pageContext['url'] ?? $candidate['page_source_url'] ?? null),
                'source_identity_confirmed' => (bool) ($candidate['source_identity_confirmed'] ?? false)
                    || $pageIdentityConfirmed,
            ];
        }, $candidates);
        $settings = app(AiSettings::class);
        $timeBudget = app(ProductSearchTimeBudget::class);

        if (! $timeBudget->canStart($telegramUpdateId ?? $draft->telegram_update_id, 15)) {
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
        $prompt = $this->prompt($draft, $candidates, $categoryHint, $galleryFramePolicy);
        $attachments = array_map(fn (array $candidate, int $index) => app(VisionImagePreparer::class)->thumbnail(
            $candidate['image'],
            'candidate-'.($index + 1).'.webp',
            (string) config('product-images.vision_detail', 'low'),
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

        $reviewed = collect($data['images'])
            ->map(function (array $review) use ($draft, $candidates): array {
                $candidate = $candidates[$review['index'] - 1] ?? [];

                return [
                    ...$review,
                    'hero' => $review['kind'] === 'product'
                        && in_array($review['view'], ['front', 'angle'], true),
                    'source_supported' => (bool) ($candidate['source_identity_confirmed'] ?? false)
                        || $this->identityMatcher->supports($draft, (string) ($candidate['source_url'] ?? '')),
                ];
            });

        $allowedKinds = $galleryFramePolicy
            ? ['product', 'packaging', 'detail', 'banner']
            : ['product', 'packaging', 'detail'];
        if (ProductPhotoPolicy::allowsComponentLogos($draft)) {
            $allowedKinds[] = 'logo';
        }
        $forbidsPackaging = self::categoryHintForbidsPackaging($categoryHint);
        $qualifies = fn (array $review): bool => $review['publishable']
            && (! filled($draft->color) || $review['color_match'])
            && in_array($review['kind'], $allowedKinds, true)
            && $review['score'] >= $minimumScore
            && ($review['exact_match'] || $review['source_supported']);

        if ($forbidsPackaging) {
            $this->rememberHintBlockedCandidate(
                $draft,
                $reviewed->filter(fn (array $review): bool => $review['kind'] === 'packaging' && $qualifies($review)),
                $candidates,
                $categoryHint,
                $model,
            );
        }

        $reviews = $this->rankReviews($reviewed->filter(fn (array $review): bool => $qualifies($review)
            && ! ($forbidsPackaging && $review['kind'] === 'packaging')));

        return $reviews->take($limit)->map(function (array $review) use ($candidates, $model): array {
            return [
                ...$candidates[$review['index'] - 1],
                'vision_kind' => $review['kind'],
                'vision_score' => $review['score'],
                'vision_reason' => $review['source_supported'] && ! $review['exact_match']
                    ? 'Exact identity is supported by the source URL. '.$review['reason']
                    : $review['reason'],
                'vision_model' => $model,
            ];
        })->all();
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
                    ->map(function (array $image): array {
                        if (is_string($image['reason'] ?? null)) {
                            $image['reason'] = mb_substr($image['reason'], 0, 1000);
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
                    'images.*.publishable' => ['required', 'boolean'],
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
    private function prompt(ProductDraft $draft, array $candidates, string $categoryHint, bool $galleryFramePolicy = false): string
    {
        $count = count($candidates);
        $specifications = collect($draft->specifications ?? [])->map(function (array $item): string {
            return trim(($item['name'] ?? '').': '.($item['value'] ?? ''), ': ');
        })->filter()->take(12)->implode('; ');
        $candidateSources = collect($candidates)->map(
            fn (array $candidate, int $index): string => '#'.($index + 1)
                .' source: '.($candidate['source_url'] ?? 'unknown')
                .' product_page: '.($candidate['product_page_url'] ?? 'unknown')
                .' exact_page_identity: '.(($candidate['source_identity_confirmed'] ?? false) ? 'yes' : 'no')
                .' resolution: '.($candidate['width'] ?? '?').'x'.($candidate['height'] ?? '?')
                .(($candidate['confirmed_gallery'] ?? false)
                    ? ' confirmed_playwright_gallery_frame: yes; best resolution exposed by this complete gallery'
                    : ''),
        )->implode("\n");

        $qualityPolicy = $galleryFramePolicy
            ? <<<'POLICY'
            These attachments are frames from one structurally complete but semantically ambiguous carousel on the
            exact product page. Be deliberately permissive: publishable=true when the requested product is meaningfully
            visible and the frame is useful or attractive, including lifestyle scenes with people or rooms, feature
            text/graphics over a product photo, collages, unusual angles, a closed/folded/side/top view, ports, keyboard,
            internals, installed components, or a polished render. Promotional text alone is not a rejection reason.
            Classify a marketing composition with a prominent product as kind=product, kind=detail, or kind=banner.

            Reject only when the requested product is absent or too tiny to be useful; the frame is pure text/chart/logo,
            UI screenshot, accessory-only, unrelated media, user-generated/used/damaged imagery; quality is genuinely
            unusable; or pixels visibly contradict the product type, model, revision, or required color. Page identity
            is valid positive evidence when pixels do not contradict it. Judge each attachment's own relevance and
            quality independently of the others - but still compare attachments against each other for the mandatory
            duplicate check below; that check is never skipped by this permissive policy.
            POLICY
            : <<<'POLICY'
            Set publishable=false for a thumbnail, blurry or pixelated/upscaled image, watermark or promotional text,
            badly cropped/truncated product, collage, screenshot, logo, accessory-only shot, or image where the product
            is too small to be useful. Also reject user-generated used-item and auction photos, worn/damaged units,
            hands, rooms, or improvised phone shots even when the model matches.
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

        return <<<PROMPT
            Review {$count} attached candidate images for a public product catalog.
            Exact requested product: {$draft->title}
            Brand: {$draft->brand}
            Model: {$draft->model}
            Product type: {$draft->product_type}; category: {$draft->category}
            {$componentLogoPolicy}
            Required color/version: {$draft->color}
            Trusted category instruction: {$categoryHint}

            Judge every source type by identical rules. A manufacturer, marketplace, or retailer URL is evidence only
            and must never override a visible mismatch. A visibly different product color requires color_match=false.
            {$qualityPolicy}
            {$confirmedFramePolicy}
            A candidate marked exact_page_identity=yes came from a page whose URL/title deterministically contains
            the requested SKU/MPN/model. Do not require that identifier to be visibly printed on the photographed
            product. Use that page evidence for identity unless the pixels visibly contradict it. It never overrides
            a visible conflict, wrong color, wrong product type, prohibited packaging, or insufficient quality.

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

        return $accepted;
    }
}
