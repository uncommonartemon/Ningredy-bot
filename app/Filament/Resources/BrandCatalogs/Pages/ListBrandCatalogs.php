<?php

namespace App\Filament\Resources\BrandCatalogs\Pages;

use App\Filament\Resources\BrandCatalogs\BrandCatalogResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListBrandCatalogs extends ListRecords
{
    protected static string $resource = BrandCatalogResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
