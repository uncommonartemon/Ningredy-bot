<?php

namespace App\Filament\Resources\ReferenceSites\Tables;

use App\Models\ReferenceSite;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ReferenceSitesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->columns([
                TextColumn::make('domain')->label(__('Сайт'))->searchable()->sortable(),
                TextColumn::make('applies_to')->label(__('Для каких товаров'))->limit(50)->wrap()->searchable()
                    ->tooltip(fn (ReferenceSite $record): string => $record->applies_to),
                TextColumn::make('take')->label(__('Что брать'))
                    ->state(fn (ReferenceSite $record): string => match (true) {
                        $record->use_for_facts && $record->use_for_photos => __('характеристики и фото'),
                        $record->use_for_photos => __('только фото'),
                        default => __('только характеристики'),
                    }),
                TextColumn::make('access')->label(__('Кто открывает'))->badge()
                    ->formatStateUsing(fn (string $state): string => $state === ReferenceSite::ACCESS_PERSON ? __('только человек') : __('бот'))
                    ->color(fn (string $state): string => $state === ReferenceSite::ACCESS_PERSON ? 'warning' : 'success'),
                IconColumn::make('enabled')->label(__('Включён'))->boolean(),
                TextColumn::make('hits')->label(__('Попадания'))->sortable(),
                TextColumn::make('misses')->label(__('Промахи'))->sortable(),
                TextColumn::make('last_result')->label(__('Последний результат'))->limit(40)->placeholder('—')
                    ->tooltip(fn (ReferenceSite $record): ?string => $record->last_result)->toggleable(),
                TextColumn::make('last_used_at')->label(__('Использован'))->dateTime('d.m.Y H:i')->placeholder('—')->toggleable(),
                TextColumn::make('sort_order')->label(__('Порядок'))->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }
}
