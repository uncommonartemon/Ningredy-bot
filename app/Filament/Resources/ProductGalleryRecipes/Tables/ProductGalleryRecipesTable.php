<?php

namespace App\Filament\Resources\ProductGalleryRecipes\Tables;

use App\Filament\Resources\ProductGalleryRecipes\ProductGalleryRecipeResource;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ProductGalleryRecipesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->recordUrl(fn ($record): string => ProductGalleryRecipeResource::getUrl('view', ['record' => $record]))
            ->defaultSort('last_success_at', 'desc')
            ->columns([
                TextColumn::make('id')->label('#')->sortable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('domain')
                    ->label(__('Домен'))
                    ->searchable()
                    ->copyable()
                    ->sortable(),
                TextColumn::make('path_pattern')
                    ->label(__('Шаблон пути'))
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('region')
                    ->label(__('Регион'))
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('status')
                    ->label(__('Состояние'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'active' => __('Активен'),
                        'learning' => __('Обучается'),
                        'disabled' => __('Отключён'),
                        default => $state,
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'active' => 'success',
                        'learning' => 'warning',
                        'disabled' => 'gray',
                        default => 'gray',
                    }),
                IconColumn::make('source_blocked')
                    ->label(__('Источник заблокирован'))
                    ->boolean()
                    ->sortable(),
                TextColumn::make('success_count')
                    ->label(__('Успехов'))
                    ->numeric()
                    ->sortable(),
                TextColumn::make('failure_count')
                    ->label(__('Ошибок'))
                    ->numeric()
                    ->sortable()
                    ->color(fn (int $state): string => $state > 0 ? 'danger' : 'gray'),
                TextColumn::make('last_success_at')
                    ->label(__('Последний успех'))
                    ->dateTime('d.m.Y H:i:s')
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('last_failure_at')
                    ->label(__('Последняя ошибка'))
                    ->dateTime('d.m.Y H:i:s')
                    ->placeholder('—')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('last_error')
                    ->label(__('Ошибка'))
                    ->limit(70)
                    ->placeholder('—')
                    ->tooltip(fn ($record): ?string => $record->last_error)
                    ->toggleable(),
                TextColumn::make('last_failure_kind')
                    ->label(__('Тип сбоя'))
                    ->badge()
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('retry_after')
                    ->label(__('Повтор не раньше'))
                    ->dateTime('d.m.Y H:i:s')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('Состояние'))
                    ->options([
                        'active' => __('Активные'),
                        'learning' => __('Обучаются'),
                        'disabled' => __('Отключённые'),
                    ]),
            ])
            ->recordActions([
                ViewAction::make(),
                ActionGroup::make([
                    EditAction::make()->label(__('Быстрое редактирование')),
                    DeleteAction::make()
                        ->label(__('Удалить и обучить заново'))
                        ->modalDescription(__('При следующем обращении к домену Playwright автоматически создаст новый рецепт.')),
                ]),
            ]);
    }
}
