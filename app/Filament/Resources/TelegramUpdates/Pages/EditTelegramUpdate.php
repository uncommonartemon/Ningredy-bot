<?php

namespace App\Filament\Resources\TelegramUpdates\Pages;

use App\Filament\Resources\TelegramUpdates\TelegramUpdateResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditTelegramUpdate extends EditRecord
{
    protected static string $resource = TelegramUpdateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }
}
