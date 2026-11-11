<?php

namespace App\Filament\Widgets\Accounting;

use App\Filament\Resources\StockUnits\StockUnitResource;
use App\Models\StockUnit;
use Filament\Support\Enums\FontFamily;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/** The last units sold: what went, for how much, and what it earned. */
class RecentSales extends TableWidget
{
    // Shown on the «Учёт» page only, not on the main dashboard.
    protected static bool $isDiscovered = false;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->heading(__('Последние продажи'))
            ->description(__('Единицы, ушедшие из наличия как проданные. Исправить продажу можно в «Единицах склада».'))
            ->query(StockUnit::query()->where('status', StockUnit::SOLD)->latest('sold_at')->latest('id'))
            ->paginated([10, 25])->defaultPaginationPageOption(10)
            ->columns([
                TextColumn::make('sold_at')->label(__('Дата продажи'))->date('d.m.Y')->placeholder('—'),
                TextColumn::make('code')->label(__('Номер'))->fontFamily(FontFamily::Mono)
                    ->url(fn (): string => StockUnitResource::getUrl()),
                TextColumn::make('product_title')->label(__('Товар'))->wrap()->limit(70)->placeholder('—'),
                TextColumn::make('purchase_price')->label(__('Цена закупки'))->numeric(decimalPlaces: 2)->placeholder('—')->alignEnd(),
                TextColumn::make('sold_price')->label(__('Цена продажи'))->numeric(decimalPlaces: 2)->placeholder('—')->alignEnd(),
                TextColumn::make('profit')->label(__('Прибыль'))->alignEnd()->placeholder('—')
                    ->state(fn (StockUnit $record): ?float => $record->sold_price === null || $record->purchase_price === null ? null
                        : (float) $record->sold_price - (float) $record->purchase_price)
                    ->numeric(decimalPlaces: 2)
                    ->color(fn (?float $state): string => $state === null ? 'gray' : ($state >= 0 ? 'success' : 'danger')),
            ]);
    }
}
