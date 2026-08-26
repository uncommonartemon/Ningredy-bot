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
                ->limit(2)
                ->get();

            return $compatible->count() === 1 ? $compatible->first() : null;
        }

        return $selected;
    }
}
