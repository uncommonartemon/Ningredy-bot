<?php

namespace App\Services\Products;

use Illuminate\Support\Str;

/**
 * The same brand+model (falling back to title) slug used both to decide
 * whether an approved draft updates an existing product instead of
 * duplicating it (ProductDraftWorkflow), and to check - before a new draft
 * is even created - whether the catalog already has this exact product.
 */
class ProductIdentityKey
{
    public static function for(?string $brand, ?string $model, string $title): string
    {
        $identity = implode(' ', array_filter([$brand, $model ?: $title]));

        return Str::slug($identity) ?: 'product-'.sha1(Str::lower($title));
    }
}
