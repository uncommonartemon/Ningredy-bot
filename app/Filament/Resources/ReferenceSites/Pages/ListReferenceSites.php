<?php

namespace App\Filament\Resources\ReferenceSites\Pages;

use App\Filament\Resources\ReferenceSites\ReferenceSiteResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListReferenceSites extends ListRecords
{
    protected static string $resource = ReferenceSiteResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
