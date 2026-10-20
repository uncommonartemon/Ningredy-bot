<?php

namespace App\Filament\Resources\ProductDrafts\Schemas;

use App\Filament\Schemas\ProductTranslations;
use App\Services\Catalog\AttributePresentation;
use App\Services\Products\ProductCondition;
use App\Services\Products\ProductSourceTypes;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\Collection;

class ProductDraftForm
{
    public static function configure(Schema $schema): Schema
    {
        // Without an explicit columns(1), EditRecord/CreateRecord default
        // this top-level schema to columns(2), halving the usable width.
        // The layout follows the product page: the product on the left,
        // review and search state on the right, the longer parts below.
        return $schema->dense()
            ->columns(1)
            ->components([
                Grid::make(['default' => 1, 'lg' => 3])->schema([
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
                        ])
                        ->gridContainer()->columns(['default' => 1, '@md' => 2, '@xl' => 3])
                        ->columnSpan(['default' => 1, 'lg' => 2]),
                    Group::make([
                        Section::make(__('Проверка'))
                            ->schema([
                                TextInput::make('status')->label(__('Статус'))->disabled(),
                                TextInput::make('confidence')->label(__('Уверенность AI'))->numeric()->minValue(0)->maxValue(1),
                                TextInput::make('reviewed_at')->label(__('Проверен'))->disabled(),
                                TextInput::make('reviewer.name')->label(__('Администратор'))->disabled(),
                                TextInput::make('reviewed_by_telegram_user_id')->label('Telegram reviewer')->disabled(),
                                Textarea::make('rejection_reason')->label(__('Причина отклонения'))->rows(2)->autosize()->columnSpanFull(),
                            ])->columns(2),
                        Section::make(__('Состояние поиска фото'))
                            ->description(__('Технические поля поиска — только для чтения.'))
                            ->schema([
                                TextInput::make('gallery_status')->label(__('Статус галереи'))->disabled(),
                                TextInput::make('gallery_search_stop_reason')->label(__('Причина остановки'))->disabled(),
                                TextInput::make('images_staged_at')->label(__('Фото сохранены'))->disabled()->columnSpanFull(),
                                Textarea::make('gallery_notes')->label(__('Заметка галереи'))->rows(2)->autosize()->disabled()->columnSpanFull(),
                            ])->columns(2)->collapsed(),
                    ])->columnSpan(1),
                    Section::make(__('Характеристики'))
                        ->schema([
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
                                ->dehydrated(false),
                            Repeater::make('specifications')
                                ->label(__('Характеристики'))
                                ->hiddenLabel()
                                ->table([
                                    TableColumn::make(__('Название'))->width('35%'),
                                    TableColumn::make(__('Значение')),
                                ])
                                ->compact()
                                ->schema([
                                    TextInput::make('name')->label(__('Название'))->required(),
                                    TextInput::make('value')->label(__('Значение'))->required(),
                                ])->defaultItems(0),
                        ])
                        ->columnSpanFull(),
                    Section::make(__('Описание'))
                        ->schema([
                            Textarea::make('description')
                                ->label(__('Публичное описание'))
                                ->hintIcon('heroicon-o-question-mark-circle', tooltip: __('Только текст для каталога, без рассуждений поиска.'))
                                ->rows(4)->autosize()
                                ->columnSpanFull(),
                            Textarea::make('research_notes')
                                ->label(__('Внутренние заметки исследования'))
                                ->hintIcon('heroicon-o-question-mark-circle', tooltip: __('Не публикуются на сайте.'))
                                ->rows(3)->autosize()
                                ->columnSpanFull(),
                        ])
                        ->columnSpan(['default' => 1, 'lg' => 2]),
                    Section::make(__('Переводы'))->schema([ProductTranslations::make()])->collapsed(),
                    Section::make(__('Источники'))
                        ->schema([
                            TextInput::make('primary_source_url')->label(__('Основной источник'))->url(),
                            TextInput::make('official_source_url')->label(__('Официальный источник'))->url(),
                            Repeater::make('sources')
                                ->label(__('Все источники'))
                                ->table([
                                    TableColumn::make(__('Название'))->width('30%'),
                                    TableColumn::make('URL'),
                                    TableColumn::make(__('Тип'))->width('8rem'),
                                ])
                                ->compact()
                                ->schema([
                                    TextInput::make('title')->label(__('Название'))->required(),
                                    TextInput::make('url')->label('URL')->url()->required(),
                                    Select::make('type')->label(__('Тип'))->options(ProductSourceTypes::options())->default('web'),
                                ])->defaultItems(0)->columnSpanFull(),
                            TagsInput::make('image_urls')->label(__('Исходные ссылки на изображения'))
                                ->hintIcon('heroicon-o-question-mark-circle', tooltip: __('Используются поиском как кандидаты. Сохранённые фотографии находятся в таблице «Галерея»; ссылки не заменяют проверенные файлы.'))
                                ->columnSpanFull(),
                        ])
                        ->columns(2)->collapsed()->columnSpanFull(),
                ]),
            ]);
    }
}
