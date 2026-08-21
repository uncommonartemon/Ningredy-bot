<?php

namespace App\Filament\Resources\ProductGalleryRecipes\Pages;

use App\Filament\Resources\ProductGalleryRecipes\ProductGalleryRecipeResource;
use Filament\Resources\Pages\EditRecord;

class EditProductGalleryRecipe extends EditRecord
{
    protected static string $resource = ProductGalleryRecipeResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
