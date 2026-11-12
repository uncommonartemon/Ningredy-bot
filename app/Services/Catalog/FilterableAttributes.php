<?php

namespace App\Services\Catalog;

use Illuminate\Support\Collection;

/**
 * Which characteristics make a filter worth offering. One rule for the storefront's filters and
 * the admin's product list, so the two never disagree about what can be filtered by.
 */
final class FilterableAttributes
{
    /** A characteristic carried by fewer products than this share narrows almost nothing. */
    public const float MINIMUM_SHARE = 0.1;

    public static function minimumCoverage(int $products): int
    {
        return (int) ceil(self::MINIMUM_SHARE * $products);
    }

    /**
     * @param  Collection<int, object>  $rows  one row per value: normalized_value, option_label
     */
    public static function useful(Collection $rows, int $productsWithAttribute, int $minimumCoverage): bool
    {
        return $productsWithAttribute >= $minimumCoverage
            && ! self::namesSingleProducts($rows, $productsWithAttribute)
            && ! self::readsLikeProse($rows);
    }

    /**
     * A filter whose every product has its own value (weight "1.86 kg", dimensions, a part
     * number) only lists the catalog again as checkboxes. Small lists stay, however unique.
     */
    private static function namesSingleProducts(Collection $rows, int $productsWithAttribute): bool
    {
        $values = $rows->unique('normalized_value')->count();

        return $values >= 12 && $values >= 0.9 * $productsWithAttribute;
    }

    /** A port list or an in-box sentence is a description, not a value to tick. */
    private static function readsLikeProse(Collection $rows): bool
    {
        $labels = $rows->unique('normalized_value')->pluck('option_label');

        return $labels->filter(fn ($label): bool => mb_strlen((string) $label) > 60)->count() * 2 > $labels->count();
    }
}
