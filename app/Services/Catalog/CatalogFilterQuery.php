<?php

namespace App\Services\Catalog;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

class CatalogFilterQuery
{
    public function products(Builder $query, array $filters): void
    {
        $query
            ->when($filters['category'] ?? null, fn (Builder $query, string $category) => $query
                ->whereHas('category', fn (Builder $query) => $query->where('slug', $category)))
            ->when($filters['brands'] ?? null, fn (Builder $query, array $values) => $query
                ->whereHas('brand', fn (Builder $query) => $query->whereIn('name', $values)))
            ->when($filters['types'] ?? null, fn (Builder $query, array $values) => $query->whereIn('product_type', $values))
            ->when($filters['countries'] ?? null, fn (Builder $query, array $values) => $query
                ->whereHas('brand', fn (Builder $query) => $query->whereIn('country', $values)));

        $hasVariantFilters = filled($filters['search'] ?? null) || ! empty($filters['colors']) || ! empty($filters['stock'])
            || isset($filters['min_price']) || isset($filters['max_price']) || ! empty($filters['attributes']);
        if ($hasVariantFilters) {
            $query->whereHas('variants', fn (Builder $query) => $this->variants($query, $filters));
        }
    }

    public function variants(Builder $query, array $filters): void
    {
        $query->available()
            ->when($filters['colors'] ?? null, fn (Builder $query, array $values) => $query->whereIn('color', $values))
            ->when($filters['stock'] ?? null, fn (Builder $query, array $values) => $query->whereIn('stock_status', $values))
            ->when(isset($filters['min_price']), fn (Builder $query) => $query->where('price', '>=', $filters['min_price']))
            ->when(isset($filters['max_price']), fn (Builder $query) => $query->where('price', '<=', $filters['max_price']));

        if (filled($filters['search'] ?? null)) {
            $this->variantSearch($query, $filters['search']);
        }
        foreach ($filters['attributes'] ?? [] as $key => $values) {
            if (is_array($values) && $values !== []) {
                $definition = fn (Builder $attribute) => $attribute
                    ->whereHas('definition', fn (Builder $definition) => $definition->where('key', $key));
                $query->where(fn (Builder $choice) => $choice
                    ->whereHas('attributes', fn (Builder $attribute) => $definition($attribute)->whereIn('normalized_value', $values))
                    ->orWhere(fn (Builder $inherited) => $inherited
                        ->whereDoesntHave('attributes', $definition)
                        ->whereHas('product.attributes', fn (Builder $attribute) => $definition($attribute)
                            ->whereNull('product_variant_id')->whereIn('normalized_value', $values))));
            }
        }
    }

    private function variantSearch(Builder $query, string $search): void
    {
        $exact = mb_strtolower(trim($search));
        $term = '%'.addcslashes(trim($search), '%_\\').'%';
        $query->where(function (Builder $match) use ($exact, $term): void {
            $match->where(fn (Builder $identifier) => $identifier
                ->whereRaw('LOWER(sku) = ?', [$exact])->orWhereRaw('LOWER(mpn) = ?', [$exact])
                ->orWhereRaw('LOWER(gtin) = ?', [$exact]))
                ->orWhere(function (Builder $fallback) use ($exact, $term): void {
                    // An exact active identifier wins over generic product text.
                    $fallback->whereNotExists(fn (QueryBuilder $sibling) => $sibling
                        ->selectRaw('1')->from('product_variants as identifier_variants')
                        ->whereColumn('identifier_variants.product_id', 'product_variants.product_id')
                        ->where('identifier_variants.is_active', true)
                        ->where(fn (QueryBuilder $id) => $id
                            ->whereRaw('LOWER(identifier_variants.sku) = ?', [$exact])
                            ->orWhereRaw('LOWER(identifier_variants.mpn) = ?', [$exact])
                            ->orWhereRaw('LOWER(identifier_variants.gtin) = ?', [$exact])))
                        ->where(fn (Builder $text) => $text
                            ->where('sku', 'like', $term)->orWhere('mpn', 'like', $term)->orWhere('gtin', 'like', $term)
                            ->orWhereHas('attributes', fn (Builder $attribute) => $attribute->where('value', 'like', $term))
                            ->orWhereHas('product', fn (Builder $product) => $product
                                ->where(fn (Builder $fields) => $fields->where('title', 'like', $term)
                                    ->orWhere('model', 'like', $term)->orWhere('description', 'like', $term)
                                    ->orWhereHas('brand', fn (Builder $brand) => $brand->where('name', 'like', $term)))));
                });
        });
    }
}
