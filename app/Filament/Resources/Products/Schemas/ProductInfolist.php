<?php

namespace App\Filament\Resources\Products\Schemas;

use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Storage;

class ProductInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Карточка товара')->schema([
                ImageEntry::make('gallery')->label('Фотографии')
                    ->state(fn ($record): array => $record->media->where('type', 'image')
                        ->map(fn ($media): ?string => $media->path ?: $media->url)->filter()->values()->all())
                    ->disk('public')
                    ->imageHeight(220)
                    ->wrap()
                    ->limit(8)
                    ->limitedRemainingText(isSeparate: true)
                    ->url(fn (?string $state): ?string => filled($state)
                        ? (filter_var($state, FILTER_VALIDATE_URL) ? $state : Storage::disk('public')->url($state))
                        : null, true)
                    ->placeholder('У товара пока нет фотографий')
                    ->columnSpanFull(),
                TextEntry::make('title')->label('Название')->columnSpan(2),
                TextEntry::make('brand.name')->label('Бренд')->placeholder('—'),
                TextEntry::make('model')->label('Модель')->placeholder('—'),
                TextEntry::make('category.name')->label('Категория'),
                TextEntry::make('product_type')->label('Тип')->badge(),
                TextEntry::make('status')->label('Статус')->badge(),
                IconEntry::make('is_active')->label('Показывается на сайте')->boolean(),
                IconEntry::make('is_featured')->label('Рекомендуемый')->boolean(),
                TextEntry::make('description')->label('Описание')->columnSpanFull()->placeholder('—'),
            ])->columns(4)->columnSpanFull(),

            Section::make('Конфигурации')->schema([
                RepeatableEntry::make('variants')->label('Варианты')->schema([
                    TextEntry::make('name')->label('Название')->placeholder('Основной вариант'),
                    TextEntry::make('sku')->label('SKU')->placeholder('—')->copyable(),
                    TextEntry::make('color')->label('Цвет')->placeholder('—'),
                    TextEntry::make('price')->label('Цена')->money(fn ($record) => $record->currency ?: 'CZK')->placeholder('—'),
                    TextEntry::make('stock_status')->label('Наличие')->badge(),
                    IconEntry::make('is_default')->label('Основной')->boolean(),
                ])->columns(3)->columnSpanFull(),
            ])->columnSpanFull(),

            Section::make('Служебные данные')->schema([
                TextEntry::make('canonical_key')->label('Ключ дедупликации')->copyable(),
                TextEntry::make('confidence')->label('Уверенность AI')->numeric(4),
                TextEntry::make('sources_count')->label('Источников')->state(fn ($record) => $record->sources()->count())->badge(),
                TextEntry::make('published_at')->label('Опубликован')->dateTime()->placeholder('—'),
                TextEntry::make('created_at')->label('Создан')->dateTime(),
                TextEntry::make('updated_at')->label('Изменён')->dateTime(),
            ])->columns(3)->collapsed()->columnSpanFull(),
        ]);
    }
}
