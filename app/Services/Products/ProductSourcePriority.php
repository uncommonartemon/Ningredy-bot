<?php

namespace App\Services\Products;

use App\Models\ProductGalleryRecipe;
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
     * Reuse-ready sources already ahead of every trainable one are not
     * duplicated - the ranking tries them first anyway, so a second entry would
     * buy nothing but a repeated browser run. Neither is a source with nothing
     * to reuse: the staging loop only accepts a gallery on Playwright-confirmed
     * frames, so such a source cannot win a no-training pass. A search that may
     * not train at all (a Vision-first category) walks the list exactly once.
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

        $reuseFirst = $firstTrainable === null
            ? []
            : array_filter(array_slice($sources, $firstTrainable + 1), $reusable);

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

    /** @return array<int, string> */
    public function blockedDomains(): array
    {
        if ($this->blockedDomainsCache !== null) {
            return $this->blockedDomainsCache;
        }

        try {
            return $this->blockedDomainsCache = ProductGalleryRecipe::query()
                ->where('source_blocked', true)
                ->pluck('domain')
                ->filter(fn (mixed $domain): bool => is_string($domain) && $domain !== '')
                ->map(fn (string $domain): string => self::host('https://'.$domain))
                ->unique()->values()->all();
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
