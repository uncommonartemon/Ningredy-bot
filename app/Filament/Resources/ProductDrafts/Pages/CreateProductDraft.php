<?php

namespace App\Filament\Resources\ProductDrafts\Pages;

use App\Filament\Resources\ProductDrafts\ProductDraftResource;
use Filament\Resources\Pages\CreateRecord;

class CreateProductDraft extends CreateRecord
{
    protected static string $resource = ProductDraftResource::class;
}
