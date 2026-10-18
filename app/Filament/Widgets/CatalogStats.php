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
            Stat::make(__('Опубликованные товары'), Product::query()->visibleInCatalog()->count())
                ->description(__('Активны и видны в каталоге'))
                ->color('success')
                ->url(ProductResource::getUrl()),
            Stat::make(__('Ожидают проверки'), ProductDraft::query()->where('status', 'pending_review')->count())
                ->description(__('Черновики AI'))
                ->color('warning')
                ->url(ProductDraftResource::getUrl()),
            Stat::make(__('Активные AI-вызовы'), AiRun::query()->where('status', 'running')->count())
                ->description(__('В одном поиске может быть несколько AI-вызовов'))
                ->color('info')
                ->url(AiRunResource::getUrl()),
            Stat::make(__('Приостановлены'), ProductDraft::query()
                ->where('status', 'pending_review')
                ->where(fn ($query) => $query->whereDoesntHave('media')->orWhereNotNull('gallery_search_stop_reason'))
                ->count())
                ->description(__('Поиск фото без результата или на паузе'))
                ->color('warning')
                ->url(ProductDraftResource::getUrl()),
            Stat::make(__('Ошибки и блокировки'), $failedRuns + $blockedSources)
                ->description(__('AI: :failed · заблокированных источников: :blocked', ['failed' => $failedRuns, 'blocked' => $blockedSources]))
                ->color('danger')
                ->url($failedRuns >= $blockedSources ? AiRunResource::getUrl() : ProductGalleryRecipeResource::getUrl()),
        ];
    }
}
