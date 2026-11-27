<?php

namespace App\Services\Catalog;

use App\Models\Category;

/**
 * The one rule that settles a product's category on the server (decision of 2026-11-25), for the
 * search's answer and for the approval alike. A node that fits the product type stays as chosen,
 * a parent with kinds included: the placement then says the kind is still open, and the card shows
 * it. Nothing chosen, an unknown slug or a node of another type puts the product into the home of
 * its type - the highest node of that type - and says so. Two homes for one type are never guessed.
 */
class CatalogPlacement
{
    public const array PRODUCT_TYPES = ['laptop', 'desktop', 'component', 'other'];

    public function __construct(private readonly CatalogMap $map) {}

    public function place(?string $productType, ?string $slug): CategoryPlacement
    {
        $slug = is_string($slug) && trim($slug) !== '' ? trim($slug) : null;
        $type = in_array($productType, self::PRODUCT_TYPES, true) ? $productType : null;
        $requested = $slug === null ? null : $this->map->node($slug);
        $reason = match (true) {
            $requested === null => $slug === null ? CategoryPlacement::NONE_CHOSEN : CategoryPlacement::UNKNOWN,
            $type !== null && $requested['type'] !== null && $requested['type'] !== $type => CategoryPlacement::TYPE_CONFLICT,
            default => CategoryPlacement::CHOSEN,
        };
        if ($reason === CategoryPlacement::CHOSEN) {
            return $this->placed($requested, $slug, $type, $reason);
        }
        $homes = $type === null ? [] : $this->map->homesOf($type);
        if (count($homes) === 1) {
            return $this->placed($homes[0], $slug, $type, $reason);
        }

        return new CategoryPlacement(null, $slug, $type, $reason, candidates: array_values(array_column(
            array_filter($this->map->nodes(), fn (array $node): bool => $type !== null && $node['type'] === $type), 'slug')));
    }

    /** @param  array{id: int, slug: string}  $node */
    private function placed(array $node, ?string $requested, ?string $type, string $reason): CategoryPlacement
    {
        $category = Category::query()->find($node['id']);

        return new CategoryPlacement($category, $requested, $type, $reason,
            kinds: array_values(array_column($this->map->kinds($node['slug']), 'name')));
    }
}
