<?php

namespace App\Filament\Resources\TelegramUpdates\Pages;

use App\Filament\Resources\ProductDrafts\ProductDraftResource;
use App\Filament\Resources\TelegramUpdates\TelegramUpdateResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;

class ViewTelegramUpdate extends ViewRecord
{
    protected static string $resource = TelegramUpdateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('openDrafts')
                ->label('Черновики этого обновления')
                ->icon('heroicon-o-arrow-top-right-on-square')
                ->color('gray')
                ->visible(fn (): bool => $this->record->productDrafts()->exists())
                ->url(fn (): string => ProductDraftResource::getUrl('index', [
                    'tableFilters' => ['telegram_update_id' => ['value' => $this->record->id]],
                ])),
        ];
    }
}
