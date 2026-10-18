<?php

namespace App\Filament\Resources\ProductDrafts\Pages;

use App\Filament\Concerns\InteractsWithCatalogCleanup;
use App\Filament\Resources\ProductDrafts\ProductDraftResource;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListProductDrafts extends ListRecords
{
    use InteractsWithCatalogCleanup;

    protected static string $resource = ProductDraftResource::class;

    protected function getHeaderActions(): array
    {
        return [$this->catalogCleanupAction('drafts')];
    }

    public function getTabs(): array
    {
        return [
            'all' => Tab::make(__('Все')),
            'pending' => Tab::make(__('Ожидают'))
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'pending_review')),
            'approved' => Tab::make(__('Одобрены'))
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'approved')),
            'rejected' => Tab::make(__('Отклонены'))
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'rejected')),
            'no_photos' => Tab::make(__('Без фото / поиск приостановлен'))
                ->modifyQueryUsing(fn (Builder $query) => $query
                    ->where('status', 'pending_review')
                    ->where(fn (Builder $q) => $q
                        ->whereDoesntHave('media')
                        ->orWhereNotNull('gallery_search_stop_reason'))),
        ];
    }
}
