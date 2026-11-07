<?php

namespace App\Services\Products;

use App\Models\Category;

class ProductCategoryResolver
{
    public function resolve(?string $productType, ?string $categorySlug): ?Category
    {
        $selected = $categorySlug
            ? Category::query()->where('slug', $categorySlug)->where('is_active', true)->first()
            : null;

        if (! $productType) {
            return $selected;
        }

        if (! $selected || (
            $selected->product_type_affinity !== null
            && $selected->product_type_affinity !== $productType
        )) {
            $compatible = Category::query()
                ->where('is_active', true)
                ->where('product_type_affinity', $productType)
                ->orderBy('sort_order')
                ->get(['id', 'slug', 'parent_id', 'product_type_affinity']);
            if ($compatible->count() === 1) {
                return $compatible->first();
            }
            // Several categories accept this type when a parent has kinds under
            // it (SSD, memory… under «Комплектующие»): a kind is research's own
            // choice, the parent is the honest home when it made none. Two
            // unrelated roots for one type stay a question nobody should guess.
            $roots = $compatible->whereNull('parent_id');

            return $roots->count() === 1 ? $roots->first() : null;
        }

        return $selected;
    }
}
