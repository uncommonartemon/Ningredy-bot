<?php

namespace App\Filament\Resources\AiRuns;

use App\Filament\Resources\AiRuns\Pages\ListAiRuns;
use App\Filament\Resources\AiRuns\Pages\ViewAiRun;
use App\Filament\Resources\AiRuns\Schemas\AiRunForm;
use App\Filament\Resources\AiRuns\Schemas\AiRunInfolist;
use App\Filament\Resources\AiRuns\Tables\AiRunsTable;
use App\Models\AiRun;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class AiRunResource extends Resource
{
    protected static ?string $model = AiRun::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSparkles;

    protected static ?string $navigationLabel = 'Запуски AI';

    protected static ?string $modelLabel = 'запуск AI';

    protected static ?string $pluralModelLabel = 'запуски AI';

    protected static ?int $navigationSort = 3;

    public static function form(Schema $schema): Schema
    {
        return AiRunForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return AiRunInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AiRunsTable::configure($table);
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
            'index' => ListAiRuns::route('/'),
            'view' => ViewAiRun::route('/{record}'),
        ];
    }
}
