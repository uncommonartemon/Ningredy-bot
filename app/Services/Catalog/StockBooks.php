<?php

namespace App\Services\Catalog;

use App\Models\StockUnit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The shop's books in numbers (operator's decision, 2026-11-11): what is on the shelf and what it cost, what was bought and sold lately, and what
 * was earned. Everything is counted from the units; a unit without a purchase price is
 * counted apart instead of as a free one.
 */
class StockBooks
{
    /**
     * @return array{currency: string, other_currencies: int, in_stock: int, unpriced: int, invested: float, shelf_value: float,
     *     expected_profit: float, bought: float, bought_count: int, sold: float, sold_count: int, profit: float, sold_cost: float,
     *     sold_unpriced: int, days: int}
     */
    public function summary(int $days = 30): array
    {
        [$units, $currency, $others] = $this->units();
        $since = now()->subDays($days)->startOfDay();
        $inStock = $units->where('status', StockUnit::IN_STOCK);
        $priced = $inStock->whereNotNull('purchase_price');
        $bought = $units->filter(fn ($unit): bool => $unit->purchase_price !== null && $unit->purchased_at !== null
            && Carbon::parse($unit->purchased_at)->gte($since));
        $sold = $units->filter(fn ($unit): bool => $unit->status === StockUnit::SOLD && $unit->sold_at !== null
            && Carbon::parse($unit->sold_at)->gte($since));
        $soldPriced = $sold->filter(fn ($unit): bool => $unit->purchase_price !== null && $unit->sold_price !== null);

        return [
            'currency' => $currency, 'other_currencies' => $others, 'days' => $days,
            'in_stock' => $inStock->count(), 'unpriced' => $inStock->count() - $priced->count(),
            'invested' => (float) $priced->sum('purchase_price'),
            'shelf_value' => (float) $inStock->sum('shelf_price'),
            'expected_profit' => (float) $priced->whereNotNull('shelf_price')->sum(fn ($unit): float => $unit->shelf_price - $unit->purchase_price),
            'bought' => (float) $bought->sum('purchase_price'), 'bought_count' => $bought->count(),
            'sold' => (float) $sold->sum('sold_price'), 'sold_count' => $sold->count(),
            'profit' => (float) $soldPriced->sum(fn ($unit): float => $unit->sold_price - $unit->purchase_price),
            'sold_cost' => (float) $soldPriced->sum('purchase_price'),
            'sold_unpriced' => $sold->count() - $soldPriced->count(),
        ];
    }

    /**
     * Money by month, the oldest month first.
     *
     * @return array{labels: list<string>, bought: list<float>, sold: list<float>, profit: list<float>, currency: string}
     */
    public function monthly(int $months = 12): array
    {
        [$units, $currency] = $this->units();
        $keys = collect(range($months - 1, 0))->map(fn (int $back): string => now()->startOfMonth()->subMonths($back)->format('Y-m'));
        $empty = $keys->mapWithKeys(fn (string $key): array => [$key => 0.0]);
        $bought = $empty->all();
        $sold = $empty->all();
        $profit = $empty->all();
        foreach ($units as $unit) {
            $boughtIn = $unit->purchased_at === null ? null : Carbon::parse($unit->purchased_at)->format('Y-m');
            if ($unit->purchase_price !== null && isset($bought[$boughtIn])) {
                $bought[$boughtIn] += $unit->purchase_price;
            }
            $soldIn = $unit->status !== StockUnit::SOLD || $unit->sold_at === null ? null : Carbon::parse($unit->sold_at)->format('Y-m');
            if ($unit->sold_price !== null && isset($sold[$soldIn])) {
                $sold[$soldIn] += $unit->sold_price;
                $profit[$soldIn] += $unit->purchase_price === null ? 0.0 : $unit->sold_price - $unit->purchase_price;
            }
        }

        return ['labels' => $keys->map(fn (string $key): string => Carbon::createFromFormat('Y-m', $key)->format('m.Y'))->all(),
            'bought' => array_values($bought), 'sold' => array_values($sold), 'profit' => array_values($profit), 'currency' => $currency];
    }

    /**
     * The units in the shop's main currency, with their configuration's price beside them.
     *
     * @return array{0: Collection<int, object>, 1: string, 2: int}
     */
    private function units(): array
    {
        // A sold unit outlives its product, so the configuration is joined only where it still exists.
        $rows = StockUnit::query()->leftJoin('product_variants', 'product_variants.id', '=', 'stock_units.product_variant_id')
            ->get(['stock_units.status', 'stock_units.purchase_price', 'stock_units.purchased_at', 'stock_units.sold_at',
                'stock_units.sold_price', 'stock_units.currency', 'product_variants.price as shelf_price',
                'product_variants.currency as shelf_currency'])
            ->map(fn (StockUnit $unit): object => (object) [
                'status' => $unit->status, 'currency' => $unit->getAttribute('shelf_currency') ?: $unit->getAttribute('currency') ?: 'CZK',
                'purchase_price' => $unit->getRawOriginal('purchase_price') === null ? null : (float) $unit->getRawOriginal('purchase_price'),
                'sold_price' => $unit->getRawOriginal('sold_price') === null ? null : (float) $unit->getRawOriginal('sold_price'),
                'shelf_price' => $unit->getAttribute('shelf_price') === null ? null : (float) $unit->getAttribute('shelf_price'),
                'purchased_at' => $unit->getRawOriginal('purchased_at'), 'sold_at' => $unit->getRawOriginal('sold_at'),
            ]);
        // One set of books is one currency: the one most units are priced in.
        $currency = (string) ($rows->countBy('currency')->sortDesc()->keys()->first() ?? 'CZK');

        return [$rows->where('currency', $currency)->values(), $currency, $rows->where('currency', '!=', $currency)->count()];
    }
}
