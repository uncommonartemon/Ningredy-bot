<?php

namespace App\Filament\Resources\BrandCatalogs\Pages;

use App\Filament\Resources\BrandCatalogs\BrandCatalogResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditBrandCatalog extends EditRecord
{
    protected static string $resource = BrandCatalogResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
