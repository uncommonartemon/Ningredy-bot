<?php

namespace App\Filament\Resources\StockUnits\Pages;

use App\Filament\Pages\Accounting;
use App\Filament\Resources\StockUnits\StockUnitResource;
use App\Models\StockUnit;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ListStockUnits extends ListRecords
{
    protected static string $resource = StockUnitResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('books')->label(__('Учёт'))->icon(Heroicon::OutlinedBanknotes)->color('gray')->url(Accounting::getUrl()),
            // The whole list for a spreadsheet: semicolons and a byte order mark, as Excel opens it.
            Action::make('download')->label(__('Скачать CSV'))->icon(Heroicon::OutlinedArrowDownTray)->color('gray')
                ->action(fn (): StreamedResponse => response()->streamDownload(function (): void {
                    $out = fopen('php://output', 'w');
                    fwrite($out, "\xEF\xBB\xBF");
                    fputcsv($out, ['code', 'product', 'sku', 'serial_number', 'status', 'purchase_price', 'purchased_at', 'supplier',
                        'sold_price', 'sold_at', 'currency', 'note'], ';');
                    StockUnit::query()->with('variant.product')->orderBy('id')->chunk(500, function ($units) use ($out): void {
                        foreach ($units as $unit) {
                            fputcsv($out, [$unit->code, $unit->variant?->product?->title, $unit->variant?->sku, $unit->serial_number,
                                $unit->status, $unit->purchase_price, $unit->purchased_at?->toDateString(), $unit->supplier,
                                $unit->sold_price, $unit->sold_at?->format('Y-m-d H:i'), $unit->variant?->currency, $unit->note], ';');
                        }
                    });
                    fclose($out);
                }, 'stock-units-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8'])),
        ];
    }
}
