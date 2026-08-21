<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\AiRuns\AiRunResource;
use App\Filament\Resources\ProductDrafts\ProductDraftResource;
use App\Filament\Resources\ProductGalleryRecipes\ProductGalleryRecipeResource;
use App\Filament\Resources\Products\ProductResource;
use App\Models\AiRun;
use App\Models\Product;
use App\Models\ProductDraft;
use App\Models\ProductGalleryRecipe;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class CatalogStats extends StatsOverviewWidget
{
    protected function getStats(): array
    {
        $failedRuns = AiRun::query()->where('status', 'failed')->count();
        $blockedSources = ProductGalleryRecipe::query()->where('source_blocked', true)->count();

        return [
            Stat::make('Опубликованные товары', Product::query()->visibleInCatalog()->count())
                ->description('Активны и видны в каталоге')
                ->color('success')
                ->url(ProductResource::getUrl()),
            Stat::make('Ожидают проверки', ProductDraft::query()->where('status', 'pending_review')->count())
                ->description('Черновики AI')
                ->color('warning')
                ->url(ProductDraftResource::getUrl()),
            Stat::make('Активные поиски', AiRun::query()->where('status', 'running')->count())
                ->description('Запуски со статусом running')
                ->color('info')
                ->url(AiRunResource::getUrl()),
            Stat::make('Приостановлены', ProductDraft::query()
                ->where('status', 'pending_review')
                ->where(fn ($query) => $query->whereDoesntHave('media')->orWhereNotNull('gallery_search_stop_reason'))
                ->count())
                ->description('Поиск фото без результата или на паузе')
                ->color('warning')
                ->url(ProductDraftResource::getUrl()),
            Stat::make('Ошибки и блокировки', $failedRuns + $blockedSources)
                ->description("AI: {$failedRuns} · заблокированных источников: {$blockedSources}")
                ->color('danger')
                ->url($failedRuns >= $blockedSources ? AiRunResource::getUrl() : ProductGalleryRecipeResource::getUrl()),
        ];
    }
}
