<?php

namespace App\Filament\Resources\ProductGalleryRecipes;

use App\Filament\Resources\ProductGalleryRecipes\Pages\EditProductGalleryRecipe;
use App\Filament\Resources\ProductGalleryRecipes\Pages\ListProductGalleryRecipes;
use App\Filament\Resources\ProductGalleryRecipes\Schemas\ProductGalleryRecipeForm;
use App\Filament\Resources\ProductGalleryRecipes\Tables\ProductGalleryRecipesTable;
use App\Models\ProductGalleryRecipe;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class ProductGalleryRecipeResource extends Resource
{
    protected static ?string $model = ProductGalleryRecipe::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPhoto;

    protected static ?string $navigationLabel = 'Рецепты галерей';

    protected static ?string $modelLabel = 'рецепт галереи';

    protected static ?string $pluralModelLabel = 'рецепты галерей';

    protected static string|UnitEnum|null $navigationGroup = 'AI и автоматизация';

    protected static ?int $navigationSort = 4;

    protected static ?string $recordTitleAttribute = 'domain';

    public static function form(Schema $schema): Schema
    {
        return ProductGalleryRecipeForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ProductGalleryRecipesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProductGalleryRecipes::route('/'),
            'edit' => EditProductGalleryRecipe::route('/{record}/edit'),
        ];
    }
}
