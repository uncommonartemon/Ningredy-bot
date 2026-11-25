<?php

namespace App\Services\Catalog;

use Illuminate\Support\Collection;

/**
 * Which filters are worth offering. One rule for every filter of the storefront, a characteristic
 * or a column such as the brand, the colour or the availability, and for the admin's product
 * list, so no two places disagree about what can be filtered by.
 */
final class FilterableAttributes
{
    /** A filter carried by fewer products than this share narrows almost nothing. */
    public const float MINIMUM_SHARE = 0.1;

    /**
     * @param  Collection<int, object>  $rows  one row per value: normalized_value, option_label
     * @param  int  $carriers  products of the category that have a value
     * @param  int  $products  all products of the category
     */
    public static function useful(Collection $rows, int $carriers, int $products): bool
    {
        return self::narrows($rows, $carriers, $products)
            && ! self::namesSingleProducts($rows, $carriers)
            && ! self::readsLikeProse($rows);
    }

    /**
     * The part of the rule that holds for a list the shop keeps itself (its brands, the states
     * of availability): enough products carry a value and ticking one changes the list. Such a
     * list is short by its nature, so a shop with a dozen brands of one product each still
     * offers the brand; free text (a characteristic, a colour) answers to the whole rule.
     *
     * @param  Collection<int, object>  $rows
     */
    public static function narrows(Collection $rows, int $carriers, int $products): bool
    {
        return $carriers >= (int) ceil(self::MINIMUM_SHARE * $products)
            && ! self::sameForEveryProduct($rows, $carriers, $products);
    }

    /**
     * One value that every product of the category carries (the type «component» among memory
     * modules, «in stock» when all of them are): ticking it leaves the list as it was.
     */
    private static function sameForEveryProduct(Collection $rows, int $carriers, int $products): bool
    {
        return $carriers >= $products && $rows->unique('normalized_value')->count() === 1;
    }

    /**
     * A filter whose every product has its own value (weight "1.86 kg", dimensions, a part
     * number) only lists the catalog again as checkboxes. Small lists stay, however unique.
     */
    private static function namesSingleProducts(Collection $rows, int $carriers): bool
    {
        $values = $rows->unique('normalized_value')->count();

        return $values >= 12 && $values >= 0.9 * $carriers;
    }

    /** A port list or an in-box sentence is a description, not a value to tick. */
    private static function readsLikeProse(Collection $rows): bool
    {
        $labels = $rows->unique('normalized_value')->pluck('option_label');

        return $labels->filter(fn ($label): bool => mb_strlen((string) $label) > 60)->count() * 2 > $labels->count();
    }
}
