<?php

namespace App\Filament\Resources\ProductGalleryRecipeVersions;

use App\Filament\Resources\ProductGalleryRecipeVersions\Pages\ListProductGalleryRecipeVersions;
use App\Filament\Resources\ProductGalleryRecipeVersions\Tables\ProductGalleryRecipeVersionsTable;
use App\Models\ProductGalleryRecipeVersion;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use UnitEnum;

class ProductGalleryRecipeVersionResource extends Resource
{
    protected static ?string $model = ProductGalleryRecipeVersion::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-clock';

    protected static ?string $navigationLabel = 'История рецептов';

    protected static ?string $modelLabel = 'версия рецепта';

    protected static ?string $pluralModelLabel = 'история рецептов';

    protected static string|UnitEnum|null $navigationGroup = 'AI и автоматизация';

    protected static ?int $navigationSort = 5;

    public static function table(Table $table): Table
    {
        return ProductGalleryRecipeVersionsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProductGalleryRecipeVersions::route('/'),
        ];
    }
}
