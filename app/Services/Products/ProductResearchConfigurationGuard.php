<?php

namespace App\Services\Products;

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

        if (! $identity && ! $this->hasExactRetailOffer($data)) {
            $issues[] = 'No exact reusable SKU/MPN/GTIN or exact retailer offer identifies one sellable configuration.';
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

    private function hasExactRetailOffer(array $data): bool
    {
        $model = trim((string) ($data['model'] ?? ''));

        if ($model === '' || $this->ambiguous($model)) {
            return false;
        }

        $primaryUrl = trim((string) ($data['primary_source_url'] ?? ''));
        $primary = collect($data['sources'] ?? [])->first(
            fn (mixed $source): bool => is_array($source)
                && rtrim((string) ($source['url'] ?? ''), '/') === rtrim($primaryUrl, '/'),
        );

        return is_array($primary)
            && in_array($primary['type'] ?? null, ['retailer', 'marketplace'], true)
            && preg_match('/[a-z].*\d|\d.*[a-z]/i', $model) === 1;
    }
}
