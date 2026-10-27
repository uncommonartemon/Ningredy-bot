<?php

namespace App\Filament\Resources\BrandCatalogs;

use App\Filament\Resources\BrandCatalogs\Pages\CreateBrandCatalog;
use App\Filament\Resources\BrandCatalogs\Pages\EditBrandCatalog;
use App\Filament\Resources\BrandCatalogs\Pages\ListBrandCatalogs;
use App\Filament\Resources\BrandCatalogs\Schemas\BrandCatalogForm;
use App\Filament\Resources\BrandCatalogs\Tables\BrandCatalogsTable;
use App\Filament\Resources\LocalizedResource as Resource;
use App\Models\BrandCatalog;
use BackedEnum;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class BrandCatalogResource extends Resource
{
    protected static ?string $model = BrandCatalog::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBookOpen;

    protected static ?string $navigationLabel = 'Каталоги брендов';

    protected static ?string $modelLabel = 'каталог бренда';

    protected static ?string $pluralModelLabel = 'каталоги брендов';

    protected static string|UnitEnum|null $navigationGroup = 'Поиск и AI';

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'brand';

    public static function form(Schema $schema): Schema
    {
        return BrandCatalogForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return BrandCatalogsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListBrandCatalogs::route('/'),
            'create' => CreateBrandCatalog::route('/create'),
            'edit' => EditBrandCatalog::route('/{record}/edit'),
        ];
    }
}
