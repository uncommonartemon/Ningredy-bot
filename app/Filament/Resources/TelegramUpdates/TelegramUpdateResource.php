<?php

namespace App\Filament\Resources\TelegramUpdates;

use App\Filament\Resources\TelegramUpdates\Pages\ListTelegramUpdates;
use App\Filament\Resources\TelegramUpdates\Pages\ViewTelegramUpdate;
use App\Filament\Resources\TelegramUpdates\Schemas\TelegramUpdateForm;
use App\Filament\Resources\TelegramUpdates\Schemas\TelegramUpdateInfolist;
use App\Filament\Resources\TelegramUpdates\Tables\TelegramUpdatesTable;
use App\Models\TelegramUpdate;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class TelegramUpdateResource extends Resource
{
    protected static ?string $model = TelegramUpdate::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static ?string $navigationLabel = 'Telegram журнал';

    protected static ?string $modelLabel = 'Telegram update';

    protected static ?string $pluralModelLabel = 'Telegram журнал';

    protected static ?int $navigationSort = 4;

    public static function form(Schema $schema): Schema
    {
        return TelegramUpdateForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return TelegramUpdateInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return TelegramUpdatesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTelegramUpdates::route('/'),
            'view' => ViewTelegramUpdate::route('/{record}'),
        ];
    }
}
