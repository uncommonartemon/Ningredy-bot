<?php

namespace App\Services\Catalog;

use App\Models\ProductVariant;
use App\Models\StockUnit;
use Illuminate\Support\Facades\DB;

/**
 * Keeps a configuration's quantity and its units in stock the same number (operator,
 * 2026-11-11: «репитер, отталкивающийся от кол-ва»). Whoever changes the quantity - the admin
 * form, a button, the bot in Telegram - gets units added or taken away; whoever changes a
 * unit - the books, the chat - gets the quantity moved.
 *
 * A unit that leaves because the quantity went down is taken as sold, at the configuration's
 * price and now: the shop has no orders, so this is the only trace of a sale. A unit nobody
 * wrote anything about is simply removed. The books can correct either.
 */
class StockUnits
{
    /** The most units one configuration is given at once; a slip of the hand is not a warehouse. */
    private const int MOST_UNITS = 1000;

    private bool $busy = false;

    /**
     * After a configuration was saved. `$rows` are the product form's rows for its units in
     * stock, or null when the quantity alone was changed.
     *
     * @param  array<int|string, array<string, mixed>>|null  $rows
     */
    public function afterVariantSaved(ProductVariant $variant, ?array $rows): void
    {
        if ($this->busy || ($rows === null && ! $variant->wasRecentlyCreated && ! $variant->wasChanged('quantity'))) {
            return;
        }
        $this->quietly(function () use ($variant, $rows): void {
            if ($rows !== null) {
                $this->applyRows($variant, $rows);
            }
            $this->matchQuantity($variant);
        });
    }

    /** After a unit was added, removed or changed its state by itself: the quantity follows. */
    public function unitChanged(StockUnit $unit): void
    {
        if ($this->busy) {
            return;
        }
        $this->quietly(function () use ($unit): void {
            foreach (array_unique(array_filter([$unit->product_variant_id, $unit->getOriginal('product_variant_id')])) as $variantId) {
                $variant = ProductVariant::query()->find($variantId);
                $variant?->forceFill(['quantity' => $variant->stockUnits()->inStock()->count()])->saveQuietly();
            }
        });
    }

    /** Takes one unit out of the stock as sold. */
    public function sell(StockUnit $unit, ?string $price = null, mixed $at = null): void
    {
        $unit->update(['status' => StockUnit::SOLD, 'sold_at' => $at ?? now(),
            'sold_price' => $price ?? $unit->variant?->price]);
    }

    /** Takes one unit out of the stock without a sale: lost, broken, used for parts. */
    public function writeOff(StockUnit $unit): void
    {
        $unit->update(['status' => StockUnit::WRITTEN_OFF, 'sold_at' => null, 'sold_price' => null]);
    }

    /** Puts a unit back: a sale that did not happen, a mistake in the books. */
    public function restore(StockUnit $unit): void
    {
        $unit->update(['status' => StockUnit::IN_STOCK, 'sold_at' => null, 'sold_price' => null]);
    }

    /** @param  array<int|string, array<string, mixed>>  $rows */
    private function applyRows(ProductVariant $variant, array $rows): void
    {
        $inStock = $variant->stockUnits()->inStock()->get()->keyBy('id');
        $kept = [];
        foreach ($rows as $row) {
            $details = [
                'serial_number' => self::text($row['serial_number'] ?? null), 'supplier' => self::text($row['supplier'] ?? null),
                'note' => self::text($row['note'] ?? null),
                'purchase_price' => is_numeric($row['purchase_price'] ?? null) && (float) $row['purchase_price'] >= 0 ? $row['purchase_price'] : null,
                'purchased_at' => filled($row['purchased_at'] ?? null) ? $row['purchased_at'] : null,
            ];
            $unit = $inStock->get((int) ($row['id'] ?? 0));
            if ($unit !== null) {
                $unit->update($details);
                $kept[] = $unit->id;
            } else {
                $kept[] = $variant->stockUnits()->create($details)->id;
            }
        }
        // A row the form no longer has: that unit left the stock.
        foreach ($inStock->except($kept) as $unit) {
            $this->leave($variant, $unit);
        }
    }

    /** As many units in stock as the quantity says. */
    private function matchQuantity(ProductVariant $variant): void
    {
        $wanted = max(0, min(self::MOST_UNITS, (int) $variant->quantity));
        $inStock = $variant->stockUnits()->inStock()->orderBy('id')->get();
        for ($missing = $wanted - $inStock->count(); $missing > 0; $missing--) {
            $variant->stockUnits()->create([]);
        }
        $surplus = $inStock->count() - $wanted;
        if ($surplus <= 0) {
            return;
        }
        // Units nobody described go first, the newest of them first; then the oldest described ones.
        $leaving = $inStock->filter->isBlank()->reverse()->concat($inStock->reject->isBlank())->take($surplus);
        foreach ($leaving as $unit) {
            $this->leave($variant, $unit);
        }
    }

    private function leave(ProductVariant $variant, StockUnit $unit): void
    {
        $unit->isBlank() ? $unit->delete()
            : $unit->update(['status' => StockUnit::SOLD, 'sold_at' => now(), 'sold_price' => $variant->price]);
    }

    /** The service's own writes do not come back to it as changes to react to. */
    private function quietly(callable $work): void
    {
        $this->busy = true;
        try {
            DB::transaction($work);
        } finally {
            $this->busy = false;
        }
    }

    private static function text(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : '';

        return $value === '' ? null : $value;
    }
}
