<?php

namespace App\Filament\Support;

use App\Models\StockUnit;
use App\Services\Catalog\StockUnits;
use Illuminate\Support\Str;

/**
 * The product form's rows for a configuration's units in stock: one row per physical item,
 * and always as many rows as the quantity says (operator, 2026-11-11).
 */
final class StockUnitRows
{
    /** What the admin writes about a unit. */
    private const array DETAILS = ['serial_number', 'purchase_price', 'purchased_at', 'supplier', 'note'];

    /**
     * The rows of a saved configuration's units in stock, oldest first.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function of(int $variantId): array
    {
        $rows = [];
        foreach (StockUnit::query()->where('product_variant_id', $variantId)->inStock()->orderBy('id')->get() as $unit) {
            $rows['unit-'.$unit->id] = [
                'id' => $unit->id, 'code' => $unit->code, 'serial_number' => $unit->serial_number,
                'purchase_price' => $unit->purchase_price === null ? null : rtrim(rtrim((string) $unit->purchase_price, '0'), '.'),
                'purchased_at' => $unit->purchased_at?->toDateString(), 'supplier' => $unit->supplier, 'note' => $unit->note,
            ];
        }

        return $rows;
    }

    /**
     * As many rows as the quantity: new empty rows at the end, and when there are too many,
     * rows nobody filled in go first, then the last ones.
     *
     * @param  array<string, array<string, mixed>>  $rows
     * @return array<string, array<string, mixed>>
     */
    public static function resize(array $rows, int $quantity): array
    {
        $quantity = max(0, min(1000, $quantity));
        while (count($rows) < $quantity) {
            // A row is shown with its number at once (operator, 2026-11-11): the number is taken here.
            $rows[(string) Str::uuid()] = ['id' => null, 'code' => app(StockUnits::class)->nextCode(), ...array_fill_keys(self::DETAILS, null)];
        }
        foreach (array_reverse(array_keys($rows)) as $key) {
            if (count($rows) > $quantity && self::isBlank($rows[$key])) {
                unset($rows[$key]);
            }
        }

        return count($rows) > $quantity ? array_slice($rows, 0, $quantity, preserve_keys: true) : $rows;
    }

    /** @param  array<string, mixed>  $row */
    private static function isBlank(array $row): bool
    {
        foreach (self::DETAILS as $detail) {
            if (filled($row[$detail] ?? null)) {
                return false;
            }
        }

        return true;
    }
}
