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
            ->modifyQueryUsing(fn ($query) => $query->withCount('values'))
            ->defaultSort('sort_order')
            ->columns([
                TextColumn::make('id')->label('#')->sortable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('key')->label('Ключ')->searchable()->sortable(),
                TextColumn::make('label')->label('Название')->searchable()->sortable(),
                TextColumn::make('data_type')->label('Тип')->badge(),
                TextColumn::make('default_unit')->label('Единица')->placeholder('—'),
                IconColumn::make('is_filterable')->label('Фильтр')->boolean(),
                IconColumn::make('is_variant')->label('SKU')->boolean(),
                TextColumn::make('values_count')->label('Использований')->badge()->sortable(),
                TextColumn::make('sort_order')->label('Порядок')->sortable(),
                TextColumn::make('created_at')->label('Создан')->dateTime('d.m.Y H:i')->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }
}
