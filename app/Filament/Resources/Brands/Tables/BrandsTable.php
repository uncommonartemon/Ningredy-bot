<?php

namespace App\Filament\Resources\Brands\Tables;

use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;

class BrandsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->withCount('products'))
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')->label('Название')->searchable()->sortable(),
                TextColumn::make('slug')->label('Slug')->searchable()->sortable(),
                TextColumn::make('country')->label('Страна')->placeholder('—')->sortable(),
                TextColumn::make('website_url')->label('Сайт')->url(fn (?string $state): ?string => $state)->openUrlInNewTab()->placeholder('—')->limit(30),
                TextColumn::make('products_count')->label('Товары')->badge()->sortable(),
                ToggleColumn::make('is_active')->label('Активен')->sortable(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }
}
