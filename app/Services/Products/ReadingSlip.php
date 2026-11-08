<?php

namespace App\Services\Products;

/**
 * The same part number read with a slip. In a long code, a letter and a digit standing next
 * to each other can swap places when a label is read or typed: at the colleague's (#203,
 * 2026-11-08) a Samsung label was read as M393A4K40BB1-CRCQ0, and Samsung and every shop
 * write M393A4K40BB1-CRC0Q. The search refused eleven shops and every photo as «another
 * code» and ended with no card. Two digits or two letters swapped stay another code:
 * 5600 and 6500 are two processors, and a short name has no room for a slip at all.
 */
final class ReadingSlip
{
    /** From this length a code is a part number rather than a name. */
    private const int LONG_CODE_LENGTH = 10;

    /**
     * The spellings of a compact code with one neighbouring letter and digit swapped.
     *
     * @return list<string>
     */
    public static function variants(string $compact): array
    {
        if (strlen((string) preg_replace('/[^A-Za-z0-9]/', '', $compact)) < self::LONG_CODE_LENGTH) {
            return [];
        }
        $variants = [];
        for ($index = 0; $index < strlen($compact) - 1; $index++) {
            [$left, $right] = [$compact[$index], $compact[$index + 1]];
            if (ctype_alnum($left) && ctype_alnum($right) && ctype_digit($left) !== ctype_digit($right)) {
                $variants[] = substr($compact, 0, $index).$right.$left.substr($compact, $index + 2);
            }
        }

        return array_values(array_unique($variants));
    }

    /** Whether $text holds $code spelled with such a slip. */
    public static function within(string $text, string $code): bool
    {
        foreach (self::variants($code) as $variant) {
            if (str_contains($text, $variant)) {
                return true;
            }
        }

        return false;
    }
}
