<?php

namespace App\Services\Catalog;

use App\Models\Category;

/**
 * Where a product goes and why (decision of 2026-11-25). The placement is what the product's
 * category will be; the reason says what was asked for and how the server settled it, so the card
 * can tell the operator the real cause.
 */
final readonly class CategoryPlacement
{
    /** The node asked for fits the product type. */
    public const string CHOSEN = 'chosen';

    /** Nothing was asked for: the product goes to the home of its type. */
    public const string NONE_CHOSEN = 'none_chosen';

    /** The node asked for belongs to another product type: the product goes to the home of its type. */
    public const string TYPE_CONFLICT = 'type_conflict';

    /** The slug asked for is no active category: the product goes to the home of its type. */
    public const string UNKNOWN = 'unknown';

    /**
     * @param  list<string>  $kinds  names of the kinds under the placed node, when it has any
     * @param  list<string>  $candidates  slugs that could hold the product when the server could not settle one
     */
    public function __construct(
        public ?Category $category,
        public ?string $requested,
        public ?string $productType,
        public string $reason,
        public array $kinds = [],
        public array $candidates = [],
    ) {}

    public function slug(): ?string
    {
        return $this->category?->slug;
    }

    /** The product sits in a node that has kinds under it: its kind is still to be chosen. */
    public function awaitsKind(): bool
    {
        return $this->category !== null && $this->kinds !== [];
    }

    /** The server put the product elsewhere than asked, or asked nothing and the server chose. */
    public function settledByServer(): bool
    {
        return $this->reason !== self::CHOSEN;
    }

    /**
     * What is kept with a draft: what was asked, where it went, why, and whether the kind is open.
     *
     * @return array{requested: ?string, placed: ?string, product_type: ?string, reason: string, awaits_kind: bool}
     */
    public function record(): array
    {
        return ['requested' => $this->requested, 'placed' => $this->slug(), 'product_type' => $this->productType,
            'reason' => $this->reason, 'awaits_kind' => $this->awaitsKind()];
    }

    /** Why no node holds the product, in words an agent can act on; null when one does. */
    public function problem(): ?string
    {
        if ($this->category !== null) {
            return null;
        }

        return $this->candidates === []
            ? 'No active category of the catalog map is for product_type '.($this->productType ?? 'unknown').'.'
            : 'Category "'.($this->requested ?? 'none').'" does not settle where a '.($this->productType ?? 'product')
                .' goes, and several nodes of the catalog map are for that type: '.implode(', ', $this->candidates)
                .'. Return the slug of the deepest one that fits.';
    }
}
