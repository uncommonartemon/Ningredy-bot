<?php

namespace App\Filament\Resources\ProductGalleryRecipes\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ProductGalleryRecipeForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Сайт и состояние')
                ->description('Рецепт создаётся автоматически после успешного прохода Playwright.')
                ->schema([
                    TextInput::make('domain')
                        ->label('Домен')
                        ->required()
                        ->unique(ignoreRecord: true)
                        ->maxLength(255),
                    TextInput::make('path_pattern')
                        ->label('Шаблон пути')
                        ->helperText('* означает все товарные страницы домена.')
                        ->required()
                        ->maxLength(255),
                    Select::make('status')
                        ->label('Состояние')
                        ->options([
                            'active' => 'Активен',
                            'learning' => 'Обучается',
                            'disabled' => 'Отключён',
                        ])
                        ->required()
                        ->native(false),
                    TextInput::make('success_count')
                        ->label('Успешных запусков')
                        ->disabled()
                        ->dehydrated(false),
                    TextInput::make('failure_count')
                        ->label('Ошибок')
                        ->disabled()
                        ->dehydrated(false),
                    Textarea::make('last_error')
                        ->label('Последняя ошибка')
                        ->rows(3)
                        ->disabled()
                        ->dehydrated(false)
                        ->columnSpanFull(),
                ])
                ->columns(2)
                ->columnSpanFull(),
            Section::make('Безопасные Playwright-селекторы')
                ->description('Исполняются только CSS-селекторы. Произвольный JavaScript здесь не хранится и не запускается.')
                ->schema([
                    TagsInput::make('collect_selectors')
                        ->label('Сбор изображений')
                        ->helperText('Например: [data-old-hires]')
                        ->columnSpanFull(),
                    TagsInput::make('thumbnail_selectors')
                        ->label('Миниатюры слайдера')
                        ->columnSpanFull(),
                    TagsInput::make('open_selectors')
                        ->label('Открытие галереи')
                        ->columnSpanFull(),
                    TagsInput::make('next_selectors')
                        ->label('Следующее изображение')
                        ->columnSpanFull(),
                ])
                ->columnSpanFull(),
        ]);
    }
}
