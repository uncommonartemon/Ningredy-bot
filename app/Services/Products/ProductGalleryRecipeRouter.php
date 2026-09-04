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
            // Being followed by the last segment is not proof of anything - a
            // collection page ends in a word too. Only an actual id counts.
            $followedByProductId = $index + 1 <= $last && $this->looksDynamic($segments[$index + 1]);

            if ($index < $last && ($this->looksDynamic($segment)
                || ($followedByProductId && $this->looksLikeProductName($segment)))) {
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
     * The one way an operator blocks or unblocks a whole shop, wherever the
     * button lives. Both operator paths used to write their own version: the
     * Telegram one created the domain-scoped row that every check understands,
     * while the Filament one flipped whichever recipe row happened to be open -
     * usually a single path - and its own confirmation still said the domain
     * would not be used for any product. Blocking marks every existing row too,
     * so nothing already trained slips through a check that reads only one.
     */
    public function blockDomain(string $domain, ?string $reason = null): void
    {
        $domain = $this->domainForUrl('https://'.$domain) ?: strtolower(trim($domain));

        if ($domain === '') {
            return;
        }

        $wildcard = ProductGalleryRecipe::query()
            ->where('domain', $domain)
            ->where('path_pattern', '*')
            ->first();

        // Only the domain-scoped row is written. Marking every path row as well
        // reads as thorough and destroys state: a path blocked earlier for its
        // own failures loses its own reason, and unblocking the shop later
        // would silently release it too. Every check that matters already reads
        // this row - domainIsBlocked() and ProductSourcePriority both treat a
        // blocked '*' as the whole shop - so one row is both sufficient and
        // reversible.
        //
        // status is left alone on a row that already exists: a working recipe
        // may live at '*', and a temporary ban that disables it permanently -
        // unblocking never restored the status - costs a trained recipe.
        $attributes = [
            'source_blocked' => true,
            'source_block_reason' => $reason,
            'source_blocked_at' => now(),
            'retry_after' => null,
        ];

        if (! $wildcard) {
            $attributes['status'] = 'disabled';
        }

        ProductGalleryRecipe::query()->updateOrCreate(
            ['domain' => $domain, 'path_pattern' => '*'],
            $attributes,
        );
    }

    public function unblockDomain(string $domain): void
    {
        $domain = $this->domainForUrl('https://'.$domain) ?: strtolower(trim($domain));

        if ($domain === '') {
            return;
        }

        // The mirror of blockDomain(): the domain-scoped row is released and
        // nothing else is touched. A path that blocked itself stays blocked -
        // lifting the shop's ban is not a verdict on a recipe that failed on
        // its own, and re-blocking a path is not something an operator can do
        // from here.
        ProductGalleryRecipe::query()
            ->where('domain', $domain)
            ->where('path_pattern', '*')
            ->update([
                'source_blocked' => false,
                'source_block_reason' => null,
                'source_blocked_at' => null,
            ]);
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

        // A row scoped to the whole domain is an operator saying "this shop, no
        // товар at all", and it has to outrank every path. recipeForUrl() falls
        // back to that row only when no exact recipe exists, so asking it alone
        // let the block be bypassed on precisely the pages the bot visits most:
        // the ones already trained. The Telegram confirmation promised the shop
        // would never be used again while every trained path kept using it.
        if (ProductGalleryRecipe::query()
            ->where('domain', $domain)
            ->where('path_pattern', '*')
            ->where('source_blocked', true)
            ->exists()) {
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

    /**
     * Some shops write the product's whole name into the path
     * ("/product/predator-helios-neo-16-ai-phn16-73-773d-16-gaming-notebook-wqxg/8500067"),
     * so every laptop looked like a page type nobody had ever seen and paid for
     * its own training. Measured on the live case: one shop, two products, two
     * recipes, the second trained minutes after the first.
     *
     * The bar is deliberately high - this many words in one segment is a title,
     * while a taxonomy node is short ("dell-laptops", "gaming-laptops",
     * "xps-13-laptop"). Collapsing those would merge genuinely different page
     * layouts onto one recipe, which is what path scoping exists to prevent.
     */
    /**
     * Length and word count alone were not enough:
     * "computer-components-and-accessories" is a department, and wildcarding it
     * merged every page under /store onto one recipe. Requiring a digit fixed
     * that and broke the opposite case, because a product can be named without
     * one - /product/apple-macbook-air-midnight/8500067 went back to a recipe
     * per laptop.
     *
     * Position settles what the words cannot: the caller only asks about a
     * segment that is immediately followed by the product's id. A department
     * is followed by another department, never by an id.
     */
    private function looksLikeProductName(string $segment): bool
    {
        return mb_strlen($segment) >= 20 && substr_count($segment, '-') >= 3;
    }
}
