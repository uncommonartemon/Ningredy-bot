<?php

namespace App\Filament\Resources\AttributeGroups\Tables;

use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;

class AttributeGroupsTable
{
    public static function configure(Table $table): Table
    {
        return $table->defaultPaginationPageOption(50)->defaultSort('sort_order')
            ->modifyQueryUsing(fn ($query) => $query->withCount('attributeDefinitions'))
            ->reorderable('sort_order')
            ->columns([
                TextColumn::make('name')->label(__('catalog-groups.english_name'))->searchable()->sortable(),
                TextColumn::make('translations.cs')->label(__('catalog-groups.czech_name'))->placeholder('—'),
                TextColumn::make('attribute_definitions_count')->label(__('catalog-groups.attributes'))->badge()->sortable(),
                ToggleColumn::make('highlight_in_filters')->label(__('catalog-groups.highlight')),
                TextColumn::make('sort_order')->label(__('Порядок'))->sortable(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()->modalDescription(__('catalog-groups.delete_help')),
            ]);
    }
}
