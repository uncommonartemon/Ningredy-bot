<?php

namespace App\Filament\Resources\AttributeDefinitions\Tables;

use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class AttributeDefinitionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->columns([
                TextColumn::make('key')->label('Ключ')->searchable()->sortable(),
                TextColumn::make('label')->label('Название')->searchable()->sortable(),
                TextColumn::make('data_type')->label('Тип')->badge(),
                TextColumn::make('default_unit')->label('Единица')->placeholder('—'),
                IconColumn::make('is_filterable')->label('Фильтр')->boolean(),
                IconColumn::make('is_variant')->label('SKU')->boolean(),
                TextColumn::make('sort_order')->label('Порядок')->sortable(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }
}
