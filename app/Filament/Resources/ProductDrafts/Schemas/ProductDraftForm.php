<?php

namespace App\Filament\Resources\ProductDrafts\Schemas;

use App\Filament\Schemas\ProductTranslations;
use App\Services\Catalog\AttributePresentation;
use App\Services\Products\ProductCondition;
use App\Services\Products\ProductSourceTypes;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\Collection;

class ProductDraftForm
{
    public static function configure(Schema $schema): Schema
    {
        // Without an explicit columns(1), EditRecord/CreateRecord default
        // this top-level schema to columns(2) and our sections below only
        // fill one of those tracks, halving the usable width.
        return $schema->dense()
            ->columns(1)
            ->components([
                Section::make(__('Результат AI'))
                    ->schema([
                        TextInput::make('title')->label(__('Название'))->required()->columnSpanFull(),
                        TextInput::make('brand')->label(__('Бренд')),
                        TextInput::make('model')->label(__('Модель')),
                        TextInput::make('color')->label(__('Цвет')),
                        Select::make('condition')->label(__('Состояние'))->options(ProductCondition::options())->default('new')->required(),
                        Select::make('product_type')->label(__('Тип товара'))->options([
                            'laptop' => __('Ноутбук'), 'desktop' => __('Готовый ПК'), 'component' => __('Комплектующая'), 'other' => __('Другая техника'),
                        ]),
                        TextInput::make('category')->label(__('Категория (текст исследователя)')),
                        TextInput::make('confidence')->label(__('Уверенность AI'))->numeric()->minValue(0)->maxValue(1),
                        Textarea::make('description')
                            ->label(__('Публичное описание'))
                            ->helperText(__('Только текст для каталога, без рассуждений поиска.'))
                            ->rows(7)
                            ->columnSpanFull(),
                        Textarea::make('research_notes')
                            ->label(__('Внутренние заметки исследования'))
                            ->helperText(__('Не публикуются на сайте.'))
                            ->rows(5)
                            ->columnSpanFull(),
                        TextEntry::make('attribute_group_review')
                            ->label(__('catalog-groups.groups'))
                            ->state(fn (Get $get): array => AttributePresentation::specifications(
                                array_values($get('specifications') ?? []),
                            )->groupBy(fn (array $specification): string => (string) ($specification['group']['id'] ?? 'ungrouped'))
                                ->map(fn (Collection $specifications): string => ($specifications->first()['group']['name'] ?? __('catalog-groups.ungrouped'))
                                    .': '.$specifications->pluck('name')->implode(', '))
                                ->values()->all())
                            ->listWithLineBreaks()
                            ->placeholder(__('catalog-groups.ungrouped'))
                            ->dehydrated(false)
                            ->columnSpanFull(),
                        Repeater::make('specifications')
                            ->label(__('Характеристики'))
                            ->schema([
                                TextInput::make('name')->label(__('Название'))->required(),
                                TextInput::make('value')->label(__('Значение'))->required(),
                            ])->columns(2)->defaultItems(0)->columnSpanFull(),
                    ])->columns(4)->columnSpanFull(),
                Section::make(__('Переводы'))->schema([ProductTranslations::make()])->collapsed(),
                Section::make(__('Источники'))
                    ->schema([
                        TextInput::make('primary_source_url')->label(__('Основной источник'))->url()->columnSpanFull(),
                        TextInput::make('official_source_url')->label(__('Официальный источник'))->url()->columnSpanFull(),
                        Repeater::make('sources')
                            ->label(__('Все источники'))
                            ->schema([
                                TextInput::make('title')->label(__('Название'))->required(),
                                TextInput::make('url')->label('URL')->url()->required(),
                                Select::make('type')->label(__('Тип'))->options(ProductSourceTypes::options())->default('web'),
                            ])->columns(3)->defaultItems(0)->columnSpanFull(),
                        TagsInput::make('image_urls')->label(__('Исходные ссылки на изображения'))
                            ->hintIcon('heroicon-o-question-mark-circle', tooltip: __('Используются поиском как кандидаты. Сохранённые фотографии находятся в таблице «Галерея»; ссылки не заменяют проверенные файлы.'))
                            ->columnSpanFull(),
                    ])->columns(2)->columnSpanFull(),
                Section::make(__('Состояние поиска фото'))
                    ->description(__('Технические поля поиска — только для чтения.'))
                    ->schema([
                        TextInput::make('gallery_status')->label(__('Статус галереи'))->disabled(),
                        TextInput::make('gallery_search_stop_reason')->label(__('Причина остановки'))->disabled(),
                        TextInput::make('images_staged_at')->label(__('Фото сохранены'))->disabled(),
                        Textarea::make('gallery_notes')->label(__('Заметка галереи'))->rows(2)->disabled()->columnSpanFull(),
                    ])->columns(3)->columnSpanFull(),
                Section::make(__('Проверка'))
                    ->schema([
                        TextInput::make('status')->label(__('Статус'))->disabled(),
                        TextInput::make('reviewed_at')->label(__('Проверен'))->disabled(),
                        TextInput::make('reviewer.name')->label(__('Администратор'))->disabled(),
                        TextInput::make('reviewed_by_telegram_user_id')->label('Telegram reviewer')->disabled(),
                        Textarea::make('rejection_reason')->label(__('Причина отклонения'))->rows(3)->columnSpanFull(),
                    ])->columns(4)->columnSpanFull(),
            ]);
    }
}
