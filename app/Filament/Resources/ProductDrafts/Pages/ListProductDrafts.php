<?php

namespace App\Filament\Resources\ProductDrafts\Pages;

use App\Filament\Resources\ProductDrafts\ProductDraftResource;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListProductDrafts extends ListRecords
{
    protected static string $resource = ProductDraftResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }

    public function getTabs(): array
    {
        return [
            'all' => Tab::make('Все'),
            'pending' => Tab::make('Ожидают')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'pending_review')),
            'approved' => Tab::make('Одобрены')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'approved')),
            'rejected' => Tab::make('Отклонены')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'rejected')),
            'no_photos' => Tab::make('Без фото / поиск приостановлен')
                ->modifyQueryUsing(fn (Builder $query) => $query
                    ->where('status', 'pending_review')
                    ->where(fn (Builder $q) => $q
                        ->whereDoesntHave('media')
                        ->orWhereNotNull('gallery_search_stop_reason'))),
        ];
    }
}
