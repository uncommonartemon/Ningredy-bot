<?php

namespace App\Filament\Resources\TelegramUpdates\Pages;

use App\Filament\Resources\TelegramUpdates\TelegramUpdateResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewTelegramUpdate extends ViewRecord
{
    protected static string $resource = TelegramUpdateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
