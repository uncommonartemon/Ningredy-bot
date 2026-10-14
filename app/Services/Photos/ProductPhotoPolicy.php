<?php

namespace App\Services\Photos;

use App\Models\ProductDraft;

/** Operator-approved media exceptions, shared by replay and static Vision. */
final class ProductPhotoPolicy
{
    public static function allowsComponentLogos(ProductDraft $draft): bool
    {
        // Prefer the explicit product kind; retain compatibility with drafts
        // created before that field was populated. No brand/domain lists.
        return filled($draft->product_type)
            ? $draft->product_type === 'component'
            : $draft->category === 'components';
    }
}
