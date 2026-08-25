<?php

namespace App\Services\Products;

use App\Ai\Agents\ProductImageDiscoveryAgent;
use App\Models\AiRun;
use App\Models\Category;
use App\Models\ProductDraft;
use App\Services\Ai\AiErrorPresenter;
use App\Services\Ai\AiSettings;
use App\Services\Ai\OpenAiHeavyOperationGate;
use App\Services\Ai\ProductSearchTimeBudget;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Throwable;

class ProductImageCandidateDiscovery
{
    /** @var array<string, string> */
    private array $sourcePagesByImageUrl = [];

    /** @var array<string, array<string, mixed>> */
    private array $sourceContextsByImageUrl = [];

    private bool $terminalFailure = false;

    public function __construct(
        private readonly ProductImageResolver $resolver,
        private readonly WikimediaImageSearch $wikimedia,
        private readonly ProductSourcePriority $sourcePriority,
        private readonly AiErrorPresenter $errors,
        private readonly ProductIdentityMatcher $identityMatcher,
        private readonly ProductSourceAttemptRecorder $attempts,
    ) {}

    public function sourcePageForImage(string $imageUrl): ?string
    {
        return $this->sourcePagesByImageUrl[ProductImageStorage::normalizeCandidateUrl($imageUrl)] ?? null;
    }

    /** @return array<string, mixed>|null */
    public function sourceContextForImage(string $imageUrl): ?array
    {
        return $this->sourceContextsByImageUrl[ProductImageStorage::normalizeCandidateUrl($imageUrl)] ?? null;
    }

    public function hasTerminalFailure(): bool
    {
        return $this->terminalFailure;
    }

    /** @return array<int, string> */
    /** @param null|callable(string): void $progress */
    public function find(
        ProductDraft $draft,
        array $excludedUrls = [],
        bool $skipKnownSources = false,
        ?callable $progress = null,
        ?int $telegramUpdateId = null,
        array $additionalExcludedSourceUrls = [],
        int $searchAttempt = 0,
    ): array {
        // Callers acting long after the draft's original search (retry/top-up
        // buttons pressed minutes or hours later) must pass their own fresh
        // update id here - falling back to the draft's original one would
        // measure time budget and AI-cost attribution against a search that
        // already ended, making canStart() report zero time left immediately.
        $telegramUpdateId ??= $draft->telegram_update_id;
        $this->sourcePagesByImageUrl = [];
        $this->sourceContextsByImageUrl = [];
        $this->terminalFailure = false;
        $excludedUrls = collect($excludedUrls)
            ->filter(fn (mixed $url): bool => is_string($url) && $url !== '')
            ->unique()
            ->values()
            ->all();
        $knownDraftSourceUrls = $skipKnownSources
            ? collect($draft->sources ?? [])
                ->filter(fn (mixed $source): bool => is_array($source) && is_string($source['url'] ?? null))
                ->pluck('url')
                ->all()
            : [];
        $excludedSourceUrls = collect([
            ...($draft->excluded_gallery_source_urls ?? []),
            ...$additionalExcludedSourceUrls,
            ...$knownDraftSourceUrls,
            ...array_map(fn (string $domain): string => 'https://'.$domain, $this->sourcePriority->blockedDomains()),
        ])
            ->filter(fn (mixed $url): bool => is_string($url) && $url !== '')
            ->unique()
            ->values()
            ->all();
        $sources = array_values(array_filter(
            $this->imageSources($this->sourcePriority->sortSources($draft->sources ?? [], $draft->brand)),
            fn (array $source): bool => ! $this->sourceExcluded((string) ($source['url'] ?? ''), $excludedSourceUrls),
        ));
        $knownSourceUrls = $skipKnownSources
            ? []
            : $this->resolveSourcesIndividually($sources, $draft, telegramUpdateId: $telegramUpdateId);
        $preferredUrls = $this->withoutExcluded($this->sourcePriority->sortUrls(
            $knownSourceUrls,
            $draft->brand,
            $sources,
        ), $excludedUrls);

        $directCatalogUrls = array_values(array_filter(
            $preferredUrls,
            fn (string $url): bool => $this->looksLikeCatalogImage($url),
        ));

        if (count($directCatalogUrls) >= (int) config('product-images.public_source_target', 6)) {
            return $directCatalogUrls;
        }

        $wikimediaUrls = $this->wikimedia->find($draft, 10);
        $availableUrls = $this->withoutExcluded($this->sourcePriority->sortUrls(
            [...$preferredUrls, ...$wikimediaUrls],
            $draft->brand,
            $sources,
        ), $excludedUrls);

        $settings = app(AiSettings::class);
        $timeBudget = app(ProductSearchTimeBudget::class);

        if (! $timeBudget->canStart($telegramUpdateId, 30)) {
            $progress?->__invoke('Резерв времени достигнут: дополнительный AI-поиск источников пропущен, сохраняю уже полученный результат.');

            return $availableUrls;
        }

        $discoveryTimeout = $timeBudget->timeoutFor(
            $telegramUpdateId,
            $settings->imageDiscoveryTimeoutSeconds(),
        );
        $provider = $settings->providerFor('product_image_discovery');
        $model = $settings->modelFor('product_image_discovery');
        $prompt = $this->prompt($draft, $excludedUrls, $excludedSourceUrls, $searchAttempt);
        $cached = AiRun::query()
            ->where('telegram_update_id', $telegramUpdateId)
            ->where('provider', $provider)
            ->where('model', $model)
            ->where('status', 'completed')
            ->where('prompt', $prompt)
            ->latest('id')
            ->first();

        if ($cached && is_array($cached->response)) {
            return $this->withoutExcluded($this->sourcePriority->sortUrls([
                ...$availableUrls,
                ...$this->candidateUrls($cached->response, $draft, $sources, $progress, $telegramUpdateId, $excludedSourceUrls),
            ], $draft->brand, $sources), $excludedUrls);
        }

        $run = AiRun::query()->create([
            'telegram_update_id' => $telegramUpdateId,
            'provider' => $provider,
            'model' => $model,
            'status' => 'running',
            'prompt' => $prompt,
            'started_at' => now(),
        ]);

        try {
            $response = app(OpenAiHeavyOperationGate::class)->run(
                $provider,
                $discoveryTimeout,
                fn () => ProductImageDiscoveryAgent::make()->prompt(
                    $prompt,
                    provider: $provider,
                    model: $model,
                    timeout: $discoveryTimeout,
                ),
            );
            $normalizedResponse = $response->toArray();

            foreach (['image_urls', 'page_urls'] as $urlField) {
                $candidates = collect($normalizedResponse[$urlField] ?? [])
                    ->filter(fn (mixed $url): bool => is_string($url))
                    ->map(fn (string $url): string => trim($url))
                    ->filter(fn (string $url): bool => mb_strlen($url) <= 2048
                        && filter_var($url, FILTER_VALIDATE_URL) !== false
                        && in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true));

                // Only image_urls can be two renditions of one photo (Scene7
                // wid/hei, $PRESET$, ...) - page_urls are product page links,
                // where that CDN-size normalization doesn't apply.
                if ($urlField === 'image_urls') {
                    $candidates = $candidates->map(fn (string $url): string => ProductImageStorage::normalizeCandidateUrl($url));
                }

                $normalizedResponse[$urlField] = $candidates->unique()->take(12)->values()->all();
            }

            $normalizedResponse['sources'] = collect($normalizedResponse['sources'] ?? [])
                ->filter(fn (mixed $source): bool => is_array($source))
                ->map(function (array $source): array {
                    $pageUrl = is_string($source['page_url'] ?? null) ? trim($source['page_url']) : '';
                    $imageUrls = collect($source['image_urls'] ?? [])
                        ->filter(fn (mixed $url): bool => is_string($url))
                        ->map(fn (string $url): string => trim($url))
                        ->filter(fn (string $url): bool => mb_strlen($url) <= 2048
                            && filter_var($url, FILTER_VALIDATE_URL) !== false
                            && in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true))
                        ->map(fn (string $url): string => ProductImageStorage::normalizeCandidateUrl($url))
                        ->unique()
                        ->take(12)
                        ->values()
                        ->all();

                    return ['page_url' => $pageUrl, 'image_urls' => $imageUrls];
                })
                ->filter(fn (array $source): bool => filter_var($source['page_url'], FILTER_VALIDATE_URL) !== false
                    && in_array(parse_url($source['page_url'], PHP_URL_SCHEME), ['http', 'https'], true))
                ->unique('page_url')
                ->take(8)
                ->values()
                ->all();

            $data = Validator::make($normalizedResponse, [
                'sources' => ['sometimes', 'array', 'max:8'],
                'sources.*.page_url' => ['required', 'url:http,https', 'max:2048'],
                'sources.*.image_urls' => ['present', 'array', 'max:12'],
                'sources.*.image_urls.*' => ['url:http,https', 'max:2048'],
                'image_urls' => ['present', 'array', 'max:12'],
                'image_urls.*' => ['url:http,https', 'max:2048'],
                'page_urls' => ['present', 'array', 'max:12'],
                'page_urls.*' => ['url:http,https', 'max:2048'],
            ])->validate();
            $run->update([
                'invocation_id' => $response->invocationId,
                'status' => 'completed',
                'response' => $data,
                'usage' => $response->usage->toArray(),
                'completed_at' => now(),
            ]);

            return $this->withoutExcluded($this->sourcePriority->sortUrls([
                ...$availableUrls,
                ...$this->candidateUrls($data, $draft, $sources, $progress, $telegramUpdateId, $excludedSourceUrls),
            ], $draft->brand, $sources), $excludedUrls);
        } catch (Throwable $exception) {
            $presented = $this->errors->present($exception);
            $this->terminalFailure = ! ($presented['retryable'] ?? false);
            $run->update([
                'status' => 'failed',
                'error' => mb_substr($exception->getMessage(), 0, 5000),
                'completed_at' => now(),
            ]);
            Log::warning('Optional AI product image discovery failed; keeping the current search result.', [
                'draft_id' => $draft->id,
                'available_candidates' => count($availableUrls),
                'error' => $exception->getMessage(),
            ]);
            $progress?->__invoke($this->terminalFailure
                ? 'Дополнительный AI-поиск остановлен: '.($presented['message'] ?? $exception->getMessage())
                : 'Дополнительный AI-поиск временно недоступен; сохраняю текущий результат.');

            return $availableUrls;
        }
    }

    /** @param array<int, array<string, mixed>> $sources @return array<int, array<string, mixed>> */
    private function imageSources(array $sources): array
    {
        return collect($sources)
            ->filter(fn (array $source): bool => $this->isLikelyHtmlImageSource((string) ($source['url'] ?? '')))
            ->values()
            ->all();
    }

    private function isLikelyHtmlImageSource(string $url): bool
    {
        $lower = strtolower(urldecode($url));
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $path = strtolower((string) parse_url($url, PHP_URL_PATH));

        if ($url === '' || preg_match('#\.(?:pdf|docx?|xlsx?|csv|zip)(?:\?|$)#i', $path) === 1) {
            return false;
        }

        if (str_contains($host, 'psref.lenovo.com')
            || str_contains($host, 'energystar.gov')
            || str_contains($host, 'support.lenovo.com')
            || str_contains($host, 'pcsupport.lenovo.com')
        ) {
            return false;
        }

        return ! str_contains($lower, '/support/')
            && ! str_contains($lower, '/drivers/')
            && ! str_contains($lower, '/manual')
            && ! str_contains($lower, 'spec.pdf')
            && ! str_contains($lower, 'datasheet')
            && ! str_contains($lower, 'certificate');
    }

    private function looksLikeCatalogImage(string $url): bool
    {
        $lower = strtolower(urldecode($url));

        if (ImageUrlHeuristics::containsMarker($lower, [
            ...ImageUrlHeuristics::COMMON_MARKERS,
            ...ImageUrlHeuristics::ASSET_MARKERS,
        ])) {
            return false;
        }

        return preg_match('#\.(?:jpe?g|png|webp|avif)(?:\?|$)#i', $lower) === 1
            || preg_match('#/(?:w[3-9]\d{2,}|h[3-9]\d{2,})(?:\?|/|$)#i', $lower) === 1;
    }

    /** @param array<int, string> $excludedUrls @param array<int, string> $excludedSourceUrls */
    private function prompt(ProductDraft $draft, array $excludedUrls = [], array $excludedSourceUrls = [], int $searchAttempt = 0): string
    {
        $specifications = collect($draft->specifications ?? [])
            ->map(fn (mixed $item): string => is_array($item)
                ? trim(($item['name'] ?? $item['key'] ?? '').': '.($item['value'] ?? ''), ': ')
                : '')
            ->filter()
            ->take(10)
            ->implode('; ');
        $knownPages = collect($this->imageSources($this->sourcePriority->sortSources($draft->sources ?? [], $draft->brand)))
            ->pluck('url')
            ->filter(fn (mixed $url): bool => is_string($url) && ! $this->sourceExcluded($url, $excludedSourceUrls))
            ->take(8)
            ->implode("\n");
        $excluded = collect($excludedUrls)->take(20)->implode("\n");
        $excludedSources = collect($excludedSourceUrls)->take(20)->implode("\n");
        $attemptInstruction = $searchAttempt > 0
            ? "Independent fallback search pass: {$searchAttempt}. Return new sources not excluded above."
            : '';

        return <<<PROMPT
            [product-image-discovery:v8]
            Find current, downloadable professional catalog image candidates for this exact product:
            Title: {$draft->title}
            Brand: {$draft->brand}
            Exact model: {$draft->model}
            Required color/version: {$draft->color}
            Identifying specifications: {$specifications}
            Already known product pages:
            {$knownPages}
            Previously rejected image URLs that must not be returned again:
            {$excluded}
            Previously rejected source pages/domains that must not be searched again:
            {$excludedSources}
            {$attemptInstruction}

            Search the exact model and required color/version using professional manufacturer, retailer, or marketplace
            product pages. Do not prefer a source by its type; extraction history is ranked by the application. Never use
            photos from individual used-item listings, auctions, reviews, social media, or user uploads. A used/refurbished
            request may use professional photos of the exact same chassis revision and color from a new-retail or
            manufacturer page. Do not use PDF, support/download/manual pages, certifications, generic family pages,
            any rejected source/domain above, or any previously rejected image. Prefer working page URLs from several
            new domains; our server extracts their galleries. Return a direct image URL only when the current search
            result exposes that exact URL. Do not reconstruct or guess CDN paths.
            PROMPT;
    }

    /** @param array<int, string> $excludedSourceUrls */
    private function sourceExcluded(string $url, array $excludedSourceUrls): bool
    {
        $host = ProductSourcePriority::host($url);

        if ($host === '') {
            return false;
        }

        return collect($excludedSourceUrls)
            ->filter(fn (mixed $excluded): bool => is_string($excluded))
            ->contains(fn (string $excluded): bool => ProductSourcePriority::hostsMatch(
                $host,
                ProductSourcePriority::host($excluded),
            ));
    }

    /** @param array<int, string> $urls @param array<int, string> $excludedUrls @return array<int, string> */
    private function withoutExcluded(array $urls, array $excludedUrls): array
    {
        if ($excludedUrls === []) {
            return array_values(array_unique($urls));
        }

        return array_values(array_diff(array_unique($urls), $excludedUrls));
    }

    /** @param array<string, mixed> $data @return array<int, string> */
    private function candidateUrls(
        array $data,
        ProductDraft $draft,
        array $sources,
        ?callable $progress = null,
        ?int $telegramUpdateId = null,
        array $excludedSourceUrls = [],
    ): array {
        $excludedSources = array_values(array_unique([
            ...($draft->excluded_gallery_source_urls ?? []),
            ...$excludedSourceUrls,
        ]));
        $rawPairedSources = collect($data['sources'] ?? [])
            ->filter(fn (mixed $source): bool => is_array($source)
                && is_string($source['page_url'] ?? null));
        $pairedSources = $rawPairedSources
            ->filter(fn (array $source): bool => ! $this->sourceExcluded($source['page_url'], $excludedSources))
            ->values();
        $imageUrls = $rawPairedSources->isEmpty()
            ? array_values(array_filter(
                $data['image_urls'] ?? [],
                fn (mixed $url): bool => is_string($url) && ! $this->sourceExcluded($url, $excludedSources),
            ))
            : $pairedSources->flatMap(function (array $source): array {
                $pageUrl = $source['page_url'];

                return collect($source['image_urls'] ?? [])
                    ->filter(fn (mixed $url): bool => is_string($url))
                    ->each(function (string $url) use ($pageUrl): void {
                        $key = ProductImageStorage::normalizeCandidateUrl($url);
                        $this->sourcePagesByImageUrl[$key] = $pageUrl;
                        $this->sourceContextsByImageUrl[$key] = ['url' => $pageUrl];
                    })
                    ->values()
                    ->all();
            })->unique()->values()->all();
        $pageUrls = array_values(array_filter(
            [
                ...$pairedSources->pluck('page_url')->all(),
                ...($data['page_urls'] ?? []),
            ],
            fn (mixed $url): bool => is_string($url) && ! $this->sourceExcluded($url, $excludedSources),
        ));
        $pageUrls = array_slice($this->sourcePriority->sortUrls($pageUrls, $draft->brand, $sources), 0, (int) config('product-images.ai_page_urls_limit', 4));
        $resolved = $this->resolveSourcesIndividually(
            array_map(fn (string $url): array => ['url' => $url], $pageUrls),
            $draft,
            $progress,
            $telegramUpdateId,
        );

        $sorted = $this->sourcePriority->sortUrls(
            [...$resolved, ...$imageUrls],
            $draft->brand,
            $sources,
        );

        return $this->limitCandidateUrlsBySource($sorted);
    }

    /**
     * Resolve every product page separately so an image never loses the exact
     * card it came from when multiple stores share the same CDN host.
     *
     * @param  array<int, array<string, mixed>>  $sources
     * @return array<int, string>
     */
    private function resolveSourcesIndividually(array $sources, ProductDraft $draft, ?callable $progress = null, ?int $telegramUpdateId = null): array
    {
        $telegramUpdateId ??= $draft->telegram_update_id;
        $resolved = [];
        $category = Category::query()
            ->where('slug', trim((string) $draft->category))
            ->first();
        $categoryStrategy = $category?->gallerySearchStrategy() ?? Category::GALLERY_SEARCH_AUTO;
        $minimumVerifiedImages = $category?->minimumVerifiedImages() ?? 3;
        $activeRecipeOnly = $categoryStrategy === Category::GALLERY_SEARCH_VISION_FIRST;
        $resolveLimit = (int) config('product-images.resolve_limit', 16);

        foreach ($sources as $source) {
            $pageUrl = is_string($source['url'] ?? null) ? $source['url'] : null;

            if (! $pageUrl) {
                continue;
            }
            $source['_minimum_verified_images'] = $minimumVerifiedImages;
            $source['_category_slug'] = trim((string) $draft->category) !== '' ? trim((string) $draft->category) : null;
            $source['_minimum_image_width'] = $category?->minimumImageWidth();
            $source['_minimum_image_height'] = $category?->minimumImageHeight();

            $pageImages = $progress
                ? $this->resolver->resolve(
                    [$source],
                    $resolveLimit,
                    fn (string $level, string $message) => $progress($message),
                    $telegramUpdateId,
                    activeRecipeOnly: $activeRecipeOnly,
                )
                : $this->resolver->resolve(
                    [$source], $resolveLimit, telegramUpdateId: $telegramUpdateId, activeRecipeOnly: $activeRecipeOnly
                );

            $runtimeContext = collect($pageImages)
                ->map(fn (string $imageUrl): ?array => $this->resolver->sourceContextForImage($imageUrl))
                ->first(fn (mixed $context): bool => is_array($context));
            // Some stores use opaque/numeric product URLs and the browser
            // extractor may have no richer runtime metadata. Preserve the
            // Web Search title/SKU evidence instead of degrading that exact
            // card to a bare URL and rejecting it later as unconfirmed.
            $runtimeContext ??= [...$source, 'url' => $pageUrl];

            $finalPageUrl = is_string($runtimeContext['url'] ?? null)
                ? $runtimeContext['url']
                : $pageUrl;
            $runtimeContext = [...$runtimeContext, 'url' => $finalPageUrl];
            $identityRejected = $this->identityMatcher->conflictsSource($draft, $runtimeContext)
                || ($this->identityMatcher->requiresExactIdentifier($draft)
                    && ! $this->identityMatcher->supportsSource($draft, $runtimeContext));

            if ($identityRejected) {
                $this->attempts->record([
                    'telegram_update_id' => $telegramUpdateId,
                    'product_draft_id' => $draft->id,
                    'product_url' => $finalPageUrl,
                    'actor' => 'identity_validator',
                    'phase' => 'source_selection',
                    'action' => 'validate_discovery_runtime_identity',
                    'status' => 'failed',
                    'decision' => 'reject_runtime_identifier_mismatch',
                    'input' => ['requested_url' => $pageUrl],
                    'output' => ['final_source_context' => $runtimeContext],
                ]);
                $progress?->__invoke('Страница резервного поиска пропущена после открытия: конечная карточка не подтверждает точную модель/SKU · '.$finalPageUrl);

                continue;
            }

            foreach ($pageImages as $imageUrl) {
                $key = ProductImageStorage::normalizeCandidateUrl($imageUrl);
                $context = $this->resolver->sourceContextForImage($imageUrl) ?? $runtimeContext;
                $finalPageUrl = is_string($context['url'] ?? null) ? $context['url'] : $pageUrl;
                $this->sourcePagesByImageUrl[$key] = $finalPageUrl;
                $this->sourceContextsByImageUrl[$key] = $context;
            }

            $resolved = [...$resolved, ...$pageImages];
        }

        return array_values(array_unique($resolved));
    }

    /**
     * Apply result limits per logical product page, then globally. This keeps
     * a confirmed gallery atomic and prevents one page with many renditions
     * from evicting every frame of the next independent exact card.
     *
     * @param  array<int, string>  $urls
     * @return array<int, string>
     */
    private function limitCandidateUrlsBySource(array $urls): array
    {
        $globalLimit = max(1, (int) config('product-images.ai_result_limit', 20));
        $perSourceLimit = max(1, min(
            $globalLimit,
            (int) config('product-images.max_images', 10),
        ));

        return collect($urls)
            ->values()
            ->map(function (string $url, int $index): array {
                $key = ProductImageStorage::normalizeCandidateUrl($url);
                $pageUrl = $this->sourceContextsByImageUrl[$key]['url']
                    ?? $this->sourcePagesByImageUrl[$key]
                    ?? null;

                return [
                    'url' => $url,
                    'index' => $index,
                    'group' => is_string($pageUrl) && filter_var($pageUrl, FILTER_VALIDATE_URL)
                        ? 'page:'.rtrim($pageUrl, '/')
                        : 'image:'.sha1($url),
                    'gallery_rank' => $this->resolver->isConfirmedGalleryImage($url)
                        ? 2
                        : ($this->resolver->isPartialGalleryImage($url) ? 1 : 0),
                ];
            })
            ->groupBy('group')
            ->map(fn ($group): array => [
                'first_index' => $group->min('index'),
                'gallery_rank' => $group->max('gallery_rank'),
                'items' => $group
                    ->sort(fn (array $left, array $right): int => ($right['gallery_rank'] <=> $left['gallery_rank'])
                        ?: ($left['index'] <=> $right['index'])
                    )
                    ->take($perSourceLimit)
                    ->values(),
            ])
            ->sort(fn (array $left, array $right): int => ($right['gallery_rank'] <=> $left['gallery_rank'])
                ?: ($left['first_index'] <=> $right['first_index'])
            )
            ->flatMap(fn (array $group) => $group['items'])
            ->take($globalLimit)
            ->pluck('url')
            ->values()
            ->all();
    }
}
