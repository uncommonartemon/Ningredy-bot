<?php

namespace App\Filament\Resources\BrandCatalogs\Tables;

use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class BrandCatalogsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('brand')
            ->columns([
                TextColumn::make('brand')->label(__('Бренд'))->searchable()->sortable(),
                TextColumn::make('catalog_url')->label(__('Каталог'))->limit(40)->placeholder('—')->copyable(),
                TextColumn::make('search_hint')->label(__('Поиск'))->limit(40)->placeholder('—'),
                IconColumn::make('lookup_template')->label(__('Страница по коду'))->boolean()
                    ->state(fn ($record): bool => filled($record->lookup_template)),
                IconColumn::make('api_template')->label(__('API'))->boolean()
                    ->state(fn ($record): bool => filled($record->api_template)),
                IconColumn::make('enabled')->label(__('Включён'))->boolean(),
                TextColumn::make('hits')->label(__('Попадания'))->sortable(),
                TextColumn::make('misses')->label(__('Промахи'))->sortable(),
                TextColumn::make('last_result')->label(__('Последний результат'))->limit(50)->placeholder('—')
                    ->tooltip(fn ($record): ?string => $record->last_result)->toggleable(),
                TextColumn::make('last_used_at')->label(__('Использован'))->dateTime('d.m.Y H:i')->placeholder('—')->toggleable(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }
}
