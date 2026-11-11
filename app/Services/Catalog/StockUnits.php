<?php

namespace App\Services\Catalog;

use App\Models\AppSetting;
use App\Models\Product;
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
 *
 * The books are a history, and a sold-out product is deleted from the catalog sooner or later.
 * So a unit carries its product's name and currency itself, and what the books know about it
 * stays when the product goes.
 */
class StockUnits
{
    /** The most units one configuration is given at once; a slip of the hand is not a warehouse. */
    private const int MOST_UNITS = 1000;

    /** The setting that holds the last number given to a unit. */
    private const string LAST_NUMBER = 'stock.last_unit_number';

    private bool $busy = false;

    /**
     * The next unit number, taken at once. The product form shows a number the moment a row is
     * added (operator, 2026-11-11), so it is given before the unit is saved. A number is never
     * given twice; one that was taken and not used is a gap, which costs nothing.
     */
    public function nextCode(): string
    {
        return DB::transaction(function (): string {
            // Units a migration wrote by itself took their row's id: the counter never goes below those.
            $number = max((int) AppSetting::valueFor(self::LAST_NUMBER, '0'), (int) StockUnit::query()->max('id')) + 1;
            AppSetting::put(self::LAST_NUMBER, (string) $number);

            return 'U-'.str_pad((string) $number, 6, '0', STR_PAD_LEFT);
        });
    }

    /** A number the form brings back is used only if it was really given out and no unit has it. */
    private function givenAndFree(mixed $code): ?string
    {
        if (! is_string($code) || preg_match('/^U-(\d{6,})$/D', $code, $number) !== 1
            || (int) $number[1] > (int) AppSetting::valueFor(self::LAST_NUMBER, '0')) {
            return null;
        }

        return StockUnit::query()->where('code', $code)->exists() ? null : $code;
    }

    /**
     * After a configuration was saved. `$rows` are the product form's rows for its units in
     * stock, or null when the quantity alone was changed.
     *
     * @param  array<int|string, array<string, mixed>>|null  $rows
     */
    public function afterVariantSaved(ProductVariant $variant, ?array $rows, bool $inserted = false): void
    {
        if ($variant->wasChanged('currency')) {
            $variant->stockUnits()->toBase()->update(['currency' => $variant->currency]);
        }
        // Only a save that wrote the quantity is about the quantity: the save that inserted the configuration,
        // or one that changed the number. A later save of the same object is neither, however new that object
        // still calls itself - it would match the units against a quantity it remembers from before a sale.
        if ($this->busy || ($rows === null && ! $inserted && ! $variant->wasChanged('quantity'))) {
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
        // A unit whose product was deleted has no shelf to return to.
        if ($unit->product_variant_id !== null) {
            $unit->update(['status' => StockUnit::IN_STOCK, 'sold_at' => null, 'sold_price' => null]);
        }
    }

    /**
     * Items that just came in are written down as they come: what they were bought for, when and
     * from whom goes into the rows this change added - the ones that were not there before it.
     * The same product comes in again and again (operator, 2026-11-11), each time at its own price.
     *
     * @param  list<int>  $knownUnitIds  the configuration's units before the change
     * @param  array<string, mixed>  $purchase
     */
    public function describeArrivals(ProductVariant $variant, array $knownUnitIds, array $purchase): int
    {
        $details = array_filter([
            'purchase_price' => is_numeric($purchase['purchase_price'] ?? null) && (float) $purchase['purchase_price'] >= 0 ? $purchase['purchase_price'] : null,
            'purchased_at' => filled($purchase['purchased_at'] ?? null) ? $purchase['purchased_at'] : null,
            'supplier' => self::text($purchase['supplier'] ?? null),
        ], fn (mixed $value): bool => $value !== null);
        if ($details === []) {
            return 0;
        }
        $arrived = $variant->stockUnits()->inStock()->whereNotIn('id', $knownUnitIds)->get();
        $arrived->each(fn (StockUnit $unit) => $unit->update($details));

        return $arrived->count();
    }

    /** How many units of the product the books keep once it is deleted: all but the rows that only stood for the quantity. */
    public function keptAfter(Product $product): int
    {
        return StockUnit::query()->whereIn('product_variant_id', $product->variants()->select('id'))->get()
            ->reject(fn (StockUnit $unit): bool => $unit->status === StockUnit::IN_STOCK && $unit->isBlank())->count();
    }

    /** The product got another name: its units are called the same. */
    public function productRenamed(Product $product): void
    {
        StockUnit::query()->whereIn('product_variant_id', $product->variants()->select('id'))
            ->toBase()->update(['product_title' => $product->title]);
    }

    /**
     * Before configurations are deleted, with their product or alone. What the books know
     * stays, without the link: a unit sold or written off, and a unit in stock somebody
     * described - that one is written off, because the item left the catalog without a sale.
     * A unit that only stood for the quantity goes with the configuration.
     *
     * @param  list<int>  $variantIds
     */
    public function variantsLeaving(array $variantIds): void
    {
        if ($variantIds === []) {
            return;
        }
        $this->quietly(function () use ($variantIds): void {
            foreach (StockUnit::query()->whereIn('product_variant_id', $variantIds)->inStock()->get() as $unit) {
                $unit->isBlank() ? $unit->delete() : $unit->update(['status' => StockUnit::WRITTEN_OFF]);
            }
            StockUnit::query()->whereIn('product_variant_id', $variantIds)->toBase()->update(['product_variant_id' => null]);
        });
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
                // The row was shown with its number already: the unit keeps that number.
                $unit = $variant->stockUnits()->make([...$details, ...self::nameOf($variant)]);
                $unit->forceFill(['code' => $this->givenAndFree($row['code'] ?? null)])->save();
                $kept[] = $unit->id;
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
            $variant->stockUnits()->create(self::nameOf($variant));
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
        $was = $this->busy;
        $this->busy = true;
        try {
            DB::transaction($work);
        } finally {
            $this->busy = $was;
        }
    }

    /**
     * What a unit keeps of its product, so the books can read it once the product is gone.
     *
     * @return array{product_title: ?string, currency: ?string}
     */
    private static function nameOf(ProductVariant $variant): array
    {
        return ['product_title' => $variant->product?->title, 'currency' => $variant->currency];
    }

    private static function text(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : '';

        return $value === '' ? null : $value;
    }
}
