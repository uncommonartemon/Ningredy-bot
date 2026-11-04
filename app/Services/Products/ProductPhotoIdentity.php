<?php

namespace App\Services\Products;

use App\Models\Category;
use App\Models\ProductDraft;

/**
 * What identifies a product in a photograph, by its category's choice (operator,
 * 2026-11-04). A laptop is identified by its page and its body: memory and
 * storage inside are invisible, and a processor badge on the lid is only a
 * configuration marker. A component is itself the thing photographed: the
 * label on the module or the drive names its part number and capacity, and
 * its board length or socket is visible, so those markings are its identity
 * and a page address that happens to carry the requested code is not.
 *
 * Live 2026-11-04 (#379, #380, #384): for a BC501 request a PC801 drive was
 * published from a reseller page whose address held the BC501 code; four
 * memory modules of other brands and five other Seagate drives were taken
 * from "related products" blocks of pages judged exact by their address.
 */
final class ProductPhotoIdentity
{
    /** The page and the body identify the product (laptops, computers). */
    public const string PAGE = 'page';

    /** The product's own markings identify it (components). */
    public const string LABEL = 'label';

    public const array OPTIONS = [self::PAGE, self::LABEL];

    /** Specification rows that name the product sold, compared with what a label shows. */
    private const array CODE_KEYS = ['mpn', 'sku', 'part_number', 'product_code'];

    /** A marking this short is a family name or a size, not a part number. */
    private const int MIN_CODE_LENGTH = 6;

    /** A readable marking is the same product's other batch when this much of it matches from the start. */
    private const float SIBLING_PREFIX_SHARE = 0.75;

    public static function fromLabel(ProductDraft $draft): bool
    {
        return self::policy($draft) === self::LABEL;
    }

    public static function policy(ProductDraft $draft): string
    {
        $category = self::category($draft);
        if ($category !== null) {
            return $category->photoIdentity();
        }

        // A draft without a category row: the product kind says what it is.
        return ($draft->product_type ?? null) === 'component' ? self::LABEL : self::PAGE;
    }

    /** What is visible on such a product, in the operator's words, for the agents. */
    public static function hint(ProductDraft $draft): string
    {
        return trim((string) self::category($draft)?->photo_identity_hint);
    }

    /**
     * The codes a label may show for this product: its identifying rows and model,
     * as written and without separators.
     *
     * @return list<string>
     */
    public static function codes(ProductDraft $draft): array
    {
        return collect($draft->specifications ?? [])
            ->filter(fn (mixed $row): bool => is_array($row) && in_array($row['key'] ?? null, self::CODE_KEYS, true))
            ->pluck('value')->push($draft->model)
            ->map(fn (mixed $code): string => trim((string) $code))
            ->filter(fn (string $code): bool => $code !== '')
            ->unique()->values()->all();
    }

    /**
     * What a marking read from the frame says against the requested product:
     * match (the requested code), sibling (the same code with another suffix or
     * batch), conflict (another code), none (nothing code-like was read).
     */
    public static function markingVerdict(ProductDraft $draft, ?string $observed): string
    {
        $observed = self::compact((string) $observed);
        if (mb_strlen($observed) < self::MIN_CODE_LENGTH || preg_match('/\d/', $observed) !== 1) {
            return 'none';
        }
        $codes = array_values(array_filter(array_map(self::compact(...), self::codes($draft)),
            fn (string $code): bool => mb_strlen($code) >= self::MIN_CODE_LENGTH && preg_match('/\d/', $code) === 1));
        if ($codes === []) {
            return 'none';
        }
        $verdict = 'conflict';
        foreach ($codes as $code) {
            if ($observed === $code || str_contains($observed, $code) || str_contains($code, $observed)) {
                return 'match';
            }
            $shorter = min(mb_strlen($observed), mb_strlen($code));
            $prefix = 0;
            while ($prefix < $shorter && $observed[$prefix] === $code[$prefix]) {
                $prefix++;
            }
            if ($prefix >= max(8, (int) ceil($shorter * self::SIBLING_PREFIX_SHARE))) {
                $verdict = 'sibling';
            }
        }

        return $verdict;
    }

    private static function compact(string $text): string
    {
        return strtoupper((string) preg_replace('/[^A-Za-z0-9]+/', '', $text));
    }

    private static function category(ProductDraft $draft): ?Category
    {
        $slug = trim((string) $draft->category);

        return $slug === '' ? null : Category::query()->where('slug', $slug)->first();
    }
}
