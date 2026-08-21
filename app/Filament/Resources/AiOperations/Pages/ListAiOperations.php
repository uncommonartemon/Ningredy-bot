<?php

namespace App\Filament\Resources\AiOperations\Pages;

use App\Filament\Resources\AiOperations\AiOperationResource;
use Filament\Resources\Pages\ListRecords;

class ListAiOperations extends ListRecords
{
    protected static string $resource = AiOperationResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
