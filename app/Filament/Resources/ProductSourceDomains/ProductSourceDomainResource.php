<?php

namespace App\Filament\Resources\ProductSourceDomains;

use App\Filament\Resources\ProductSourceDomains\Pages\CreateProductSourceDomain;
use App\Filament\Resources\ProductSourceDomains\Pages\EditProductSourceDomain;
use App\Filament\Resources\ProductSourceDomains\Pages\ListProductSourceDomains;
use App\Filament\Resources\ProductSourceDomains\Schemas\ProductSourceDomainForm;
use App\Filament\Resources\ProductSourceDomains\Tables\ProductSourceDomainsTable;
use App\Models\ProductSourceDomain;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class ProductSourceDomainResource extends Resource
{
    protected static ?string $model = ProductSourceDomain::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedGlobeAlt;

    protected static ?string $navigationLabel = 'Домены источников';

    protected static ?string $modelLabel = 'домен источника';

    protected static ?string $pluralModelLabel = 'домены источников';

    protected static string|UnitEnum|null $navigationGroup = 'Поиск и AI';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'domain';

    public static function form(Schema $schema): Schema
    {
        return ProductSourceDomainForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ProductSourceDomainsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProductSourceDomains::route('/'),
            'create' => CreateProductSourceDomain::route('/create'),
            'edit' => EditProductSourceDomain::route('/{record}/edit'),
        ];
    }
}
