<?php

namespace App\Filament\Resources\ProductGalleryRecipes;

use App\Filament\Resources\ProductGalleryRecipes\Pages\EditProductGalleryRecipe;
use App\Filament\Resources\ProductGalleryRecipes\Pages\ListProductGalleryRecipes;
use App\Filament\Resources\ProductGalleryRecipes\Pages\ViewProductGalleryRecipe;
use App\Filament\Resources\ProductGalleryRecipes\Schemas\ProductGalleryRecipeForm;
use App\Filament\Resources\ProductGalleryRecipes\Schemas\ProductGalleryRecipeInfolist;
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

    protected static string|UnitEnum|null $navigationGroup = 'Поиск и AI';

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'domain';

    public static function getNavigationBadge(): ?string
    {
        $count = ProductGalleryRecipe::query()
            ->where('source_blocked', true)
            ->orWhere('status', 'learning')
            ->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        $blocked = ProductGalleryRecipe::query()->where('source_blocked', true)->exists();

        return $blocked ? 'danger' : 'warning';
    }

    public static function form(Schema $schema): Schema
    {
        return ProductGalleryRecipeForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return ProductGalleryRecipeInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ProductGalleryRecipesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProductGalleryRecipes::route('/'),
            'view' => ViewProductGalleryRecipe::route('/{record}'),
            'edit' => EditProductGalleryRecipe::route('/{record}/edit'),
        ];
    }
}
