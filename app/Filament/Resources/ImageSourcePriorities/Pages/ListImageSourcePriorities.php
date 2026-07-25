<?php

namespace App\Filament\Resources\ImageSourcePriorities\Pages;

use App\Filament\Resources\ImageSourcePriorities\ImageSourcePriorityResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListImageSourcePriorities extends ListRecords
{
    protected static string $resource = ImageSourcePriorityResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
