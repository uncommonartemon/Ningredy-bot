<?php

namespace App\Services\Products;

use App\Services\ProductSearch\Research\ResearchSourceRoles;
use Illuminate\Support\Str;

class ProductResearchConfigurationGuard
{
    private const IDENTITY_KEYS = ['sku', 'mpn', 'ean', 'upc', 'gtin'];

    private const CONFIGURATION_KEYS = [
        'cpu', 'gpu', 'ram', 'storage', 'display', 'screen_size',
        'refresh_rate', 'color', 'pack_count', 'module_count',
    ];

    /** @return array<int, string> */
    public function issues(array $data): array
    {
        if (($data['status'] ?? null) !== 'found') {
            return [];
        }

        $specifications = collect($data['specifications'] ?? [])
            ->filter(fn (mixed $item): bool => is_array($item))
            ->keyBy('key');
        $identity = collect(self::IDENTITY_KEYS)
            ->map(fn (string $key): string => trim((string) data_get($specifications->get($key), 'value', '')))
            ->first(fn (string $value): bool => $value !== '' && ! $this->ambiguous($value));
        $issues = [];

        if (! $identity && ! $this->hasObservedFullModel($data)) {
            $issues[] = 'The selected identity is unresolved: provide an observed reusable SKU/MPN/GTIN or selected_identity with the full model, single_product scope, observed source URL and verbatim excerpt. A family title, source publisher type, or letters and digits alone cannot identify one configuration. Keep useful sources and facts; missing identity evidence is not not_found.';
        }
        // With a code already identifying the product, an honestly family-scoped
        // observation is provenance, not a second blocker (live #430: operator
        // label SKU kept, page model string names the family).
        if (! $identity && is_array($data['selected_identity'] ?? null)
            && ($data['selected_identity']['model_scope'] ?? null) !== 'single_product') {
            $issues[] = 'selected_identity still describes a product family or unknown scope, not one selected product.';
        }

        foreach (self::CONFIGURATION_KEYS as $key) {
            $value = trim((string) data_get($specifications->get($key), 'value', ''));

            if ($value !== '' && $this->ambiguous($value)) {
                $issues[] = $key.' describes alternatives instead of the installed/boxed value: '.$value;
            }
        }

        return $issues;
    }

    /**
     * Real-world spec text for one exact SKU is full of "up to", "or", and
     * "depending" that describe how one fixed component behaves - "GPU
     * Boost up to 175W", "refresh rate up to 240Hz", "PD or DP over
     * Thunderbolt" - not that several different components could be
     * installed. Live evidence: this over-broad list rejected a real ASUS
     * ROG Strix SCAR 18 and HP OMEN 16 Max search on ordinary boost-clock/
     * refresh-rate phrasing alone, with no way for the model to phrase a
     * genuine single-SKU spec that avoids every one of these common words.
     * Only phrases that specifically claim several different components or
     * SKUs are legitimate signals.
     */
    private function ambiguous(string $value): bool
    {
        return preg_match(
            '/\b(?:product\s+family|families|variants?|varies\s+by\s+(?:sku|region|market|config|configuration)|multiple\s+(?:skus?|configurations?|variants?)|select\s+skus?|depending\s+on\s+(?:sku|region|market|config|configuration))\b/i',
            $value,
        ) === 1;
    }

    private function hasObservedFullModel(array $data): bool
    {
        $model = trim((string) ($data['model'] ?? ''));
        $identity = $data['selected_identity'] ?? null;
        if ($model === '' || $this->ambiguous($model) || ! is_array($identity)
            || ($identity['basis'] ?? null) !== 'full_model' || ($identity['model_scope'] ?? null) !== 'single_product'
            || trim((string) ($identity['reason'] ?? '')) === '') {
            return false;
        }
        $url = trim((string) ($identity['source_url'] ?? ''));
        $source = collect($data['sources'] ?? [])->first(
            fn (mixed $source): bool => is_array($source)
                && self::samePage((string) ($source['url'] ?? ''), $url)
                && ResearchSourceRoles::mayIdentifySelectedProduct($source),
        );
        $text = static fn (string $value): string => Str::lower(Str::squish($value));
        $needle = $text($model);
        $observed = $text((string) ($identity['observed_model'] ?? ''));
        $excerpt = $text((string) ($identity['source_excerpt'] ?? ''));
        $contains = static fn (string $haystack): bool => preg_match(
            '/(?<![\p{L}\p{N}])'.preg_quote($needle, '/').'(?![\p{L}\p{N}])/u', $haystack) === 1;

        // The page must prove the WHOLE selected model: a verbatim excerpt that
        // contains it. A page naming more ("Brand Family MODEL-123 Silver")
        // proves "MODEL-123"; a page naming only the family does not prove a
        // longer model. Retained Web Search evidence admits a candidate; it does
        // not create a browser observation, verified fact or photo verdict.
        return is_array($source) && $excerpt !== '' && ! $this->ambiguous($excerpt)
            && $contains($excerpt) && ($observed === $needle || $contains($observed));
    }

    /** Same product page despite scheme, host case, www., fragment or trailing slash. */
    private static function samePage(string $left, string $right): bool
    {
        $key = static function (string $url): string {
            $parts = parse_url(trim($url));
            $host = preg_replace('/^www\./', '', strtolower((string) ($parts['host'] ?? '')));

            return $host.rtrim((string) ($parts['path'] ?? ''), '/').(isset($parts['query']) ? '?'.$parts['query'] : '');
        };

        return trim($left) !== '' && trim($right) !== '' && $key($left) === $key($right);
    }
}
