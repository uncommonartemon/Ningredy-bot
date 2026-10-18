<?php

namespace App\Services\ProductSearch\Research;

/** Discovery hints only. No role confirms the source, its facts, or its photos. */
final class ResearchSourceRoles
{
    public const VALUES = ['facts', 'exact_photo', 'compatible_photo_candidate'];

    public static function maySupplyFacts(array $source): bool
    {
        return ! array_key_exists('roles', $source)
            || (is_array($source['roles']) && in_array('facts', $source['roles'], true));
    }

    public static function maySupplyPhotos(array $source): bool
    {
        return ! array_key_exists('roles', $source)
            || (is_array($source['roles']) && array_intersect($source['roles'], ['exact_photo', 'compatible_photo_candidate']) !== []);
    }

    public static function mayIdentifySelectedProduct(array $source): bool
    {
        return self::maySupplyFacts($source)
            && ! in_array('compatible_photo_candidate', (array) ($source['roles'] ?? []), true);
    }
}
