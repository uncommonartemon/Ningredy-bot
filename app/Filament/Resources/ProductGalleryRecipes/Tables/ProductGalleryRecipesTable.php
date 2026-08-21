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
                TextColumn::make('domain')
                    ->label('Домен')
                    ->searchable()
                    ->copyable()
                    ->sortable(),
                TextColumn::make('status')
                    ->label('Состояние')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'active' => 'Активен',
                        'learning' => 'Обучается',
                        'disabled' => 'Отключён',
                        default => $state,
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'active' => 'success',
                        'learning' => 'warning',
                        'disabled' => 'gray',
                        default => 'gray',
                    }),
                IconColumn::make('source_blocked')
                    ->label('Источник заблокирован')
                    ->boolean()
                    ->sortable(),
                TextColumn::make('success_count')
                    ->label('Успехов')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('failure_count')
                    ->label('Ошибок')
                    ->numeric()
                    ->sortable()
                    ->color(fn (int $state): string => $state > 0 ? 'danger' : 'gray'),
                TextColumn::make('last_success_at')
                    ->label('Последний успех')
                    ->dateTime('d.m.Y H:i:s')
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('last_failure_at')
                    ->label('Последняя ошибка')
                    ->dateTime('d.m.Y H:i:s')
                    ->placeholder('—')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('last_error')
                    ->label('Ошибка')
                    ->limit(70)
                    ->placeholder('—')
                    ->tooltip(fn ($record): ?string => $record->last_error)
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Состояние')
                    ->options([
                        'active' => 'Активные',
                        'learning' => 'Обучаются',
                        'disabled' => 'Отключённые',
                    ]),
            ])
            ->recordActions([
                ViewAction::make(),
                ActionGroup::make([
                    EditAction::make()->label('Быстрое редактирование'),
                    DeleteAction::make()
                        ->label('Удалить и обучить заново')
                        ->modalDescription('При следующем обращении к домену Playwright автоматически создаст новый рецепт.'),
                ]),
            ]);
    }
}
