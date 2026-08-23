<?php

namespace App\Filament\Resources\Products\Schemas;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ProductForm
{
    public static function configure(Schema $schema): Schema
    {
        // Without an explicit columns(1), EditRecord/CreateRecord default
        // this top-level schema to columns(2) and our single Grid below
        // only fills one of those tracks, halving the usable width.
        return $schema
            ->columns(1)
            ->components([
                Grid::make(3)
                    ->schema([
                        Group::make([
                            Section::make('Товар')->schema([
                                TextInput::make('title')->label('Название')->required()->columnSpanFull(),
                                TextInput::make('model')->label('Модель'),
                                Select::make('product_type')->label('Тип')->options([
                                    'laptop' => 'Ноутбук', 'desktop' => 'Готовый ПК', 'component' => 'Комплектующая', 'other' => 'Другая техника',
                                ])->required(),
                                Textarea::make('description')->label('Описание')->rows(6)->columnSpanFull(),
                            ])->columns(2),
                        ])->columnSpan(2),

                        Group::make([
                            Section::make('Публикация')->schema([
                                Select::make('status')->label('Статус')->options([
                                    'published' => 'Опубликован', 'draft' => 'Черновик', 'archived' => 'Архив',
                                ])->required(),
                                Toggle::make('is_active')->label('Показывать в каталоге')->default(true),
                                Toggle::make('is_featured')->label('Рекомендуемый'),
                                DateTimePicker::make('published_at')->label('Дата публикации'),
                                TextInput::make('sort_order')->label('Порядок')->numeric()->minValue(0),
                            ]),
                            Section::make('Категория и бренд')->schema([
                                Select::make('category_id')->label('Категория')->relationship('category', 'name')->searchable()->preload()->required(),
                                Select::make('brand_id')->label('Бренд')->relationship('brand', 'name')->searchable()->preload()
                                    ->createOptionForm([
                                        TextInput::make('name')->label('Название')->required(),
                                        TextInput::make('slug')->label('Slug')->required(),
                                        TextInput::make('country')->label('Страна'),
                                    ]),
                            ]),
                            Section::make('Идентификаторы')
                                ->schema([
                                    TextInput::make('slug')->label('Slug')->helperText('Можно оставить пустым — создастся автоматически.')->unique(ignoreRecord: true),
                                    TextInput::make('canonical_key')->label('Ключ дедупликации')->helperText('Можно оставить пустым.')->unique(ignoreRecord: true),
                                ])
                                ->collapsed(),
                        ])->columnSpan(1),
                    ]),

                Section::make('Конфигурации / SKU')
                    ->description('Цена, наличие, цвет и технические характеристики конкретной конфигурации.')
                    ->schema([
                        Repeater::make('variants')->relationship()->label('Варианты')->schema([
                            TextInput::make('name')->label('Название конфигурации')->columnSpan(2),
                            TextInput::make('sku')->label('SKU')->unique(ignoreRecord: true),
                            TextInput::make('mpn')->label('MPN'),
                            TextInput::make('gtin')->label('GTIN')->unique(ignoreRecord: true),
                            TextInput::make('color')->label('Цвет'),
                            Select::make('condition')->label('Состояние')->options([
                                'new' => 'Новый', 'used' => 'Б/у', 'refurbished' => 'Восстановленный',
                            ])->required(),
                            Select::make('stock_status')->label('Наличие')->options([
                                'unknown' => 'Уточнить', 'in_stock' => 'В наличии', 'out_of_stock' => 'Нет', 'preorder' => 'Предзаказ',
                            ])->required(),
                            TextInput::make('price')->label('Цена')->numeric()->minValue(0),
                            TextInput::make('compare_at_price')->label('Старая цена')->numeric()->minValue(0),
                            TextInput::make('currency')->label('Валюта')->default('CZK')->length(3),
                            TextInput::make('quantity')->label('Количество')->numeric()->minValue(0),
                            TextInput::make('warranty_months')->label('Гарантия, месяцев')->numeric()->minValue(0),
                            Toggle::make('is_default')->label('Основной вариант'),
                            Toggle::make('is_active')->label('Активен')->default(true),
                            Repeater::make('attributes')->relationship()->label('Характеристики')->schema([
                                Select::make('attribute_definition_id')->label('Характеристика')
                                    ->relationship('definition', 'label')->searchable()->preload()->required()->columnSpan(2),
                                TextInput::make('value')->label('Значение')->required(),
                                TextInput::make('numeric_value')->label('Число')->numeric(),
                                TextInput::make('unit')->label('Единица'),
                            ])->columns(5)->defaultItems(0)->columnSpanFull(),
                        ])->columns(4)->defaultItems(1)->columnSpanFull(),
                    ])
                    ->columnSpanFull(),

                Section::make('Источники')
                    ->schema([
                        Repeater::make('sources')->relationship()->label('Источники данных')->schema([
                            TextInput::make('title')->label('Название')->required(),
                            TextInput::make('url')->label('URL')->url()->required()->columnSpan(2),
                            TextInput::make('domain')->label('Домен'),
                            Select::make('source_type')->label('Тип')->options([
                                'manufacturer' => 'Производитель', 'retailer' => 'Магазин', 'review' => 'Обзор', 'web' => 'Сайт',
                            ])->default('web'),
                            TextInput::make('confidence')->label('Уверенность')->numeric()->minValue(0)->maxValue(1),
                        ])->columns(5)->defaultItems(0)->columnSpanFull(),
                    ])
                    ->columnSpanFull()
                    ->collapsed(),
            ]);
    }
}
