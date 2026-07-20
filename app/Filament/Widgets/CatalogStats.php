<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\AiRuns\AiRunResource;
use App\Filament\Resources\ProductDrafts\ProductDraftResource;
use App\Filament\Resources\Products\ProductResource;
use App\Models\AiRun;
use App\Models\Product;
use App\Models\ProductDraft;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class CatalogStats extends StatsOverviewWidget
{
    protected function getStats(): array
    {
        return [
            Stat::make('Товары', Product::query()->count())
                ->description('Рабочий каталог')
                ->color('success')
                ->url(ProductResource::getUrl()),
            Stat::make('Ожидают проверки', ProductDraft::query()->where('status', 'pending_review')->count())
                ->description('Черновики AI')
                ->color('warning')
                ->url(ProductDraftResource::getUrl()),
            Stat::make('Ошибки AI', AiRun::query()->where('status', 'failed')->count())
                ->description('Запуски со статусом failed')
                ->color('danger')
                ->url(AiRunResource::getUrl()),
        ];
    }
}
