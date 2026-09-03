<?php

namespace App\Services\Products;

use App\Models\ProductGalleryRecipe;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ProductGalleryRecipeRouter
{
    public function domainForUrl(string $url): string
    {
        return strtolower(rtrim((string) parse_url($url, PHP_URL_HOST), '.'));
    }

    /**
     * A recipe belongs to a reusable page family, never to one literal
     * product URL. The last path segment is treated as the product slug/id;
     * earlier obviously dynamic ids are wildcarded as well.
     */
    public function pathPatternForUrl(string $url): string
    {
        $path = rawurldecode((string) (parse_url($url, PHP_URL_PATH) ?: '/'));
        $segments = collect(preg_split('~/+~', trim($path, '/')) ?: [])
            ->filter(fn (mixed $segment): bool => is_string($segment) && trim($segment) !== '')
            ->map(fn (string $segment): string => trim($segment))
            ->values()
            ->all();

        if ($segments === []) {
            return '/';
        }

        $last = count($segments) - 1;
        $dynamicTail = $last;

        foreach ($segments as $index => $segment) {
            if ($index < $last && $this->looksDynamic($segment)) {
                $dynamicTail = min($dynamicTail, $index);
                break;
            }
        }

        for ($index = $dynamicTail; $index <= $last; $index++) {
            $segments[$index] = '*';
        }

        return mb_substr('/'.implode('/', $segments), 0, 255);
    }

    public function exactRecipeForUrl(string $url): ?ProductGalleryRecipe
    {
        $domain = $this->domainForUrl($url);

        if ($domain === '') {
            return null;
        }

        $pathPattern = $this->pathPatternForUrl($url);
        $primary = ProductGalleryRecipe::query()
            ->where('domain', $domain)
            ->where('path_pattern', $pathPattern)
            ->first();

        if ($primary) {
            return $primary;
        }

        return ProductGalleryRecipe::query()
            ->where('domain', $domain)
            ->get()
            ->first(fn (ProductGalleryRecipe $recipe): bool => in_array(
                $pathPattern,
                is_array($recipe->compatible_path_patterns) ? $recipe->compatible_path_patterns : [],
                true,
            ));
    }

    /**
     * Exact path scope wins. The old domain-wide * row remains a
     * compatibility fallback until that path has its own recipe.
     */
    public function recipeForUrl(string $url): ?ProductGalleryRecipe
    {
        if ($exact = $this->exactRecipeForUrl($url)) {
            return $exact;
        }

        $domain = $this->domainForUrl($url);

        return $domain === '' ? null : ProductGalleryRecipe::query()
            ->where('domain', $domain)
            ->where('path_pattern', '*')
            ->first();
    }

    public function activeRecipeForUrl(string $url): ?ProductGalleryRecipe
    {
        $recipe = $this->recipeForUrl($url);

        return $recipe?->status === 'active' ? $recipe : null;
    }

    public function bestActiveRecipeForDomain(string $url): ?ProductGalleryRecipe
    {
        $domain = $this->domainForUrl($url);

        return $domain === '' ? null : ProductGalleryRecipe::query()
            ->where('domain', $domain)
            ->where('status', 'active')
            ->orderByDesc('success_count')
            ->orderByDesc('last_success_at')
            ->first();
    }

    /**
     * Active recipes from the same domain that have not yet been confirmed
     * for this path. They are cheap Playwright hypotheses, not AI decisions.
     *
     * @return Collection<int, ProductGalleryRecipe>
     */
    public function compatibleCandidatesForUrl(string $url, ?int $excludeRecipeId = null, int $limit = 3): Collection
    {
        $domain = $this->domainForUrl($url);

        if ($domain === '' || $limit < 1) {
            return collect();
        }

        return ProductGalleryRecipe::query()
            ->where('domain', $domain)
            ->where('status', 'active')
            ->where('source_blocked', false)
            ->when($excludeRecipeId !== null, fn ($query) => $query->whereKeyNot($excludeRecipeId))
            ->orderByDesc('success_count')
            ->orderByDesc('last_success_at')
            ->orderBy('failure_count')
            ->limit($limit)
            ->get();
    }

    /**
     * Remember that a strictly validated recipe also serves this path. The
     * recipe body stays single-source; only its confirmed scopes grow.
     */
    public function bindCompatiblePath(ProductGalleryRecipe $recipe, string $url): ProductGalleryRecipe
    {
        $pathPattern = $this->pathPatternForUrl($url);

        if ($pathPattern === $recipe->path_pattern || $recipe->path_pattern === '*') {
            return $recipe;
        }

        return DB::transaction(function () use ($recipe, $pathPattern): ProductGalleryRecipe {
            $locked = ProductGalleryRecipe::query()->lockForUpdate()->findOrFail($recipe->id);
            $patterns = collect(is_array($locked->compatible_path_patterns)
                ? $locked->compatible_path_patterns
                : [])
                ->filter(fn (mixed $pattern): bool => is_string($pattern) && trim($pattern) !== '')
                ->push($pathPattern)
                ->unique()
                ->values()
                ->all();

            $locked->update(['compatible_path_patterns' => $patterns]);

            return $locked->refresh();
        });
    }

    public function domainHasActiveRecipe(string $url): bool
    {
        $domain = $this->domainForUrl($url);

        return $domain !== '' && ProductGalleryRecipe::query()
            ->where('domain', $domain)
            ->where('status', 'active')
            ->exists();
    }

    /**
     * Blocks are recorded per path, so one product page meeting a CAPTCHA used
     * to take Playwright away from the whole domain - including paths that were
     * training successfully at that moment. A site that really refuses the
     * browser refuses more than one page, so the domain-wide verdict now has to
     * be earned by more than one blocked path; a single one keeps the block to
     * itself.
     */
    public const DOMAIN_BLOCK_THRESHOLD = 2;

    public function domainIsBlocked(string $url): bool
    {
        $domain = $this->domainForUrl($url);

        if ($domain === '') {
            return false;
        }

        if (ProductGalleryRecipe::query()->where('domain', $domain)->where('source_blocked', true)->count()
            >= self::DOMAIN_BLOCK_THRESHOLD) {
            return true;
        }

        // Below the threshold only the blocked path itself stays off-limits.
        return $this->recipeForUrl($url)?->source_blocked === true;
    }

    public function recipeForTraining(string $url, bool $reuseLegacyFallback = false): ProductGalleryRecipe
    {
        if ($reuseLegacyFallback) {
            $legacy = $this->recipeForUrl($url);

            if ($legacy?->path_pattern === '*') {
                return $legacy;
            }
        }

        return ProductGalleryRecipe::query()->firstOrCreate(
            [
                'domain' => $this->domainForUrl($url),
                'path_pattern' => $this->pathPatternForUrl($url),
            ],
            ['status' => 'learning'],
        );
    }

    /**
     * An id in the middle of the path used to survive into the pattern, so
     * every product on such a shop got its own scope and its own training run:
     * /c/product/1876268-REG/asus... became "/c/product/1876268-REG/*" instead
     * of "/c/product/*", and the next product looked like an unknown page type
     * again. Measured against real listings, an id there is either digits with
     * a suffix or an all-caps alphanumeric code, so both are recognised now.
     */
    private function looksDynamic(string $segment): bool
    {
        return preg_match('/^\d{4,}(?:[-_.][A-Za-z0-9]+)*$/', $segment) === 1
            || preg_match('/^(?=[A-Z0-9]*\d)[A-Z0-9]{8,}$/', $segment) === 1
            || preg_match('/^[0-9a-f]{16,}$/i', $segment) === 1
            || preg_match('/^[0-9a-f]{8}-[0-9a-f-]{27,}$/i', $segment) === 1;
    }
}
