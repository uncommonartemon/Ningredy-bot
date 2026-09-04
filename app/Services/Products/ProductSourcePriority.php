<?php

namespace App\Services\Products;

use App\Models\ProductGalleryRecipe;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Throwable;

class ProductSourcePriority
{
    /** @var array<int, string>|null */
    private ?array $blockedDomainsCache = null;

    public function __construct(
        private readonly ProductSourceMetrics $metrics,
        private readonly ProductGalleryRecipeRouter $recipeRouter,
    ) {}

    /** @param array<int, mixed> $sources @return array<int, array<string, mixed>> */
    public function sortSources(array $sources, ?string $brand): array
    {
        return collect($sources)
            ->filter(fn (mixed $source): bool => is_array($source)
                && is_string($source['url'] ?? null)
                && ! $this->isBlockedUrl($source['url']))
            ->values()
            ->map(fn (array $source, int $index): array => [
                'source' => $source,
                'index' => $index,
                'score' => $this->score($source['url']),
            ])
            ->sort(fn (array $left, array $right): int => ($right['score'] <=> $left['score']) ?: ($left['index'] <=> $right['index']))
            ->pluck('source')
            ->values()
            ->all();
    }

    /**
     * The order the staging loop actually walks its sources in.
     *
     * Training a recipe is by far the most expensive step of a search, and it
     * used to happen on the first source before any later source was ever asked
     * whether it already has a working recipe - a draft whose third source was
     * an already-solved domain still paid for training on the first. A source
     * that can produce a gallery out of what already exists (its own active
     * recipe, or a compatible one from the same domain) is therefore pulled in
     * front of the first source that would have to be trained, marked
     * _reuse_only so the loop runs it without training; the full ranked list
     * then follows with training allowed, and runs only because that cheap pass
     * found nothing complete.
     *
     * The cheap pass keeps the ranking's own order: it is the whole reuse-ready
     * set, not only the sources that had to be moved. Queueing just the moved
     * ones let a weaker third source overtake a better first one that was
     * equally ready, which is the opposite of what the ranking decided. It is
     * built at all only when at least one reuse-ready source sits behind a
     * trainable one - otherwise the ranking already tries them first and a
     * second entry would buy nothing but a repeated browser run.
     *
     * A source with nothing to reuse is never queued twice: the staging loop
     * only accepts a gallery on Playwright-confirmed frames, so such a source
     * cannot win a no-training pass. A search that may not train at all (a
     * Vision-first category) walks the list exactly once.
     *
     * @param  array<int, array<string, mixed>>  $cardSources
     * @return array<int, array<string, mixed>>
     */
    public function reuseFirstQueue(array $cardSources, bool $trainingDisabled = false): array
    {
        $sources = array_values($cardSources);
        $reusable = fn (array $source): bool => (bool) ($source['_preflight_active_recipe'] ?? false)
            || (bool) ($source['_preflight_known_recipe_domain'] ?? false);
        $firstTrainable = null;

        foreach ($sources as $index => $source) {
            if (! $trainingDisabled && ! $reusable($source)) {
                $firstTrainable = $index;

                break;
            }
        }

        $movesSomething = $firstTrainable !== null
            && array_filter(array_slice($sources, $firstTrainable + 1), $reusable) !== [];
        $reuseFirst = $movesSomething ? array_filter($sources, $reusable) : [];

        return [
            ...array_map(
                fn (array $source): array => [...$source, '_reuse_only' => true],
                array_values($reuseFirst),
            ),
            ...array_map(
                fn (array $source): array => [...$source, '_reuse_only' => $trainingDisabled],
                $sources,
            ),
        ];
    }

    /**
     * $sourcePagesByUrl maps an image URL to the product page it was found on -
     * many manufacturer sites (HP, Dell, ...) host photos on a dedicated CDN
     * subdomain (e.g. hp.widen.net) that is entirely unrelated to the page
     * domain (www.hp.com) a gallery recipe is trained and scored against.
     * Scoring the raw image host would leave every such CDN image stuck at a
     * neutral score forever, even when it came from the same successful
     * extraction as images the page itself happens to host directly - so an
     * image is scored by its source page's domain when that mapping is known.
     *
     * @param  array<int, mixed>  $urls
     * @param  array<int, mixed>  $sources
     * @param  array<string, string>  $sourcePagesByUrl
     * @return array<int, string>
     */
    public function sortUrls(array $urls, ?string $brand, array $sources = [], array $sourcePagesByUrl = []): array
    {
        return collect($urls)
            ->filter(fn (mixed $url): bool => is_string($url) && $url !== '' && ! $this->isBlockedUrl($url))
            ->unique()
            ->values()
            ->map(fn (string $url, int $index): array => [
                'url' => $url,
                'index' => $index,
                'score' => $this->score($sourcePagesByUrl[$url] ?? $url),
            ])
            ->sort(fn (array $left, array $right): int => ($right['score'] <=> $left['score']) ?: ($left['index'] <=> $right['index']))
            ->pluck('url')
            ->values()
            ->all();
    }

    public function isBlockedUrl(string $url): bool
    {
        $host = self::host($url);

        return $host !== '' && collect($this->blockedDomains())
            ->contains(fn (string $blocked): bool => self::hostsMatch($host, $blocked));
    }

    /**
     * Shops this catalog has already learned to open, newest success first.
     *
     * Training a recipe is the most expensive thing a search does, and it only
     * pays back when the same shop comes up again. It almost never did: 111
     * recipes trained, 22 successes between them, and exactly two recipes ever
     * used a second time. The reason is upstream - the research agent is told
     * which domains are blocked and nothing about the ones we can already open,
     * so every search goes shopping somewhere new and pays full price again.
     * This is that missing half of the picture.
     *
     * @return array<int, string>
     */
    public function provenDomains(int $limit = 25): array
    {
        try {
            return ProductGalleryRecipe::query()
                ->where('status', 'active')
                ->where('success_count', '>', 0)
                ->where(fn ($query) => $query->whereNull('source_blocked')->orWhere('source_blocked', false))
                ->orderByDesc('success_count')
                ->orderByDesc('last_success_at')
                ->pluck('domain')
                ->filter(fn (mixed $domain): bool => is_string($domain) && $domain !== '')
                ->map(fn (string $domain): string => self::host('https://'.$domain))
                ->unique()
                ->take($limit)
                ->values()
                ->all();
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * One blocked path is not a blocked domain. The router draws that line at
     * DOMAIN_BLOCK_THRESHOLD and lets a single blocked path stop only itself -
     * but this list was built from any single row with source_blocked=true and
     * removed the whole domain from every ranking, so the source never reached
     * the staging loop the router would have let through. One shop refusing one
     * product page took its entire catalogue out of the search. The threshold
     * now comes from the router, so the two cannot drift apart again.
     *
     * @return array<int, string>
     */
    public function blockedDomains(): array
    {
        if ($this->blockedDomainsCache !== null) {
            return $this->blockedDomainsCache;
        }

        try {
            return $this->blockedDomainsCache = ProductGalleryRecipe::query()
                ->where('source_blocked', true)
                ->get(['domain', 'path_pattern'])
                ->filter(fn (ProductGalleryRecipe $recipe): bool => is_string($recipe->domain) && $recipe->domain !== '')
                ->groupBy(fn (ProductGalleryRecipe $recipe): string => self::host('https://'.$recipe->domain))
                // A row scoped to the whole domain says so itself and needs no
                // second opinion; separate paths have to agree with each other.
                ->filter(fn (Collection $blocked): bool => $blocked->count() >= ProductGalleryRecipeRouter::DOMAIN_BLOCK_THRESHOLD
                    || $blocked->contains(fn (ProductGalleryRecipe $recipe): bool => $recipe->path_pattern === '*'))
                ->keys()->values()->all();
        } catch (Throwable) {
            return $this->blockedDomainsCache = [];
        }
    }

    private function score(string $url): int
    {
        return $this->extractionScore($url);
    }

    /**
     * Source type is deliberately irrelevant to ordering. Official sites,
     * marketplaces and retailers all start neutral; only measured extraction
     * and Vision-acceptance history may move a domain up or down.
     */
    private function extractionScore(string $url): int
    {
        try {
            $matchingRecipe = $this->recipeRouter->recipeForUrl($url);
        } catch (Throwable) {
            return $this->metrics->score($url);
        }

        if ($matchingRecipe?->source_blocked || $matchingRecipe?->status === 'disabled') {
            return -1_000_000;
        }

        $measuredScore = $this->metrics->score($url);

        if ($measuredScore !== 0) {
            return $measuredScore;
        }

        $matchingActive = $matchingRecipe?->status === 'active';
        $recipe = $matchingActive
            ? $matchingRecipe
            : ($this->recipeRouter->bestActiveRecipeForDomain($url) ?? $matchingRecipe);

        if (! $recipe) {
            return 0;
        }

        // Preserve useful history gathered before product_source_stats existed.
        $successes = max(0, (int) $recipe->success_count);
        $failures = max(0, (int) $recipe->failure_count);
        $attempts = $successes + $failures;

        if ($recipe->status === 'active' && $successes > 0) {
            $smoothedRate = ($successes + 1) / ($attempts + 2);

            return ($matchingActive ? 50_000 : 5_000)
                + (int) round($smoothedRate * 5_000)
                + min(500, $successes);
        }

        return $failures > 0 ? -1 * min(10_000, $failures) : 0;
    }

    public static function hostsMatch(string $left, string $right): bool
    {
        $left = Str::after(Str::lower($left), 'www.');
        $right = Str::after(Str::lower($right), 'www.');

        return $left !== '' && $right !== '' && (
            $left === $right
            || str_ends_with($left, '.'.$right)
            || str_ends_with($right, '.'.$left)
        );
    }

    public static function host(string $url): string
    {
        return Str::after(Str::lower((string) parse_url($url, PHP_URL_HOST)), 'www.');
    }
}
