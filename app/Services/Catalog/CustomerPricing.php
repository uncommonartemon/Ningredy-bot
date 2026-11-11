<?php

namespace App\Services\Catalog;

use App\Models\Customer;
use App\Models\CustomerGroup;
use App\Models\CustomerGroupPrice;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\Auth;

/**
 * The price the visitor sees (operator, 2026-11-11): a signed-in customer of a group sees
 * the group's price where the product has one; everyone else, and every product without a
 * price for the group, shows the ordinary price. One query per request loads the group's
 * whole price list.
 */
class CustomerPricing
{
    /** @var array<int, string>|null variant id => the group's price */
    private ?array $groupPrices = null;

    private ?CustomerGroup $group = null;

    private bool $resolved = false;

    public function customer(): ?Customer
    {
        $customer = Auth::guard('customer')->user();

        return $customer instanceof Customer && $customer->is_active ? $customer : null;
    }

    /** The visitor's group, when they are signed in and the group is in use. */
    public function group(): ?CustomerGroup
    {
        if (! $this->resolved) {
            $this->resolved = true;
            $group = $this->customer()?->group;
            $this->group = $group?->is_active ? $group : null;
        }

        return $this->group;
    }

    /**
     * The price to show for a configuration. `regular_price` is set only when the group's
     * price replaced the ordinary one.
     *
     * @return array{price: float|null, regular_price: float|null}
     */
    public function for(?ProductVariant $variant): array
    {
        $regular = $variant?->price !== null ? (float) $variant->price : null;
        $own = $variant === null ? null : $this->groupPrice($variant->id);
        if ($own === null || $own === $regular) {
            return ['price' => $regular, 'regular_price' => null];
        }

        return ['price' => $own, 'regular_price' => $regular];
    }

    private function groupPrice(int $variantId): ?float
    {
        $group = $this->group();
        if ($group === null) {
            return null;
        }
        $this->groupPrices ??= CustomerGroupPrice::query()->where('customer_group_id', $group->id)
            ->pluck('price', 'product_variant_id')->all();

        return isset($this->groupPrices[$variantId]) ? (float) $this->groupPrices[$variantId] : null;
    }
}
