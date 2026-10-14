<?php

namespace App\Services\Products;

use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Support\Str;

/** Catalog deduplication must not turn a shared family name into an exact variant match. */
final class ProductCatalogIdentity
{
    public function find(array $card, ?string $condition = null, bool $activeOnly = false): ?Product
    {
        $base = ProductIdentityKey::for($card['brand'] ?? null, $card['model'] ?? null, $card['title']);
        $exactKey = $this->keyForNewProduct($card);
        $identifiers = self::identifiers($card['specifications'] ?? []);
        $brandSlug = Str::slug((string) ($card['brand'] ?? ''));
        $candidates = Product::query()->with('variants')
            ->where(function ($query) use ($base, $exactKey, $identifiers, $brandSlug): void {
                $query->whereIn('canonical_key', [$base, $exactKey]);
                // Older products retain their keys. Identifiers also find the
                // same item if its model was written differently on a later run.
                if ($identifiers !== [] && $brandSlug !== '') {
                    $query->orWhereHas('brand', fn ($brand) => $brand->where('slug', $brandSlug));
                }
            })->orderBy('id')->get();

        foreach ($candidates as $product) {
            if ($this->variant($product, $card['specifications'] ?? [], $condition, $activeOnly) !== null) {
                return $product;
            }
            // Preserve the legacy, identifier-free path, but never let it
            // acknowledge a supplied commercial code it cannot confirm.
            if ($identifiers === [] && $product->variants->isEmpty()
                && ($condition === null || $condition === 'new')) {
                return $product;
            }
        }

        return null;
    }

    public function variant(Product $product, array $specifications, ?string $condition = null, bool $activeOnly = false): ?ProductVariant
    {
        $wanted = self::identifiers($specifications);
        $product->loadMissing('variants');

        return $product->variants->sortByDesc('is_default')->first(function (ProductVariant $variant) use ($wanted, $condition, $activeOnly): bool {
            if (($condition !== null && $variant->condition !== $condition) || ($activeOnly && ! $variant->is_active)) {
                return false;
            }
            if ($wanted === []) {
                return true;
            }
            $matched = false;
            foreach ($wanted as $kind => $value) {
                if (blank($variant->{$kind})) {
                    continue;
                }
                if (self::normalized($value, $kind) !== self::normalized(self::cleanIdentifier($variant->{$kind}), $kind)) {
                    return false;
                }
                $matched = true;
            }

            return $matched;
        });
    }

    public function keyForNewProduct(array $card): string
    {
        $base = ProductIdentityKey::for($card['brand'] ?? null, $card['model'] ?? null, $card['title']);
        $identifiers = self::identifiers($card['specifications'] ?? []);
        foreach ($identifiers as $kind => $value) {
            $identifiers[$kind] = self::normalized($value, $kind);
        }
        if ($identifiers === []) {
            return $base;
        }

        // Distinct sellable identities must not overwrite shared product-level
        // title, description and non-variant attributes during approval.
        return mb_substr($base, 0, 190).'-id-'.substr(hash('sha256', json_encode($identifiers)), 0, 24);
    }

    /** @return array<string, string> */
    public static function identifiers(array $specifications): array
    {
        $values = [];
        foreach ($specifications as $item) {
            if (! is_array($item) || ! is_string($item['value'] ?? null)) {
                continue;
            }
            $key = Str::lower((string) ($item['key'] ?? $item['name'] ?? ''));
            if (in_array($key, ['sku', 'mpn', 'gtin', 'ean', 'upc'], true) && filled($item['value'])) {
                $values[$key] = self::cleanIdentifier($item['value']);
            }
        }

        return array_filter([
            'sku' => $values['sku'] ?? null,
            'mpn' => $values['mpn'] ?? null,
            'gtin' => $values['gtin'] ?? $values['ean'] ?? $values['upc'] ?? null,
        ], fn ($value): bool => is_string($value) && $value !== '');
    }

    /** Keep the whole code, removing only an appended human explanation. */
    public static function cleanIdentifier(string $value): string
    {
        $normalized = trim(preg_replace('/[\(\[\{].*$/u', '', trim($value)) ?? $value);
        $normalized = trim((string) preg_split('/\s+[-\x{2010}-\x{2015}]\s+|\s*[,;]\s+/u', $normalized, 2)[0]);

        return $normalized === '' ? trim($value) : mb_substr($normalized, 0, 100);
    }

    private static function normalized(string $value, string $kind): string
    {
        $normalized = mb_strtolower(trim($value));
        $digits = preg_replace('/\s+/u', '', $normalized) ?? $normalized;
        // GS1's 14-digit representation pads GTIN-8/12/13 with leading zeroes.
        // This is representation, not a model-code equivalence rule:
        // https://www.gs1.org/edi-xml/technical-user-guide/Item_Numbers
        if ($kind === 'gtin' && ctype_digit($digits) && in_array(strlen($digits), [8, 12, 13, 14], true)) {
            return str_pad($digits, 14, '0', STR_PAD_LEFT);
        }

        return $normalized;
    }
}
