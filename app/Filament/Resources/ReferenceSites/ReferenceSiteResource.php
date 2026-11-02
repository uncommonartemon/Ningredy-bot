<?php

namespace App\Filament\Resources\ReferenceSites;

use App\Filament\Resources\LocalizedResource as Resource;
use App\Filament\Resources\ReferenceSites\Pages\CreateReferenceSite;
use App\Filament\Resources\ReferenceSites\Pages\EditReferenceSite;
use App\Filament\Resources\ReferenceSites\Pages\ListReferenceSites;
use App\Filament\Resources\ReferenceSites\Schemas\ReferenceSiteForm;
use App\Filament\Resources\ReferenceSites\Tables\ReferenceSitesTable;
use App\Models\ReferenceSite;
use BackedEnum;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class ReferenceSiteResource extends Resource
{
    protected static ?string $model = ReferenceSite::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBookmarkSquare;

    protected static ?string $navigationLabel = 'Справочники';

    protected static ?string $modelLabel = 'справочный сайт';

    protected static ?string $pluralModelLabel = 'справочные сайты';

    protected static string|UnitEnum|null $navigationGroup = 'Поиск и AI';

    protected static ?int $navigationSort = 3;

    protected static ?string $recordTitleAttribute = 'domain';

    public static function form(Schema $schema): Schema
    {
        return ReferenceSiteForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ReferenceSitesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListReferenceSites::route('/'),
            'create' => CreateReferenceSite::route('/create'),
            'edit' => EditReferenceSite::route('/{record}/edit'),
        ];
    }
}
