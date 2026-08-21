<?php

namespace App\Filament\Resources\ProductSourceDomains\Tables;

use App\Filament\Resources\ProductGalleryRecipes\ProductGalleryRecipeResource;
use App\Models\ProductGalleryRecipe;
use App\Models\ProductSourceDomain;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ProductSourceDomainsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('domain')
            ->columns([
                TextColumn::make('id')->label('#')->sortable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('domain')
                    ->label('Домен')
                    ->searchable()
                    ->copyable()
                    ->sortable(),
                TextColumn::make('agent_hint')
                    ->label('Подсказка агенту')
                    ->limit(80)
                    ->placeholder('—')
                    ->tooltip(fn ($record): ?string => $record->agent_hint),
                TextColumn::make('recipe_status')
                    ->label('Рецепт')
                    ->badge()
                    ->state(fn (ProductSourceDomain $record): string => match (self::recipeFor($record)?->status) {
                        'active' => 'Активен',
                        'learning' => 'Обучается',
                        'disabled' => 'Отключён',
                        default => 'Нет рецепта',
                    })
                    ->color(fn (ProductSourceDomain $record): string => match (self::recipeFor($record)?->status) {
                        'active' => 'success',
                        'learning' => 'warning',
                        'disabled' => 'gray',
                        default => 'gray',
                    })
                    ->url(fn (ProductSourceDomain $record): ?string => self::recipeFor($record)
                        ? ProductGalleryRecipeResource::getUrl('view', ['record' => self::recipeFor($record)])
                        : null),
                IconColumn::make('recipe_blocked')
                    ->label('Заблокирован')
                    ->boolean()
                    ->state(fn (ProductSourceDomain $record): bool => (bool) self::recipeFor($record)?->source_blocked),
                TextColumn::make('recipe_success_count')
                    ->label('Успехов')
                    ->state(fn (ProductSourceDomain $record): int => self::recipeFor($record)?->success_count ?? 0),
                TextColumn::make('recipe_failure_count')
                    ->label('Ошибок')
                    ->state(fn (ProductSourceDomain $record): int => self::recipeFor($record)?->failure_count ?? 0),
                TextColumn::make('recipe_last_success_at')
                    ->label('Последний успех')
                    ->state(fn (ProductSourceDomain $record): ?string => self::recipeFor($record)?->last_success_at?->format('d.m.Y H:i'))
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('created_at')
                    ->label('Создан')
                    ->dateTime('d.m.Y H:i:s')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->label('Обновлено')
                    ->dateTime('d.m.Y H:i:s')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    private static function recipeFor(ProductSourceDomain $record): ?ProductGalleryRecipe
    {
        static $cache = [];

        if (! array_key_exists($record->domain, $cache)) {
            $cache[$record->domain] = ProductGalleryRecipe::query()
                ->where('domain', $record->domain)
                ->orderByDesc('updated_at')
                ->first();
        }

        return $cache[$record->domain];
    }
}
