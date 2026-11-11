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
use App\Services\Admin\DashboardNumbers;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * The dashboard's cards: the catalog, what waits for the operator, and how the bot's searches
 * went over two weeks, each with its own small chart. Six cards fill two rows of three.
 */
class CatalogStats extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected function getColumns(): int
    {
        return 3;
    }

    protected function getStats(): array
    {
        $numbers = app(DashboardNumbers::class)->daily();
        $days = DashboardNumbers::DAYS;
        $failedRuns = AiRun::query()->where('status', 'failed')->where('created_at', '>=', now()->subDays($days))->count();
        $blockedSources = ProductGalleryRecipe::query()->where('source_blocked', true)->count();
        $running = AiRun::query()->where('status', 'running')->count();
        $searches = array_sum($numbers['searches']);
        $withPhotos = array_sum($numbers['with_photos']);
        $spend = array_sum($numbers['spend']);
        $paused = ProductDraft::query()->where('status', 'pending_review')
            ->where(fn ($query) => $query->whereDoesntHave('media')->orWhereNotNull('gallery_search_stop_reason'))->count();

        return [
            Stat::make(__('Опубликованные товары'), Product::query()->visibleInCatalog()->count())
                ->description(__('Добавлено за :days дней: :count', ['days' => $days, 'count' => array_sum($numbers['published'])]))
                ->descriptionIcon(Heroicon::ArrowTrendingUp)
                ->chart($numbers['published'])->color('success')
                ->url(ProductResource::getUrl()),
            Stat::make(__('Ожидают проверки'), ProductDraft::query()->where('status', 'pending_review')->count())
                ->description($paused > 0
                    ? __('Из них без фото или на паузе: :count', ['count' => $paused]) : __('Черновики AI'))
                ->descriptionIcon(Heroicon::Clock)
                ->chart($numbers['searches'])->color('warning')
                ->url(ProductDraftResource::getUrl()),
            Stat::make(__('Ошибки и блокировки'), $failedRuns + $blockedSources)
                ->description(__('AI: :failed · заблокированных источников: :blocked', ['failed' => $failedRuns, 'blocked' => $blockedSources]))
                ->descriptionIcon($failedRuns + $blockedSources > 0 ? Heroicon::ExclamationTriangle : Heroicon::CheckCircle)
                ->color($failedRuns + $blockedSources > 0 ? 'danger' : 'success')
                ->url($failedRuns >= $blockedSources ? AiRunResource::getUrl() : ProductGalleryRecipeResource::getUrl()),
            Stat::make(__('Поиски за :days дней', ['days' => $days]), $searches)
                ->description($searches > 0
                    ? __('С фото: :with из :all (:percent%)', ['with' => $withPhotos, 'all' => $searches, 'percent' => (int) round(100 * $withPhotos / $searches)])
                    : __('Поисков ещё не было'))
                ->descriptionIcon(Heroicon::Photo)
                ->chart($numbers['with_photos'])->color('info')
                ->url(ProductDraftResource::getUrl()),
            Stat::make(__('Расход AI за :days дней', ['days' => $days]), '$'.number_format($spend, 2))
                ->description($searches > 0
                    ? __('В среднем $:cost за поиск', ['cost' => number_format($spend / $searches, 2)]) : __('Оценка по ценам моделей'))
                ->descriptionIcon(Heroicon::Banknotes)
                ->chart($numbers['spend'])->color('gray')
                ->url(AiRunResource::getUrl()),
            Stat::make(__('Сейчас в работе'), $running)
                ->description($running > 0 ? __('AI-вызовов идёт прямо сейчас') : __('Бот свободен'))
                ->descriptionIcon($running > 0 ? Heroicon::Bolt : Heroicon::Pause)
                ->color($running > 0 ? 'info' : 'gray')
                ->url(AiRunResource::getUrl()),
        ];
    }
}
