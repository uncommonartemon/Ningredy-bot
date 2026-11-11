<?php

namespace App\Filament\Widgets\Accounting;

use App\Services\Catalog\StockBooks;
use Filament\Widgets\ChartWidget;

/** Bought, sold and earned by month over the last year. */
class BooksChart extends ChartWidget
{
    // Shown on the «Учёт» page only, not on the main dashboard.
    protected static bool $isDiscovered = false;

    protected int|string|array $columnSpan = 'full';

    protected ?string $maxHeight = '320px';

    public function getHeading(): ?string
    {
        return __('Закупки, продажи и прибыль по месяцам');
    }

    protected function getData(): array
    {
        $books = app(StockBooks::class)->monthly();

        return [
            'labels' => $books['labels'],
            'datasets' => [
                ['label' => __('Куплено').', '.$books['currency'], 'data' => $books['bought'], 'backgroundColor' => '#94a3b8', 'borderColor' => '#94a3b8'],
                ['label' => __('Продано').', '.$books['currency'], 'data' => $books['sold'], 'backgroundColor' => '#f59e0b', 'borderColor' => '#f59e0b'],
                ['label' => __('Прибыль').', '.$books['currency'], 'data' => $books['profit'], 'backgroundColor' => '#10b981', 'borderColor' => '#10b981'],
            ],
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }
}
