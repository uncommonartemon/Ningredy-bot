<?php

namespace App\Services\Products;

use App\Ai\Agents\ProductImageDiscoveryAgent;
use App\Models\AiRun;
use App\Models\ProductDraft;
use App\Services\Ai\AiSettings;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Throwable;

class ProductImageCandidateDiscovery
{
    public function __construct(
        private readonly ProductImageResolver $resolver,
        private readonly WikimediaImageSearch $wikimedia,
        private readonly ProductSourcePriority $sourcePriority,
    ) {}

    /** @return array<int, string> */
    public function find(ProductDraft $draft, array $excludedUrls = []): array
    {
        $excludedUrls = collect($excludedUrls)
            ->filter(fn (mixed $url): bool => is_string($url) && $url !== '')
            ->unique()
            ->values()
            ->all();
        $sources = $this->imageSources($this->sourcePriority->sortSources($draft->sources ?? [], $draft->brand));
        $knownSourceUrls = $this->resolver->resolve($sources, 16);
        $preferredUrls = $this->withoutExcluded($this->sourcePriority->sortUrls(
            $knownSourceUrls,
            $draft->brand,
            $sources,
        ), $excludedUrls);

        $trustedDirectUrls = array_values(array_filter($preferredUrls, fn (string $url): bool => $this->looksLikeCatalogImage($url)
            && in_array(
                $this->sourcePriority->classify($url, $draft->brand, $sources),
                ['official_english', 'official_localized', 'amazon', 'trusted_retailer'],
                true,
            )
        ));

        if (count($trustedDirectUrls) >= (int) config('product-images.public_source_target', 6)) {
            return $trustedDirectUrls;
        }

        $wikimediaUrls = $this->wikimedia->find($draft, 10);
        $availableUrls = $this->withoutExcluded($this->sourcePriority->sortUrls(
            [...$preferredUrls, ...$wikimediaUrls],
            $draft->brand,
            $sources,
        ), $excludedUrls);

        $provider = app(AiSettings::class)->providerFor('product_image_discovery');
        $model = app(AiSettings::class)->modelFor('product_image_discovery');
        $prompt = $this->prompt($draft, $excludedUrls);
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
                ...$this->candidateUrls($cached->response, $draft, $sources),
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
                timeout: (int) config('services.product_image_discovery.timeout', 75),
            );
            $data = Validator::make($response->toArray(), [
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
                ...$this->candidateUrls($data, $draft, $sources),
            ], $draft->brand, $sources), $excludedUrls);
        } catch (Throwable $exception) {
            $run->update([
                'status' => 'failed',
                'error' => mb_substr($exception->getMessage(), 0, 5000),
                'completed_at' => now(),
            ]);
            if ($availableUrls !== []) {
                Log::warning('AI product image discovery failed; using Wikimedia candidates.', [
                    'draft_id' => $draft->id,
                    'error' => $exception->getMessage(),
                ]);

                return $availableUrls;
            }

            report($exception);
            throw $exception;
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

        if (str_contains($lower, '.svg')
            || str_contains($lower, 'favicon')
            || str_contains($lower, 'logo')
            || str_contains($lower, 'sprite')
            || str_contains($lower, 'placeholder')
            || str_contains($lower, '/header/')
            || str_contains($lower, '/icons/')
            || str_contains($lower, '/w48')
            || str_contains($lower, '/w64')
            || str_contains($lower, '/w96')
            || str_contains($lower, '/w184')
        ) {
            return false;
        }

        return preg_match('#\.(?:jpe?g|png|webp|avif)(?:\?|$)#i', $lower) === 1
            || preg_match('#/(?:w[3-9]\d{2,}|h[3-9]\d{2,})(?:\?|/|$)#i', $lower) === 1;
    }

    /** @param array<int, string> $excludedUrls */
    private function prompt(ProductDraft $draft, array $excludedUrls = []): string
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
            ->filter(fn (mixed $url): bool => is_string($url))
            ->take(8)
            ->implode("\n");
        $excluded = collect($excludedUrls)->take(12)->implode("\n");

        return <<<PROMPT
            [product-image-discovery:v6]
            Find current, downloadable catalog image candidates for this exact product:
            Title: {$draft->title}
            Brand: {$draft->brand}
            Exact model: {$draft->model}
            Required color/version: {$draft->color}
            Identifying specifications: {$specifications}
            Already known product pages:
            {$knownPages}
            Previously used image URLs that must not be returned again:
            {$excluded}

            Search the exact quoted model and required color/version with terms such as product, gallery,
            front, rear, packaging, and the strongest model/part identifiers. The visible product color
            must match the required color when one is specified. Check a live English/US official
            manufacturer product or gallery HTML page first, Amazon.com second, then reputable retailer
            HTML product pages. Do not use PDF, PSREF/spec sheets, support/download/manual pages,
            certifications, generic family pages, or any previously used image URL above. Prefer working
            page URLs from several domains; our server extracts their galleries. Return a direct image URL
            only when the current search result actually exposes that exact URL. Do not reconstruct or
            guess CDN paths.
            PROMPT;
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
    private function candidateUrls(array $data, ProductDraft $draft, array $sources): array
    {
        $imageUrls = array_values(array_filter(
            $data['image_urls'] ?? [],
            fn (mixed $url): bool => is_string($url),
        ));
        $pageUrls = array_values(array_filter(
            $data['page_urls'] ?? [],
            fn (mixed $url): bool => is_string($url),
        ));
        $pageUrls = $this->sourcePriority->sortUrls($pageUrls, $draft->brand, $sources);
        $resolved = $this->resolver->resolve(
            array_map(fn (string $url): array => ['url' => $url], $pageUrls),
            16,
        );

        return array_slice($this->sourcePriority->sortUrls(
            [...$resolved, ...$imageUrls],
            $draft->brand,
            $sources,
        ), 0, 20);
    }
}
