<?php

namespace App\Filament\Resources\ProductGalleryRecipeVersions;

use App\Filament\Resources\ProductGalleryRecipeVersions\Pages\ListProductGalleryRecipeVersions;
use App\Filament\Resources\ProductGalleryRecipeVersions\Tables\ProductGalleryRecipeVersionsTable;
use App\Models\ProductGalleryRecipeVersion;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class ProductGalleryRecipeVersionResource extends Resource
{
    protected static ?string $model = ProductGalleryRecipeVersion::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static ?string $navigationLabel = 'Версии рецептов';

    protected static ?string $modelLabel = 'версия рецепта';

    protected static ?string $pluralModelLabel = 'версии рецептов';

    protected static string|UnitEnum|null $navigationGroup = 'Поиск и AI';

    protected static ?int $navigationSort = 3;

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
