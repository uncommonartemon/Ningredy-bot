<?php

namespace App\Services\Products;

final class ProductCondition
{
    public const VALUES = ['new', 'open_box', 'used', 'refurbished'];

    /**
     * The words a search phrase uses for a condition other than new. The assistant writes its
     * search phrase in English; these are the words it used (live #705: "... used").
     */
    private const QUERY_WORDS = [
        'refurbished' => ['refurbished', 'renewed', 'recertified', 'reconditioned'],
        'open_box' => ['open[- ]box', 'unsealed'],
        'used' => ['used', 'second[- ]hand', 'pre[- ]owned'],
    ];

    public static function options(): array
    {
        return ['new' => __('Новый'), 'open_box' => __('Распакованный'), 'used' => __('Б/у'), 'refurbished' => __('Восстановленный')];
    }

    /**
     * The search phrase without the condition of the operator's item, and that condition.
     * The condition is written on the card when it is saved; the model is searched for as a
     * new item, and a page that sells it new is a full match (decision of 2026-11-27, live
     * #705: the phrase ended in "used", pages sold the module new, and the card was refused).
     *
     * @return array{0: string, 1: ?string}
     */
    public static function fromSearchQuery(string $query, ?string $stated): array
    {
        $found = null;
        foreach (self::QUERY_WORDS as $condition => $words) {
            $pattern = '/(?<![\p{L}\p{N}])(?:'.implode('|', $words).')(?![\p{L}\p{N}])/iu';
            if (preg_match($pattern, $query) === 1) {
                $found ??= $condition;
                $query = (string) preg_replace($pattern, ' ', $query);
            }
        }
        $query = trim((string) preg_replace('/\s{2,}/u', ' ', $query), " \t,;");
        $stated = in_array($stated, self::VALUES, true) ? $stated : null;

        return [$query, $stated ?? $found];
    }

    /**
     * Facts without a condition row: the card's condition is its own field, the operator's,
     * and a row taken from a page's offer contradicted it (live #706, run 4128: "Refurbished"
     * from a shop for a module the operator called used).
     *
     * @param  array<int, mixed>  $specifications
     * @return list<mixed>
     */
    public static function withoutConditionRows(array $specifications): array
    {
        return array_values(array_filter($specifications,
            fn (mixed $item): bool => ! is_array($item) || ($item['key'] ?? null) !== 'condition'));
    }
}
