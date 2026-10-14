<?php

namespace App\Filament\Resources\Categories\Tables;

use App\Models\Category;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;

class CategoriesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('translations')->withCount('products'))
            ->defaultSort('sort_order')
            ->columns([
                TextColumn::make('id')->label('#')->sortable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('name')->label('English name')->searchable()->sortable(),
                TextColumn::make('slug')->label('Slug')->searchable()->sortable(),
                TextColumn::make('product_type_affinity')
                    ->label(__('Тип товара'))
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        'laptop' => __('Ноутбук'),
                        'desktop' => __('ПК'),
                        'component' => __('Компонент'),
                        'other' => __('Другое'),
                        default => __('Не задан'),
                    }),
                TextColumn::make('translations_summary')->label(__('Переводы'))
                    ->state(fn ($record): string => $record->translations
                        ->map(fn ($translation): string => strtoupper($translation->locale).': '.$translation->name)
                        ->implode(' · '))
                    ->wrap(),
                TextColumn::make('parent.name')->label(__('Родитель'))->placeholder('—'),
                TextColumn::make('gallery_search_strategy')
                    ->label(__('Фото-поиск'))
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        Category::GALLERY_SEARCH_VISION_FIRST => 'Vision-first',
                        Category::GALLERY_SEARCH_PLAYWRIGHT_FIRST => 'Playwright-first',
                        default => __('Авто'),
                    })
                    ->color(fn (?string $state): string => match ($state) {
                        Category::GALLERY_SEARCH_VISION_FIRST => 'info',
                        Category::GALLERY_SEARCH_PLAYWRIGHT_FIRST => 'success',
                        default => 'gray',
                    }),
                TextColumn::make('minimum_verified_images')
                    ->label(__('Мин. фото'))
                    ->badge()
                    ->sortable(),
                TextColumn::make('product_search_hint')
                    ->label(__('Подсказка поиска'))
                    ->limit(60)
                    ->placeholder('—')
                    ->tooltip(fn (?string $state): ?string => filled($state) ? $state : null),
                TextColumn::make('products_count')->label(__('Товары'))->badge()->sortable(),
                TextColumn::make('sort_order')->label(__('Порядок'))->sortable(),
                ToggleColumn::make('is_active')->label(__('Активна'))->sortable(),
                TextColumn::make('created_at')->label(__('Создана'))->dateTime('d.m.Y H:i')->sortable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')->label(__('Изменена'))->dateTime('d.m.Y H:i')->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions([EditAction::make()]);
    }
}
