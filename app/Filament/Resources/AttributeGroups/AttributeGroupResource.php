<?php

namespace App\Filament\Resources\AttributeGroups;

use App\Filament\Resources\AttributeGroups\Pages\CreateAttributeGroup;
use App\Filament\Resources\AttributeGroups\Pages\EditAttributeGroup;
use App\Filament\Resources\AttributeGroups\Pages\ListAttributeGroups;
use App\Filament\Resources\AttributeGroups\Schemas\AttributeGroupForm;
use App\Filament\Resources\AttributeGroups\Tables\AttributeGroupsTable;
use App\Filament\Resources\LocalizedResource as Resource;
use App\Models\AttributeGroup;
use BackedEnum;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class AttributeGroupResource extends Resource
{
    protected static ?string $model = AttributeGroup::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleGroup;

    protected static ?string $navigationLabel = 'catalog-groups.groups';

    protected static ?string $modelLabel = 'catalog-groups.group';

    protected static ?string $pluralModelLabel = 'catalog-groups.groups';

    protected static string|UnitEnum|null $navigationGroup = 'Каталог';

    protected static ?int $navigationSort = 6;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return AttributeGroupForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AttributeGroupsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAttributeGroups::route('/'),
            'create' => CreateAttributeGroup::route('/create'),
            'edit' => EditAttributeGroup::route('/{record}/edit'),
        ];
    }
}
