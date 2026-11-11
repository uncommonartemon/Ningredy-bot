<?php

namespace App\Filament\Widgets;

use App\Services\Admin\DashboardNumbers;
use Filament\Widgets\ChartWidget;

/** What the bot's AI calls cost by day, estimated from the models' prices. */
class SpendChart extends ChartWidget
{
    protected static ?int $sort = 3;

    protected ?string $maxHeight = '260px';

    public function getHeading(): ?string
    {
        return __('Расход AI по дням, $');
    }

    protected function getData(): array
    {
        $numbers = app(DashboardNumbers::class)->daily();

        return [
            'labels' => $numbers['labels'],
            'datasets' => [
                ['label' => __('Расход, $'), 'data' => $numbers['spend'], 'borderColor' => '#f59e0b',
                    'backgroundColor' => 'rgba(245, 158, 11, 0.15)', 'fill' => true, 'tension' => 0.3],
            ],
        ];
    }

    protected function getOptions(): array
    {
        return ['plugins' => ['legend' => ['display' => false]], 'scales' => ['y' => ['min' => 0, 'suggestedMax' => 0.5]]];
    }

    protected function getType(): string
    {
        return 'line';
    }
}
