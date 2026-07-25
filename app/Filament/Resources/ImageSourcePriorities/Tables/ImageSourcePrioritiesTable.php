<?php

namespace App\Filament\Resources\ImageSourcePriorities\Tables;

use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ImageSourcePrioritiesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('priority', 'desc')
            ->columns([
                TextColumn::make('priority')
                    ->label('Приоритет')
                    ->badge()
                    ->sortable(),
                TextColumn::make('name')
                    ->label('Источник')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('domain')
                    ->label('Домен')
                    ->copyable()
                    ->searchable(),
                TextColumn::make('source_type')
                    ->label('Тип')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'marketplace' => 'Маркетплейс',
                        'retailer' => 'Магазин',
                        'manufacturer' => 'Производитель',
                        'database' => 'База',
                        'review' => 'Обзор',
                        default => 'Сайт',
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'marketplace' => 'primary',
                        'retailer' => 'success',
                        'manufacturer' => 'info',
                        default => 'gray',
                    }),
                TextColumn::make('aliases')
                    ->label('Доп. домены')
                    ->state(fn ($record): string => collect($record->aliases)->implode(', '))
                    ->placeholder('—')
                    ->wrap()
                    ->toggleable(),
                ToggleColumn::make('is_enabled')
                    ->label('Активен')
                    ->sortable(),
                TextColumn::make('updated_at')
                    ->label('Изменён')
                    ->dateTime('d.m.Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('source_type')
                    ->label('Тип')
                    ->options([
                        'marketplace' => 'Маркетплейс',
                        'retailer' => 'Магазин',
                        'manufacturer' => 'Производитель',
                        'database' => 'База товаров',
                        'review' => 'Обзор',
                        'web' => 'Другой сайт',
                    ]),
                SelectFilter::make('is_enabled')
                    ->label('Состояние')
                    ->options([
                        1 => 'Активные',
                        0 => 'Выключенные',
                    ]),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }
}
