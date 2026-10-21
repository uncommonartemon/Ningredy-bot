<?php

namespace App\Services\Products;

use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Database\Eloquent\Collection;
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
            if ($this->variant($product, $card['specifications'] ?? [], $condition, $activeOnly, $card['model'] ?? null) !== null) {
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

    /**
     * Catalog products sharing the card's brand and model without being the
     * same sellable item: another regional SKU or configuration of one family
     * (live #553: MacBook Pro MRX33LL/A next to the stored MRX33CZ/A). Shown to
     * the operator before approval; whether it is a duplicate is their call.
     *
     * @return Collection<int, Product>
     */
    public function sameModel(array $card, ?int $exceptProductId = null): Collection
    {
        $base = ProductIdentityKey::for($card['brand'] ?? null, $card['model'] ?? null, (string) ($card['title'] ?? ''));

        return Product::query()->with('variants')
            ->where(fn ($query) => $query->where('canonical_key', $base)->orWhere('canonical_key', 'like', $base.'-id-%'))
            ->when($exceptProductId, fn ($query) => $query->whereKeyNot($exceptProductId))
            ->orderBy('id')->limit(3)->get();
    }

    public function variant(Product $product, array $specifications, ?string $condition = null, bool $activeOnly = false, ?string $model = null): ?ProductVariant
    {
        $wanted = self::identifiers($specifications);
        $product->loadMissing('variants');

        return $product->variants->sortByDesc('is_default')->first(function (ProductVariant $variant) use ($wanted, $condition, $activeOnly, $product, $model): bool {
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

            return $matched || $this->sharesCodeAcrossKinds($wanted, $variant, $product->model, $model);
        });
    }

    /**
     * One card can name a product by its part number and another by its model
     * code: live #567 stored the same ASUS card once as MPN 90YV0JC0-M0NA00 and
     * again as SKU DUAL-RTX4060-O8G. With no kind in common there is nothing to
     * contradict, so an equal code under another label is the same item. A
     * model is compared only when it is a single code; a family name such as
     * "MacBook Pro 14-inch (M3 Pro, 2023)" covers many configurations.
     *
     * @param  array<string, string>  $wanted
     */
    private function sharesCodeAcrossKinds(array $wanted, ProductVariant $variant, ?string $storedModel, ?string $cardModel): bool
    {
        $codes = fn (array $values): array => collect($values)
            ->filter(fn ($value): bool => is_string($value) && trim($value) !== '')
            ->map(fn (string $value): string => self::comparableCode(self::cleanIdentifier($value)))
            ->filter(fn (string $value): bool => $value !== '' && preg_match('/\s/u', $value) !== 1 && preg_match('/\d/', $value) === 1)
            ->unique()->values()->all();
        $card = $codes([...array_values($wanted), $cardModel]);
        $stored = $codes([$variant->sku, $variant->mpn, $variant->gtin, $storedModel]);
        $cardIdentifiers = $codes(array_values($wanted));
        $storedIdentifiers = $codes([$variant->sku, $variant->mpn, $variant->gtin]);

        // At least one side of each equal pair must be a real identifier: two
        // equal model codes alone are the shared-family case this class refuses.
        return array_intersect($cardIdentifiers, $stored) !== [] || array_intersect($card, $storedIdentifiers) !== [];
    }

    private static function comparableCode(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $digits = preg_replace('/\s+/u', '', $value) ?? $value;

        return ctype_digit($digits) && in_array(strlen($digits), [8, 12, 13, 14], true)
            ? str_pad($digits, 14, '0', STR_PAD_LEFT)
            : $value;
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
