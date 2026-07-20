<?php

namespace App\Filament\Resources\AiRuns\Pages;

use App\Filament\Resources\AiRuns\AiRunResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditAiRun extends EditRecord
{
    protected static string $resource = AiRunResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }
}
