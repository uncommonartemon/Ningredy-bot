<?php

namespace App\Filament\Resources\Categories\Schemas;

use App\Models\Category;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class CategoryForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Категория')->schema([
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
                TextInput::make('icon')->label('Иконка'),
                TextInput::make('sort_order')->label('Порядок')->numeric()->minValue(0)->default(0),
                Select::make('gallery_search_strategy')
                    ->label('Поиск фотографий')
                    ->options([
                        Category::GALLERY_SEARCH_AUTO => 'Автоматически (текущий общий режим)',
                        Category::GALLERY_SEARCH_VISION_FIRST => 'Vision-first',
                        Category::GALLERY_SEARCH_PLAYWRIGHT_FIRST => 'Playwright-first',
                    ])
                    ->helperText('Vision-first использует статичные фото и готовые рецепты домена, но не обучает новый рецепт. Playwright-first сначала раскрывает галерею браузером.')
                    ->default(Category::GALLERY_SEARCH_AUTO)
                    ->required(),
                TextInput::make('minimum_verified_images')
                    ->label('Минимум проверенных фото')
                    ->helperText('Успех при этом количестве; если источник отдаёт больше, галерея заполняется до общего лимита.')
                    ->numeric()
                    ->minValue(1)
                    ->maxValue(10)
                    ->default(3)
                    ->required(),
                Textarea::make('product_search_hint')
                    ->label('Подсказка агенту при поиске')
                    ->helperText('Например: «не используй фото товара в коробке». Агент видит правило после определения категории.')
                    ->rows(3)
                    ->maxLength(2000)
                    ->columnSpanFull(),
                Toggle::make('is_active')->label('Активна')->default(true),
            ])->columns(3)->columnSpanFull(),
            Section::make('Переводы')->schema([
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
            ])->columnSpanFull(),
        ]);
    }
}
