<?php

namespace App\Services\Products;

use App\Ai\Agents\ProductImageDiscoveryAgent;
use App\Models\AiRun;
use App\Models\ProductDraft;
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
    public function find(ProductDraft $draft): array
    {
        $sources = $this->sourcePriority->sortSources($draft->sources ?? [], $draft->brand);
        $knownSourceUrls = $this->resolver->resolve($sources, 16);
        $preferredUrls = $this->sourcePriority->sortUrls(
            $knownSourceUrls,
            $draft->brand,
            $sources,
        );

        if (count(array_filter($preferredUrls, fn (string $url): bool => in_array(
            $this->sourcePriority->classify($url, $draft->brand, $sources),
            ['official_english', 'official_localized', 'amazon'],
            true,
        ))) >= (int) config('product-images.public_source_target', 6)) {
            return $preferredUrls;
        }

        $wikimediaUrls = $this->wikimedia->find($draft, 10);
        $availableUrls = $this->sourcePriority->sortUrls(
            [...$preferredUrls, ...$wikimediaUrls],
            $draft->brand,
            $sources,
        );

        $provider = (string) config('services.product_image_discovery.provider', 'openai');
        $model = (string) config('services.product_image_discovery.model', 'gpt-5.4');
        $prompt = $this->prompt($draft);
        $cached = AiRun::query()
            ->where('telegram_update_id', $draft->telegram_update_id)
            ->where('provider', $provider)
            ->where('model', $model)
            ->where('status', 'completed')
            ->where('prompt', $prompt)
            ->latest('id')
            ->first();

        if ($cached && is_array($cached->response)) {
            return $this->sourcePriority->sortUrls([
                ...$availableUrls,
                ...$this->candidateUrls($cached->response, $draft, $sources),
            ], $draft->brand, $sources);
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

            return $this->sourcePriority->sortUrls([
                ...$availableUrls,
                ...$this->candidateUrls($data, $draft, $sources),
            ], $draft->brand, $sources);
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

    private function prompt(ProductDraft $draft): string
    {
        $specifications = collect($draft->specifications ?? [])
            ->map(fn (mixed $item): string => is_array($item)
                ? trim(($item['name'] ?? $item['key'] ?? '').': '.($item['value'] ?? ''), ': ')
                : '')
            ->filter()
            ->take(10)
            ->implode('; ');
        $knownPages = collect($this->sourcePriority->sortSources($draft->sources ?? [], $draft->brand))
            ->pluck('url')
            ->filter(fn (mixed $url): bool => is_string($url))
            ->take(8)
            ->implode("\n");

        return <<<PROMPT
            [product-image-discovery:v4]
            Find current, downloadable catalog image candidates for this exact product:
            Title: {$draft->title}
            Brand: {$draft->brand}
            Exact model: {$draft->model}
            Color/version: {$draft->color}
            Identifying specifications: {$specifications}
            Already known product pages:
            {$knownPages}

            Search the exact quoted model with terms such as product, gallery, front, rear, packaging,
            and the strongest model/part identifiers. Check a live English/US official manufacturer
            page first, Amazon.com second, then other reputable sources. Prefer working page URLs from
            several domains; our server extracts their galleries. Return a direct image URL only when
            the current search result actually exposes that exact URL. Do not reconstruct or guess CDN paths.
            PROMPT;
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
