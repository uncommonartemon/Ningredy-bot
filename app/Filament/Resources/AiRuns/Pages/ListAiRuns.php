<?php

namespace App\Filament\Resources\AiRuns\Pages;

use App\Filament\Resources\AiRuns\AiRunResource;
use Filament\Resources\Pages\ListRecords;

class ListAiRuns extends ListRecords
{
    protected static string $resource = AiRunResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
