<?php

namespace App\Filament\Resources\ImageSourcePriorities\Pages;

use App\Filament\Resources\ImageSourcePriorities\ImageSourcePriorityResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditImageSourcePriority extends EditRecord
{
    protected static string $resource = ImageSourcePriorityResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
