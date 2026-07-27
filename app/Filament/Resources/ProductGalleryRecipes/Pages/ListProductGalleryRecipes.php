<?php

namespace App\Filament\Resources\ProductGalleryRecipes\Pages;

use App\Filament\Resources\ProductGalleryRecipes\ProductGalleryRecipeResource;
use Filament\Resources\Pages\ListRecords;

class ListProductGalleryRecipes extends ListRecords
{
    protected static string $resource = ProductGalleryRecipeResource::class;
}
