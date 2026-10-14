<?php

namespace App\Filament\Resources\Products\Schemas;

use App\Filament\Support\ProductOptions;
use App\Models\ProductVariant;
use App\Services\Catalog\AttributePresentation;
use App\Services\Products\ProductCondition;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Illuminate\Support\Facades\Storage;

class ProductInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Карточка товара'))->schema([
                ImageEntry::make('gallery')->label(__('Фотографии'))
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
                    ->placeholder(__('У товара пока нет фотографий'))
                    ->columnSpanFull(),
                TextEntry::make('title')->label(__('Название'))->columnSpan(2),
                TextEntry::make('brand.name')->label(__('Бренд'))->placeholder('—'),
                TextEntry::make('model')->label(__('Модель'))->placeholder('—'),
                TextEntry::make('category.name')->label(__('Категория')),
                TextEntry::make('product_type')->label(__('Тип'))->badge()
                    ->formatStateUsing(fn ($state) => ProductOptions::types()[$state] ?? $state),
                TextEntry::make('status')->label(__('Статус'))->badge()
                    ->formatStateUsing(fn ($state) => ProductOptions::statuses()[$state] ?? $state),
                IconEntry::make('is_active')->label(__('Показывается на сайте'))->boolean(),
                IconEntry::make('is_featured')->label(__('Рекомендуемый'))->boolean(),
                TextEntry::make('description')->label(__('Описание'))->columnSpanFull()->placeholder('—'),
            ])->columns(4)->columnSpanFull(),

            Section::make(__('Конфигурации'))->schema([
                RepeatableEntry::make('variants')->label(__('Варианты'))->schema([
                    TextEntry::make('name')->label(__('Название'))->placeholder(__('Основной вариант')),
                    TextEntry::make('sku')->label('SKU')->placeholder('—')->copyable(),
                    TextEntry::make('mpn')->label('MPN')->placeholder('—')->copyable(),
                    TextEntry::make('gtin')->label('GTIN')->placeholder('—')->copyable(),
                    TextEntry::make('color')->label(__('Цвет'))->placeholder('—'),
                    TextEntry::make('condition')->label(__('Состояние'))->formatStateUsing(fn ($state) => ProductCondition::options()[$state] ?? $state),
                    TextEntry::make('price')->label(__('Цена'))->money(fn ($record) => $record->currency ?: 'CZK')->placeholder('—'),
                    TextEntry::make('stock_status')->label(__('Наличие'))->badge()
                        ->formatStateUsing(fn ($state) => ProductOptions::stockStatuses()[$state] ?? $state),
                    IconEntry::make('is_default')->label(__('Основной'))->boolean(),
                    RepeatableEntry::make('attribute_groups')->label(__('Характеристики'))
                        ->state(fn (ProductVariant $record): array => self::attributeGroups($record))
                        ->schema([
                            TextEntry::make('name')->hiddenLabel()->weight(FontWeight::SemiBold),
                            RepeatableEntry::make('attributes')->hiddenLabel()->schema([
                                TextEntry::make('label')->label(__('Название'))->placeholder('—'),
                                TextEntry::make('value')->label(__('Значение'))->placeholder('—'),
                                TextEntry::make('unit')->label(__('Единица'))->placeholder('—'),
                            ])->columns(3)->columnSpanFull(),
                        ])->columnSpanFull(),
                ])->columns(4)->columnSpanFull(),
            ])->columnSpanFull(),

            Section::make(__('Служебные данные'))->schema([
                TextEntry::make('canonical_key')->label(__('Ключ дедупликации'))->copyable(),
                TextEntry::make('confidence')->label(__('Уверенность AI'))->numeric(4),
                TextEntry::make('sources_count')->label(__('Источников'))->state(fn ($record) => $record->sources()->count())->badge(),
                TextEntry::make('published_at')->label(__('Опубликован'))->dateTime()->placeholder('—'),
                TextEntry::make('created_at')->label(__('Создан'))->dateTime(),
                TextEntry::make('updated_at')->label(__('Изменён'))->dateTime(),
            ])->columns(3)->collapsed()->columnSpanFull(),
        ]);
    }

    private static function attributeGroups(ProductVariant $variant): array
    {
        $variant->loadMissing(['attributes.definition.group', 'product.attributes.definition.group']);
        $attributes = $variant->product->attributes->whereNull('product_variant_id')
            ->concat($variant->attributes)->keyBy('attribute_definition_id')->values();

        return collect(AttributePresentation::values($attributes))
            ->groupBy(fn (array $attribute): string => (string) ($attribute['group']['id'] ?? 'ungrouped'))
            ->map(function ($attributes): array {
                $group = $attributes->first()['group'];
                $translation = $group['translations'][app()->getLocale()] ?? null;

                return [
                    'name' => filled($translation) ? $translation : ($group['name'] ?? __('catalog-groups.ungrouped')),
                    'attributes' => $attributes->values()->all(),
                ];
            })->values()->all();
    }
}
