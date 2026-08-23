<?php

namespace App\Filament\Resources\ProductDrafts\Schemas;

use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ProductDraftForm
{
    public static function configure(Schema $schema): Schema
    {
        // Without an explicit columns(1), EditRecord/CreateRecord default
        // this top-level schema to columns(2) and our sections below only
        // fill one of those tracks, halving the usable width.
        return $schema
            ->columns(1)
            ->components([
                Section::make('Результат AI')
                    ->schema([
                        TextInput::make('title')->label('Название')->required()->columnSpanFull(),
                        TextInput::make('brand')->label('Бренд'),
                        TextInput::make('model')->label('Модель'),
                        TextInput::make('color')->label('Цвет'),
                        TextInput::make('product_type')->label('Тип товара'),
                        TextInput::make('category')->label('Категория (текст исследователя)'),
                        TextInput::make('confidence')->label('Уверенность AI')->numeric()->minValue(0)->maxValue(1),
                        Textarea::make('description')
                            ->label('Публичное описание')
                            ->helperText('Только текст для каталога, без рассуждений поиска.')
                            ->rows(7)
                            ->columnSpanFull(),
                        Textarea::make('research_notes')
                            ->label('Внутренние заметки исследования')
                            ->helperText('Не публикуются на сайте.')
                            ->rows(5)
                            ->columnSpanFull(),
                        Repeater::make('specifications')
                            ->label('Характеристики')
                            ->schema([
                                TextInput::make('name')->label('Название')->required(),
                                TextInput::make('value')->label('Значение')->required(),
                            ])->columns(2)->defaultItems(0)->columnSpanFull(),
                    ])->columns(4)->columnSpanFull(),
                Section::make('Источники')
                    ->schema([
                        TextInput::make('primary_source_url')->label('Основной источник')->url()->columnSpanFull(),
                        TextInput::make('official_source_url')->label('Официальный источник')->url()->columnSpanFull(),
                        Repeater::make('sources')
                            ->label('Все источники')
                            ->schema([
                                TextInput::make('title')->label('Название')->required(),
                                TextInput::make('url')->label('URL')->url()->required(),
                                Select::make('type')->label('Тип')->options([
                                    'manufacturer' => 'Производитель',
                                    'retailer' => 'Магазин',
                                    'marketplace' => 'Маркетплейс',
                                    'review' => 'Обзор',
                                    'database' => 'База товаров',
                                    'web' => 'Другой сайт',
                                ])->default('web'),
                            ])->columns(3)->defaultItems(0)->columnSpanFull(),
                        TagsInput::make('image_urls')->label('Ссылки на изображения (устаревшее поле)')->columnSpanFull(),
                    ])->columns(2)->columnSpanFull(),
                Section::make('Состояние поиска фото')
                    ->description('Технические поля поиска — только для чтения.')
                    ->schema([
                        TextInput::make('gallery_status')->label('Статус галереи')->disabled(),
                        TextInput::make('gallery_search_stop_reason')->label('Причина остановки')->disabled(),
                        TextInput::make('images_staged_at')->label('Фото сохранены')->disabled(),
                        Textarea::make('gallery_notes')->label('Заметка галереи')->rows(2)->disabled()->columnSpanFull(),
                    ])->columns(3)->columnSpanFull(),
                Section::make('Проверка')
                    ->schema([
                        TextInput::make('status')->label('Статус')->disabled(),
                        TextInput::make('reviewed_at')->label('Проверен')->disabled(),
                        TextInput::make('reviewer.name')->label('Администратор')->disabled(),
                        TextInput::make('reviewed_by_telegram_user_id')->label('Telegram reviewer')->disabled(),
                        Textarea::make('rejection_reason')->label('Причина отклонения')->rows(3)->columnSpanFull(),
                    ])->columns(4)->columnSpanFull(),
            ]);
    }
}
