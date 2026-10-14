<?php

namespace App\Services\Photos;

use App\Services\Products\ImageUrlHeuristics;
use App\Services\Products\ProductImageResolver;

/** Orders photo candidates using existing provenance. Does not fetch, judge, or store images. */
class CandidateUrlOrdering
{
    public function __construct(private readonly ProductImageResolver $resolver) {}

    public function cleanUrls(array $urls, ?string $variantHint = null): array
    {
        $limit = (int) config('product-images.download_limit', 20);

        $ranked = collect($urls)
            ->filter(fn (mixed $url): bool => is_string($url))
            ->map(fn (string $url): string => ImageUrls::normalizeCandidateUrl($url))
            ->filter(fn (string $url): bool => $url !== '' && ! $this->looksLikeJunk($url))
            ->map(fn (string $url): array => [
                'url' => $url,
                'gallery_rank' => $this->resolver->isConfirmedGalleryImage($url)
                    ? 2
                    : ($this->resolver->isPartialGalleryImage($url) ? 1 : 0),
                'variant_rank' => ImageUrls::candidateMatchesVariant($url, $variantHint) ? 1 : 0,
                'quality' => ImageUrls::candidateUrlQualityScore($url),
            ]);

        // A family landing page carries every colourway at once, and the
        // download limit then decides which colour the card gets by whichever
        // renditions happened to score higher. Seen live on a Surface Laptop in
        // Platinum: the same hero shot existed as ...-platinum-... and
        // ...-ocean-..., and only the limit stood between the card and a blue
        // laptop. When the product has a colour and some candidates name it,
        // those come first - no list of colour names, just the word the draft
        // already knows. When nothing names it the signal is absent and the
        // order is untouched, because inventing one would be worse than none.
        $namesTheVariant = $ranked->contains(fn (array $item): bool => $item['variant_rank'] === 1);
        $ranked = $ranked
            ->sort(fn (array $left, array $right): int => ($right['gallery_rank'] <=> $left['gallery_rank'])
                ?: ($namesTheVariant ? ($right['variant_rank'] <=> $left['variant_rank']) : 0)
                ?: ($right['quality'] <=> $left['quality'])
            )
            ->unique(fn (array $item): string => ImageUrls::imageAssetKey($item['url']))
            ->values();

        // A trained/confirmed (or partially confirmed) gallery must never be
        // truncated away by the generic candidate-count cap meant to bound
        // how many *unverified* static URLs get tried - real case: a Dell
        // page with a full 10-image confirmed gallery plus ~50 unrelated
        // static "feature module" images lost 9 of the 10 confirmed photos
        // here because they scored lower by size/quality alone and fell
        // outside download_limit before gallery_rank was ever considered
        // downstream. Only the unranked tail counts against the limit.
        $confirmedOrPartialCount = $ranked->filter(fn (array $item): bool => $item['gallery_rank'] > 0)->count();

        return $ranked
            ->take(max($limit, $confirmedOrPartialCount))
            ->pluck('url')
            ->all();
    }

    public function looksLikeJunk(string $url): bool
    {
        return ImageUrlHeuristics::containsMarker($url, [
            ...ImageUrlHeuristics::COMMON_MARKERS,
            ...ImageUrlHeuristics::THUMBNAIL_MARKERS,
            ...ImageUrlHeuristics::TRACKING_MARKERS,
            ...ImageUrlHeuristics::ASSET_MARKERS,
            'avatar', 'icon-', '/icon/', '/flags/', 'locale-flag', '/blogs/', '/category/icons/', 'banner',
        ]);
    }
}
