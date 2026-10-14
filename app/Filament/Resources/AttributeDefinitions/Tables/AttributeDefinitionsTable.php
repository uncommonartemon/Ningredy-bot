<?php

namespace App\Filament\Resources\AttributeDefinitions\Tables;

use App\Filament\Schemas\RelatedCatalogForms;
use Filament\Actions\BulkAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class AttributeDefinitionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultPaginationPageOption(50)
            ->modifyQueryUsing(fn ($query) => $query->with('group')->withCount('values'))
            ->defaultSort('sort_order')
            ->columns([
                TextColumn::make('id')->label('#')->sortable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('key')->label(__('Ключ'))->searchable()->sortable(),
                TextColumn::make('label')->label(__('Название'))->searchable()->sortable(),
                TextColumn::make('group.name')->label(__('catalog-groups.group'))->badge()->sortable()->searchable()
                    ->placeholder(__('catalog-groups.ungrouped')),
                TextColumn::make('data_type')->label(__('Тип'))->badge(),
                TextColumn::make('default_unit')->label(__('Единица'))->placeholder('—'),
                IconColumn::make('is_filterable')->label(__('Фильтр'))->boolean(),
                IconColumn::make('is_variant')->label('SKU')->boolean(),
                TextColumn::make('values_count')->label(__('Использований'))->badge()->sortable(),
                TextColumn::make('sort_order')->label(__('Порядок'))->sortable(),
                TextColumn::make('created_at')->label(__('Создан'))->dateTime('d.m.Y H:i')->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('attribute_group_id')->label(__('catalog-groups.group'))->relationship('group', 'name')->searchable()->preload(),
            ])
            ->groups([Group::make('group.name')->label(__('catalog-groups.group'))->collapsible()])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkAction::make('assignGroup')->label(__('catalog-groups.assign'))->icon('heroicon-o-rectangle-group')
                    ->schema([RelatedCatalogForms::attributeGroupSelect()->helperText(__('catalog-groups.bulk_help'))])
                    ->action(function (Collection $records, array $data): void {
                        DB::transaction(function () use ($records, $data): void {
                            foreach ($records as $record) {
                                $record->update(['attribute_group_id' => $data['attribute_group_id'] ?? null]);
                            }
                        });
                    })->deselectRecordsAfterCompletion(),
            ]);
    }
}
