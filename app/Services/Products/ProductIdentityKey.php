<?php

namespace App\Services\Products;

use Illuminate\Support\Str;

/**
 * A shared brand+model (falling back to title) key. This can identify a family,
 * not a sellable configuration: ProductCatalogIdentity checks commercial
 * identifiers before research or approval may reuse an existing product.
 */
class ProductIdentityKey
{
    public static function for(?string $brand, ?string $model, string $title): string
    {
        $identity = implode(' ', array_filter([$brand, $model ?: $title]));

        return Str::slug($identity) ?: 'product-'.sha1(Str::lower($title));
    }
}
