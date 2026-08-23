<?php

namespace App\Filament\Resources\Products\Tables;

use App\Filament\Resources\Products\ProductResource;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Storage;

class ProductsTable
{
    public static function configure(Table $table): Table
    {
        return $table->modifyQueryUsing(fn ($query) => $query->with(['primaryMedia', 'media']))
            ->defaultSort('id', 'desc')->columns([
                ImageColumn::make('cover')->label('Фото')
                    ->state(fn ($record): array => $record->media
                        ->where('type', 'image')
                        ->map(fn ($media): ?string => $media->path ?: $media->url)
                        ->filter()
                        ->values()
                        ->all())
                    ->disk('public')
                    ->square()
                    ->imageSize(54)
                    ->stacked()
                    ->overlap(2)
                    ->ring(2)
                    ->limit(3)
                    ->limitedRemainingText()
                    ->url(fn (?string $state): ?string => filled($state)
                        ? (filter_var($state, FILTER_VALIDATE_URL) ? $state : Storage::disk('public')->url($state))
                        : null, true)
                    ->placeholder('Нет фото'),
                TextColumn::make('image_verification')->label('Проверка фото')
                    ->state(fn ($record): ?string => ($record->primaryMedia ?: $record->media->firstWhere('type', 'image'))?->verification_status)
                    ->badge()->formatStateUsing(fn (?string $state): string => match ($state) {
                        'verified' => 'Vision', 'source_verified' => 'Подтверждено источником', 'manual' => 'Вручную', 'rejected' => 'Отклонено', 'hint_override' => 'Нарушает подсказку', default => 'Не проверено',
                    })->color(fn (?string $state): string => match ($state) {
                        'verified' => 'success', 'source_verified' => 'success', 'manual' => 'info', 'rejected' => 'danger', 'hint_override' => 'warning', default => 'warning',
                    })->toggleable(),
                TextColumn::make('id')->label('#')->sortable(),
                TextColumn::make('title')->label('Название')->searchable(['title', 'model', 'description'])->sortable()->wrap()->limit(70),
                TextColumn::make('brand.name')->label('Бренд')->searchable()->sortable(),
                TextColumn::make('model')->label('Модель')->searchable()->toggleable(),
                TextColumn::make('category.name')->label('Категория')->sortable(),
                TextColumn::make('product_type')->label('Тип')->badge()->formatStateUsing(fn (string $state): string => match ($state) {
                    'laptop' => 'Ноутбук', 'desktop' => 'Готовый ПК', 'component' => 'Комплектующая', default => 'Другая техника',
                })->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('variants_count')->label('Варианты')->counts('variants')->badge(),
                TextColumn::make('defaultVariant.price')->label('Цена')->numeric(decimalPlaces: 2)->placeholder('—'),
                TextColumn::make('defaultVariant.currency')->label('Валюта')->toggleable(),
                TextColumn::make('status')->label('Статус')->badge()->formatStateUsing(fn (string $state): string => match ($state) {
                    'published' => 'Опубликован', 'archived' => 'Архив', default => 'Черновик',
                })->color(fn (string $state): string => match ($state) {
                    'published' => 'success', 'archived' => 'gray', default => 'warning',
                }),
                ToggleColumn::make('is_active')->label('На сайте')->sortable(),
                ToggleColumn::make('is_featured')->label('Рекомендуемый')->sortable()->toggleable(),
                TextColumn::make('slug')->label('Slug')->copyable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('canonical_key')->label('Ключ дедупликации')->copyable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('confidence')->label('Увер. AI')->numeric(decimalPlaces: 2)->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('sort_order')->label('Порядок')->sortable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('published_at')->label('Публикация')->dateTime('d.m.Y H:i')->sortable(),
                TextColumn::make('created_at')->label('Создан')->dateTime('d.m.Y H:i')->sortable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')->label('Изменён')->since()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])->filters([
                SelectFilter::make('status')->label('Статус')->options([
                    'published' => 'Опубликован', 'draft' => 'Черновик', 'archived' => 'Архив',
                ]),
                SelectFilter::make('category_id')->label('Категория')->relationship('category', 'name'),
                SelectFilter::make('brand_id')->label('Бренд')->relationship('brand', 'name'),
                SelectFilter::make('product_type')->label('Тип')->options([
                    'laptop' => 'Ноутбук', 'desktop' => 'Готовый ПК', 'component' => 'Комплектующая', 'other' => 'Другая техника',
                ]),
                TernaryFilter::make('is_active')->label('Показывается на сайте'),
                TernaryFilter::make('is_featured')->label('Рекомендуемый'),
            ])->recordActions([ViewAction::make(), EditAction::make()])
            ->recordUrl(fn ($record): string => ProductResource::getUrl('view', ['record' => $record]));
    }
}
