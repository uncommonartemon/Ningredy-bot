<?php

namespace App\Filament\Support;

use App\Services\Products\CatalogLocales;
use App\Services\Products\ManualProductAttributes;
use Illuminate\Support\Str;

/** Native repeater state only; copying a form never copies an inventory offer. */
final class ProductVariantFormState
{
    public static function fresh(int|string|null $categoryId = null): array
    {
        return [Str::uuid()->toString() => [
            'condition' => 'new', 'currency' => 'CZK', 'stock_status' => 'unknown',
            'quantity' => 0, 'is_default' => true, 'is_active' => true,
            'attributes' => app(ManualProductAttributes::class)->defaults($categoryId),
            'translations' => self::emptyTranslations(),
        ]];
    }

    public static function copy(array $variant): array
    {
        $copy = [
            'name' => $variant['name'] ?? null,
            'color' => $variant['color'] ?? null,
            'condition' => $variant['condition'] ?? 'new',
            'currency' => $variant['currency'] ?? 'CZK',
            'sku' => null, 'mpn' => null, 'gtin' => null,
            'price' => null, 'compare_at_price' => null, 'warranty_months' => null,
            'stock_status' => 'unknown', 'quantity' => 0,
            'is_default' => false, 'is_active' => true,
            'translations' => self::emptyTranslations(), 'attributes' => [],
        ];
        foreach ($variant['attributes'] ?? [] as $attribute) {
            $copy['attributes'][Str::uuid()->toString()] = [
                'attribute_definition_id' => $attribute['attribute_definition_id'] ?? null,
                'value' => $attribute['value'] ?? null,
                'unit' => $attribute['unit'] ?? null,
                '_category_default' => $attribute['_category_default'] ?? false,
            ];
        }

        return $copy;
    }

    private static function emptyTranslations(): array
    {
        return array_fill_keys(array_keys(CatalogLocales::CONTENT_TRANSLATIONS), ['title' => null, 'description' => null]);
    }

    /** Keep a usable primary offer when the previous primary row is removed. */
    public static function ensureDefault(array $variants): array
    {
        if ($variants !== [] && ! collect($variants)->contains(fn (array $variant): bool => (bool) ($variant['is_default'] ?? false))) {
            $variants[array_key_first($variants)]['is_default'] = true;
        }

        return $variants;
    }
}
