<?php

namespace App\Filament\Widgets\Accounting;

use App\Filament\Resources\StockUnits\StockUnitResource;
use App\Services\Catalog\StockBooks;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/** The books at a glance: what is on the shelf, what it cost, what the last month brought. */
class BooksStats extends StatsOverviewWidget
{
    // Shown on the «Учёт» page only, not on the main dashboard.
    protected static bool $isDiscovered = false;

    protected function getStats(): array
    {
        $books = app(StockBooks::class)->summary();
        $money = fn (float $amount): string => number_format($amount, 0, ',', ' ').' '.$books['currency'];
        $units = StockUnitResource::getUrl();

        return [
            Stat::make(__('В наличии, шт.'), (string) $books['in_stock'])
                ->description($books['unpriced'] > 0
                    ? __('Без цены закупки: :count', ['count' => $books['unpriced']]) : __('У всех указана цена закупки'))
                ->color($books['unpriced'] > 0 ? 'warning' : 'success')->url($units),
            Stat::make(__('Вложено в товар'), $money($books['invested']))
                ->description(__('Сумма закупки того, что в наличии'))->color('gray')->url($units),
            Stat::make(__('Продажная стоимость остатка'), $money($books['shelf_value']))
                ->description(__('По текущим ценам сайта'))->color('gray'),
            Stat::make(__('Ожидаемая прибыль'), $money($books['expected_profit']))
                ->description(__('Цена сайта минус закупка, по единицам с ценой закупки'))
                ->color($books['expected_profit'] >= 0 ? 'success' : 'danger'),
            Stat::make(__('Куплено за :days дней', ['days' => $books['days']]), $money($books['bought']))
                ->description(__('Единиц: :count', ['count' => $books['bought_count']]))->color('info'),
            Stat::make(__('Продано за :days дней', ['days' => $books['days']]), $money($books['sold']))
                ->description(__('Единиц: :count', ['count' => $books['sold_count']]))->color('info'),
            Stat::make(__('Прибыль за :days дней', ['days' => $books['days']]), $money($books['profit']))
                ->description($books['sold_unpriced'] > 0
                    ? __('Без цены закупки продано: :count, в прибыль не вошли', ['count' => $books['sold_unpriced']])
                    : __('Продажа минус закупка'))
                ->color($books['profit'] >= 0 ? 'success' : 'danger'),
        ];
    }
}
