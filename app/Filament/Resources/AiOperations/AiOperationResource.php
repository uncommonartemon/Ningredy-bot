<?php

namespace App\Filament\Resources\AiOperations;

use App\Filament\Resources\AiOperations\Pages\ListAiOperations;
use App\Filament\Resources\AiOperations\Pages\ViewAiOperation;
use App\Filament\Resources\AiOperations\Schemas\AiOperationInfolist;
use App\Filament\Resources\AiOperations\Tables\AiOperationsTable;
use App\Models\AiOperation;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class AiOperationResource extends Resource
{
    protected static ?string $model = AiOperation::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBolt;

    protected static ?string $navigationLabel = 'AI-операции';

    protected static ?string $modelLabel = 'AI-операция';

    protected static ?string $pluralModelLabel = 'AI-операции';

    protected static string|UnitEnum|null $navigationGroup = 'Поиск и AI';

    protected static ?int $navigationSort = 6;

    public static function infolist(Schema $schema): Schema
    {
        return AiOperationInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AiOperationsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAiOperations::route('/'),
            'view' => ViewAiOperation::route('/{record}'),
        ];
    }
}
