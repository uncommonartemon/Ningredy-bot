<?php

namespace App\Services\Products;

/**
 * A part's label often carries the part number with a short tail the shops leave out: a
 * revision, a batch, a packing code. At the colleague's (2026-11-08) the label read
 * HMCG78AGBSA095N BA and the shop's page HMCG78AGBSA095N; the label MTA9ASF1G72PZ-2G6J1QI and
 * Micron's own page MTA9ASF1G72PZ-2G6J1. Both pages were refused as «another code», the
 * searches went on through a dozen more shops for 16 and 19 minutes.
 *
 * Only a tail counts: one code is the other plus two or three characters. Two different
 * tails, or a difference in the middle, stay two codes. A single trailing character is not a
 * batch tail but the letter that names another model: BX80646I74770 is a Core i7-4770 and
 * BX80646I74770S a Core i7-4770S, and a page of the first once gave the second its photo.
 * This is for parts, whose codes work this way; a laptop's longer SKU is another configuration.
 */
final class PartNumberSuffix
{
    /** The shared part must be a part number, not a family name. */
    private const int SHORTEST_BASE = 10;

    /** One character after a model number is its model letter (S, K, T, F), not a batch. */
    private const int SHORTEST_TAIL = 2;

    /** A revision or batch tail is a few characters. */
    private const int LONGEST_TAIL = 3;

    /** Whether two compact codes are one part number, one of them with a tail. */
    public static function sameBase(string $one, string $other): bool
    {
        [$base, $longer] = strlen($one) <= strlen($other) ? [$one, $other] : [$other, $one];

        return self::isTail(strlen($longer) - strlen($base)) && strlen($base) >= self::SHORTEST_BASE
            && strncasecmp($longer, $base, strlen($base)) === 0
            && preg_match('/\d/', $base) === 1 && preg_match('/[a-z]/i', $base) === 1;
    }

    /** Whether so many characters after a code can be its batch or revision tail. */
    public static function isTail(int $length): bool
    {
        return $length >= self::SHORTEST_TAIL && $length <= self::LONGEST_TAIL;
    }
}
