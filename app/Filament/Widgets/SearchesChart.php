<?php

namespace App\Filament\Widgets;

use App\Services\Admin\DashboardNumbers;
use Filament\Widgets\ChartWidget;

/** The bot's searches by day: how many ended with photos and how many without. */
class SearchesChart extends ChartWidget
{
    protected static ?int $sort = 2;

    protected ?string $maxHeight = '260px';

    public function getHeading(): ?string
    {
        return __('Поиски по дням');
    }

    protected function getData(): array
    {
        $numbers = app(DashboardNumbers::class)->daily();

        return [
            'labels' => $numbers['labels'],
            'datasets' => [
                ['label' => __('С фото'), 'data' => $numbers['with_photos'], 'backgroundColor' => '#10b981', 'borderColor' => '#10b981'],
                ['label' => __('Без фото'), 'data' => $numbers['without_photos'], 'backgroundColor' => '#f59e0b', 'borderColor' => '#f59e0b'],
            ],
        ];
    }

    protected function getOptions(): array
    {
        return ['scales' => ['x' => ['stacked' => true], 'y' => ['stacked' => true, 'ticks' => ['precision' => 0]]]];
    }

    protected function getType(): string
    {
        return 'bar';
    }
}
