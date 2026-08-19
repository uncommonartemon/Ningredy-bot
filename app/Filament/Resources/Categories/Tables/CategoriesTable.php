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
                TextColumn::make('name')->label('English name')->searchable()->sortable(),
                TextColumn::make('slug')->label('Slug')->searchable()->sortable(),
                TextColumn::make('translations_summary')->label('Переводы')
                    ->state(fn ($record): string => $record->translations
                        ->map(fn ($translation): string => strtoupper($translation->locale).': '.$translation->name)
                        ->implode(' · '))
                    ->wrap(),
                TextColumn::make('parent.name')->label('Родитель')->placeholder('—'),
                TextColumn::make('gallery_search_strategy')
                    ->label('Фото-поиск')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        Category::GALLERY_SEARCH_VISION_FIRST => 'Vision-first',
                        Category::GALLERY_SEARCH_PLAYWRIGHT_FIRST => 'Playwright-first',
                        default => 'Авто',
                    })
                    ->color(fn (?string $state): string => match ($state) {
                        Category::GALLERY_SEARCH_VISION_FIRST => 'info',
                        Category::GALLERY_SEARCH_PLAYWRIGHT_FIRST => 'success',
                        default => 'gray',
                    }),
                TextColumn::make('minimum_verified_images')
                    ->label('Мин. фото')
                    ->badge()
                    ->sortable(),
                TextColumn::make('product_search_hint')
                    ->label('Подсказка поиска')
                    ->limit(70)
                    ->placeholder('—')
                    ->wrap(),
                TextColumn::make('products_count')->label('Товары')->badge()->sortable(),
                TextColumn::make('sort_order')->label('Порядок')->sortable(),
                ToggleColumn::make('is_active')->label('Активна')->sortable(),
            ])
            ->recordActions([EditAction::make()]);
    }
}
