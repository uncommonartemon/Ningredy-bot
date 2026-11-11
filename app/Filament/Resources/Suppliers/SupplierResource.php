<?php

namespace App\Filament\Resources\Suppliers;

use App\Filament\Resources\LocalizedResource as Resource;
use App\Filament\Resources\Suppliers\Pages\ManageSuppliers;
use App\Models\Supplier;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Who the shop buys from (operator, 2026-11-11). The list the forms pick a supplier from;
 * a new name can be added here or right in the form that needs it. What was bought from each
 * is counted from the units.
 */
class SupplierResource extends Resource
{
    protected static ?string $model = Supplier::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTruck;

    protected static ?string $navigationLabel = 'Поставщики';

    protected static ?string $modelLabel = 'поставщик';

    protected static ?string $pluralModelLabel = 'поставщики';

    protected static string|UnitEnum|null $navigationGroup = 'Склад и учёт';

    protected static ?int $navigationSort = 3;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            TextInput::make('name')->label(__('Название поставщика'))->required()->maxLength(255)->unique(ignoreRecord: true)
                ->helperText(__('Переименование меняет название и у купленных у него единиц.')),
            Textarea::make('note')->label(__('Заметка'))->rows(3)->maxLength(2000),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->withCount('units')->withSum('units', 'purchase_price'))
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')->label(__('Название поставщика'))->searchable()->sortable(),
                TextColumn::make('units_count')->label(__('Куплено штук'))->numeric()->alignEnd()->sortable(),
                TextColumn::make('units_sum_purchase_price')->label(__('На сумму закупки'))->numeric(decimalPlaces: 2)
                    ->placeholder('—')->alignEnd()->sortable(),
                TextColumn::make('note')->label(__('Заметка'))->limit(60)->placeholder('—'),
            ])
            ->recordActions([
                EditAction::make(),
                // A name the books still use stays in the list: its units would otherwise name nobody.
                DeleteAction::make()->visible(fn (Supplier $record): bool => (int) $record->units_count === 0),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageSuppliers::route('/')];
    }
}
