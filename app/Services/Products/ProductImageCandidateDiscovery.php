<?php

namespace App\Services\Products;

use App\Ai\Agents\ProductImageDiscoveryAgent;
use App\Models\AiRun;
use App\Models\ProductDraft;
use App\Services\Ai\AiSettings;
use App\Services\Ai\ProductSearchTimeBudget;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Throwable;

class ProductImageCandidateDiscovery
{
    /** @var array<string, string> */
    private array $sourcePagesByImageUrl = [];

    public function __construct(
        private readonly ProductImageResolver $resolver,
        private readonly WikimediaImageSearch $wikimedia,
        private readonly ProductSourcePriority $sourcePriority,
    ) {}

    public function sourcePageForImage(string $imageUrl): ?string
    {
        return $this->sourcePagesByImageUrl[$imageUrl] ?? null;
    }

    /** @return array<int, string> */
    /** @param null|callable(string): void $progress */
    public function find(ProductDraft $draft, array $excludedUrls = [], bool $skipKnownSources = false, ?callable $progress = null): array
    {
        $this->sourcePagesByImageUrl = [];
        $excludedUrls = collect($excludedUrls)
            ->filter(fn (mixed $url): bool => is_string($url) && $url !== '')
            ->unique()
            ->values()
            ->all();
        $excludedSourceUrls = collect([
            ...($draft->excluded_gallery_source_urls ?? []),
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
            : $this->resolveSourcesIndividually($sources, $draft);
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

        if (! $timeBudget->canStart($draft->telegram_update_id, 30)) {
            $progress?->__invoke('Резерв времени достигнут: дополнительный AI-поиск источников пропущен, сохраняю уже полученный результат.');

            return $availableUrls;
        }

        $discoveryTimeout = $timeBudget->timeoutFor(
            $draft->telegram_update_id,
            $settings->imageDiscoveryTimeoutSeconds(),
        );
        $provider = $settings->providerFor('product_image_discovery');
        $model = $settings->modelFor('product_image_discovery');
        $prompt = $this->prompt($draft, $excludedUrls, $excludedSourceUrls);
        $cached = AiRun::query()
            ->where('telegram_update_id', $draft->telegram_update_id)
            ->where('provider', $provider)
            ->where('model', $model)
            ->where('status', 'completed')
            ->where('prompt', $prompt)
            ->latest('id')
            ->first();

        if ($cached && is_array($cached->response)) {
            return $this->withoutExcluded($this->sourcePriority->sortUrls([
                ...$availableUrls,
                ...$this->candidateUrls($cached->response, $draft, $sources, $progress),
            ], $draft->brand, $sources), $excludedUrls);
        }

        $run = AiRun::query()->create([
            'telegram_update_id' => $draft->telegram_update_id,
            'provider' => $provider,
            'model' => $model,
            'status' => 'running',
            'prompt' => $prompt,
            'started_at' => now(),
        ]);

        try {
            $response = ProductImageDiscoveryAgent::make()->prompt(
                $prompt,
                provider: $provider,
                model: $model,
                timeout: $discoveryTimeout,
            );
            $normalizedResponse = $response->toArray();

            foreach (['image_urls', 'page_urls'] as $urlField) {
                $normalizedResponse[$urlField] = collect($normalizedResponse[$urlField] ?? [])
                    ->filter(fn (mixed $url): bool => is_string($url))
                    ->map(fn (string $url): string => trim($url))
                    ->filter(fn (string $url): bool => mb_strlen($url) <= 2048
                        && filter_var($url, FILTER_VALIDATE_URL) !== false
                        && in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true))
                    ->unique()
                    ->take(12)
                    ->values()
                    ->all();
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
                ...$this->candidateUrls($data, $draft, $sources, $progress),
            ], $draft->brand, $sources), $excludedUrls);
        } catch (Throwable $exception) {
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
            $progress?->__invoke('Дополнительный AI-поиск не ответил вовремя; продолжаю без него, основной запрос не перезапускаю.');

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
    private function prompt(ProductDraft $draft, array $excludedUrls = [], array $excludedSourceUrls = []): string
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
    private function candidateUrls(array $data, ProductDraft $draft, array $sources, ?callable $progress = null): array
    {
        $excludedSources = $draft->excluded_gallery_source_urls ?? [];
        $pairedSources = collect($data['sources'] ?? [])
            ->filter(fn (mixed $source): bool => is_array($source)
                && is_string($source['page_url'] ?? null)
                && ! $this->sourceExcluded($source['page_url'], $excludedSources))
            ->values();
        $imageUrls = $pairedSources->isEmpty()
            ? array_values(array_filter(
                $data['image_urls'] ?? [],
                fn (mixed $url): bool => is_string($url) && ! $this->sourceExcluded($url, $excludedSources),
            ))
            : $pairedSources->flatMap(function (array $source): array {
                $pageUrl = $source['page_url'];

                return collect($source['image_urls'] ?? [])
                    ->filter(fn (mixed $url): bool => is_string($url))
                    ->each(fn (string $url) => $this->sourcePagesByImageUrl[$url] = $pageUrl)
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
        );

        return array_slice($this->sourcePriority->sortUrls(
            [...$resolved, ...$imageUrls],
            $draft->brand,
            $sources,
        ), 0, (int) config('product-images.ai_result_limit', 20));
    }

    /**
     * Resolve every product page separately so an image never loses the exact
     * card it came from when multiple stores share the same CDN host.
     *
     * @param  array<int, array<string, mixed>>  $sources
     * @return array<int, string>
     */
    private function resolveSourcesIndividually(array $sources, ProductDraft $draft, ?callable $progress = null): array
    {
        $resolved = [];
        $resolveLimit = (int) config('product-images.resolve_limit', 16);

        foreach ($sources as $source) {
            $pageUrl = is_string($source['url'] ?? null) ? $source['url'] : null;

            if (! $pageUrl) {
                continue;
            }

            $pageImages = $progress
                ? $this->resolver->resolve(
                    [$source],
                    $resolveLimit,
                    fn (string $level, string $message) => $progress($message),
                    $draft->telegram_update_id,
                )
                : $this->resolver->resolve([$source], $resolveLimit, telegramUpdateId: $draft->telegram_update_id);

            foreach ($pageImages as $imageUrl) {
                $this->sourcePagesByImageUrl[$imageUrl] = $pageUrl;
            }

            $resolved = [...$resolved, ...$pageImages];
        }

        return array_values(array_unique($resolved));
    }
}
