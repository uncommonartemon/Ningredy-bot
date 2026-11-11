<?php

namespace App\Filament\Pages;

use App\Filament\Resources\StockUnits\StockUnitResource;
use App\Filament\Widgets\Accounting\BooksChart;
use App\Filament\Widgets\Accounting\BooksStats;
use App\Filament\Widgets\Accounting\RecentSales;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Dashboard;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * The accountant's page (operator, 2026-11-11): the shop's own books at a glance. A second
 * dashboard of Filament's own kind, so it is built from native widgets only.
 */
class Accounting extends Dashboard
{
    protected static string $routePath = '/accounting';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?int $navigationSort = 1;

    public static function getNavigationLabel(): string
    {
        return __('Учёт');
    }

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return __('Склад и учёт');
    }

    public function getTitle(): string
    {
        return __('Учёт');
    }

    public function getWidgets(): array
    {
        return [BooksStats::class, BooksChart::class, RecentSales::class];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('units')->label(__('Все единицы склада'))->icon(Heroicon::OutlinedQueueList)->color('gray')
                ->url(StockUnitResource::getUrl()),
        ];
    }
}
