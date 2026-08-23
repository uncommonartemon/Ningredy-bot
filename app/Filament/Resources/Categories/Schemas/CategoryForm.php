<?php

namespace App\Filament\Resources\Categories\Schemas;

use App\Models\Category;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class CategoryForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            // EditRecord::defaultForm()/CreateRecord's equivalent fall back
            // to columns(2) on this top-level schema whenever it never calls
            // ->columns() itself - our single Grid below then only fills one
            // of those two tracks, halving the usable width for no reason.
            ->columns(1)
            ->components([
                Grid::make(3)
                    ->schema([
                        Group::make([
                            Section::make('Поиск фотографий')
                                ->schema([
                                    ToggleButtons::make('gallery_search_strategy')
                                        ->label('Стратегия')
                                        ->options([
                                            Category::GALLERY_SEARCH_AUTO => 'Авто',
                                            Category::GALLERY_SEARCH_VISION_FIRST => 'Vision-first',
                                            Category::GALLERY_SEARCH_PLAYWRIGHT_FIRST => 'Playwright-first',
                                        ])
                                        ->icons([
                                            Category::GALLERY_SEARCH_AUTO => 'heroicon-o-bolt',
                                            Category::GALLERY_SEARCH_VISION_FIRST => 'heroicon-o-eye',
                                            Category::GALLERY_SEARCH_PLAYWRIGHT_FIRST => 'heroicon-o-cursor-arrow-rays',
                                        ])
                                        ->tooltips([
                                            Category::GALLERY_SEARCH_AUTO => 'Стандартный сценарий: Playwright обучается, когда статики не хватает.',
                                            Category::GALLERY_SEARCH_VISION_FIRST => 'Только статичные фото и уже готовый рецепт домена — новый рецепт не обучается.',
                                            Category::GALLERY_SEARCH_PLAYWRIGHT_FIRST => 'Галерея сразу раскрывается браузером.',
                                        ])
                                        ->default(Category::GALLERY_SEARCH_AUTO)
                                        ->inline()
                                        ->required()
                                        ->columnSpanFull(),
                                    TextInput::make('minimum_verified_images')
                                        ->label('Минимум проверенных фото')
                                        ->helperText('Успех при этом количестве; если источник отдаёт больше, галерея заполняется до общего лимита 10.')
                                        ->numeric()
                                        ->minValue(1)
                                        ->maxValue(10)
                                        ->default(3)
                                        ->required()
                                        ->columnSpanFull(),
                                    Textarea::make('product_search_hint')
                                        ->label('Подсказка агенту при поиске')
                                        ->helperText('Например: «не используй фото товара в коробке». Агент видит правило после определения категории.')
                                        ->rows(3)
                                        ->maxLength(2000)
                                        ->columnSpanFull(),
                                    TextInput::make('minimum_image_width')
                                        ->label('Мин. ширина фото, px')
                                        ->helperText('Пусто = глобальное значение из настроек AI.')
                                        ->numeric()
                                        ->minValue(100)
                                        ->maxValue(4000)
                                        ->suffix('px'),
                                    TextInput::make('minimum_image_height')
                                        ->label('Мин. высота фото, px')
                                        ->helperText('Пусто = глобальное значение (по умолчанию — любая).')
                                        ->numeric()
                                        ->minValue(0)
                                        ->maxValue(4000)
                                        ->suffix('px'),
                                ])
                                ->columns(2),
                            Section::make('Переводы')
                                ->schema([
                                    Repeater::make('translations')
                                        ->relationship()
                                        ->label('Названия категории')
                                        ->schema([
                                            Select::make('locale')->label('Язык')->options([
                                                'en' => 'English',
                                                'cs' => 'Čeština',
                                                'uk' => 'Українська',
                                            ])->disableOptionsWhenSelectedInSiblingRepeaterItems()->required(),
                                            TextInput::make('name')->label('Название')->required(),
                                        ])
                                        ->columns(2)
                                        ->defaultItems(0)
                                        ->addActionLabel('Добавить перевод')
                                        ->columnSpanFull(),
                                ]),
                        ])->columnSpan(2),
                        Group::make([
                            Section::make('Категория')
                                ->schema([
                                    TextInput::make('name')
                                        ->label('Название на английском')
                                        ->helperText('Каноническое имя и запасной вариант для API.')
                                        ->required(),
                                    TextInput::make('slug')
                                        ->label('Slug')
                                        ->helperText('Стабильное английское значение для URL и фильтров.')
                                        ->unique(ignoreRecord: true)
                                        ->required(),
                                    Select::make('parent_id')
                                        ->label('Родительская категория')
                                        ->relationship('parent', 'name')
                                        ->searchable()
                                        ->preload(),
                                    Toggle::make('is_active')->label('Активна')->default(true),
                                    TextInput::make('sort_order')->label('Порядок')->numeric()->minValue(0)->default(0),
                                ]),
                        ])->columnSpan(1),
                    ]),
            ]);
    }
}
