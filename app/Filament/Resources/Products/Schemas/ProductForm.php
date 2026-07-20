<?php

namespace App\Filament\Resources\Products\Schemas;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class ProductForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Товар')->schema([
                TextInput::make('title')->label('Название')->required()->columnSpanFull(),
                TextInput::make('slug')->label('Slug')->helperText('Можно оставить пустым — создастся автоматически.')->unique(ignoreRecord: true),
                TextInput::make('canonical_key')->label('Ключ дедупликации')->helperText('Можно оставить пустым.')->unique(ignoreRecord: true),
                Select::make('category_id')->label('Категория')->relationship('category', 'name')->searchable()->preload()->required(),
                Select::make('brand_id')->label('Бренд')->relationship('brand', 'name')->searchable()->preload()
                    ->createOptionForm([
                        TextInput::make('name')->label('Название')->required(),
                        TextInput::make('slug')->label('Slug')->required(),
                        TextInput::make('country')->label('Страна'),
                    ]),
                Select::make('product_type')->label('Тип')->options([
                    'laptop' => 'Ноутбук', 'desktop' => 'Готовый ПК', 'component' => 'Комплектующая', 'other' => 'Другая техника',
                ])->required(),
                Select::make('status')->label('Статус')->options([
                    'published' => 'Опубликован', 'draft' => 'Черновик', 'archived' => 'Архив',
                ])->required(),
                TextInput::make('model')->label('Модель'),
                Textarea::make('description')->label('Описание')->rows(5)->columnSpanFull(),
                Toggle::make('is_active')->label('Показывать в каталоге')->default(true),
                Toggle::make('is_featured')->label('Рекомендуемый'),
                TextInput::make('sort_order')->label('Порядок')->numeric()->minValue(0),
                DateTimePicker::make('published_at')->label('Дата публикации'),
            ])->columns(4)->columnSpanFull(),

            Section::make('Конфигурации / SKU')->description('Цена, наличие, цвет и технические характеристики конкретной конфигурации.')
                ->schema([
                    Repeater::make('variants')->relationship()->label('Варианты')->schema([
                        TextInput::make('name')->label('Название конфигурации'),
                        TextInput::make('sku')->label('SKU')->unique(ignoreRecord: true),
                        TextInput::make('mpn')->label('MPN'),
                        TextInput::make('gtin')->label('GTIN')->unique(ignoreRecord: true),
                        TextInput::make('color')->label('Цвет'),
                        Select::make('condition')->label('Состояние')->options([
                            'new' => 'Новый', 'used' => 'Б/у', 'refurbished' => 'Восстановленный',
                        ])->required(),
                        TextInput::make('price')->label('Цена')->numeric()->minValue(0),
                        TextInput::make('compare_at_price')->label('Старая цена')->numeric()->minValue(0),
                        TextInput::make('currency')->label('Валюта')->default('CZK')->length(3),
                        Select::make('stock_status')->label('Наличие')->options([
                            'unknown' => 'Уточнить', 'in_stock' => 'В наличии', 'out_of_stock' => 'Нет', 'preorder' => 'Предзаказ',
                        ])->required(),
                        TextInput::make('quantity')->label('Количество')->numeric()->minValue(0),
                        TextInput::make('warranty_months')->label('Гарантия, месяцев')->numeric()->minValue(0),
                        Toggle::make('is_default')->label('Основной вариант'),
                        Toggle::make('is_active')->label('Активен')->default(true),
                        Repeater::make('attributes')->relationship()->label('Характеристики')->schema([
                            Select::make('attribute_definition_id')->label('Характеристика')
                                ->relationship('definition', 'label')->searchable()->preload()->required(),
                            TextInput::make('value')->label('Значение')->required(),
                            TextInput::make('numeric_value')->label('Число')->numeric(),
                            TextInput::make('unit')->label('Единица'),
                        ])->columns(4)->defaultItems(0)->columnSpanFull(),
                    ])->columns(4)->defaultItems(1)->columnSpanFull(),
                ])->columnSpanFull(),

            Section::make('Медиа')->schema([
                Repeater::make('media')->relationship()->label('Изображения и видео')->schema([
                    Select::make('type')->label('Тип')->options(['image' => 'Изображение', 'video' => 'Видео'])->required(),
                    FileUpload::make('path')->label('Локальный файл')->disk('public')->directory('products/uploads')
                        ->image()->imageEditor()->openable()->downloadable()->previewable()
                        ->required(fn (Get $get): bool => blank($get('url')))->columnSpan(2),
                    TextInput::make('url')->label('Внешний URL')->url()
                        ->required(fn (Get $get): bool => blank($get('path')))->columnSpan(2),
                    TextInput::make('alt')->label('Alt'),
                    TextInput::make('source_url')->label('Источник')->url()->columnSpan(2),
                    Select::make('role')->label('Назначение')->options([
                        'primary' => 'Главное', 'secondary' => 'Дополнительное', 'detail' => 'Деталь',
                    ]),
                    Select::make('verification_status')->label('Проверка изображения')->options([
                        'verified' => 'Проверено Vision', 'manual' => 'Подтверждено вручную',
                        'unverified' => 'Не проверено', 'rejected' => 'Отклонено',
                    ])->default('manual')->required(),
                    TextInput::make('verification_score')->label('Оценка Vision')->numeric()->minValue(0)->maxValue(1),
                    TextInput::make('verification_model')->label('Модель проверки'),
                    Textarea::make('verification_notes')->label('Комментарий проверки')->rows(2)->columnSpan(2),
                    DateTimePicker::make('verified_at')->label('Проверено в'),
                    TextInput::make('sort_order')->label('Порядок')->numeric()->default(0),
                    Toggle::make('is_primary')->label('Главное'),
                ])->columns(4)->defaultItems(0)->columnSpanFull(),
            ])->columnSpanFull(),

            Section::make('Источники')->schema([
                Repeater::make('sources')->relationship()->label('Источники данных')->schema([
                    TextInput::make('title')->label('Название')->required(),
                    TextInput::make('url')->label('URL')->url()->required()->columnSpan(2),
                    TextInput::make('domain')->label('Домен'),
                    Select::make('source_type')->label('Тип')->options([
                        'manufacturer' => 'Производитель', 'retailer' => 'Магазин', 'review' => 'Обзор', 'web' => 'Сайт',
                    ])->default('web'),
                    TextInput::make('confidence')->label('Уверенность')->numeric()->minValue(0)->maxValue(1),
                ])->columns(4)->defaultItems(0)->columnSpanFull(),
            ])->columnSpanFull(),
        ]);
    }
}
