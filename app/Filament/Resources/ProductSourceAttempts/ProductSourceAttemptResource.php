<?php

namespace App\Filament\Resources\ProductSourceAttempts;

use App\Filament\Resources\ProductSourceAttempts\Pages\ListProductSourceAttempts;
use App\Filament\Resources\ProductSourceAttempts\Pages\ViewProductSourceAttempt;
use App\Filament\Resources\ProductSourceAttempts\Schemas\ProductSourceAttemptInfolist;
use App\Filament\Resources\ProductSourceAttempts\Tables\ProductSourceAttemptsTable;
use App\Models\ProductSourceAttempt;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class ProductSourceAttemptResource extends Resource
{
    protected static ?string $model = ProductSourceAttempt::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCommandLine;

    protected static ?string $navigationLabel = 'Попытки источников';

    protected static ?string $modelLabel = 'попытка источника';

    protected static ?string $pluralModelLabel = 'попытки источников';

    protected static string|UnitEnum|null $navigationGroup = 'Поиск и AI';

    protected static ?int $navigationSort = 4;

    public static function infolist(Schema $schema): Schema
    {
        return ProductSourceAttemptInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ProductSourceAttemptsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProductSourceAttempts::route('/'),
            'view' => ViewProductSourceAttempt::route('/{record}'),
        ];
    }
}
