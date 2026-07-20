<?php

namespace App\Filament\Resources\ProductDrafts\Pages;

use App\Filament\Resources\ProductDrafts\ProductDraftResource;
use Filament\Resources\Pages\ListRecords;

class ListProductDrafts extends ListRecords
{
    protected static string $resource = ProductDraftResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
