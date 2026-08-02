<?php

namespace App\Filament\Resources\ProductGalleryRecipes\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ProductGalleryRecipeForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Сайт и состояние')
                ->description('Рецепт создаёт сильная AI-модель из очищенной DOM-структуры, а Playwright безопасно проверяет его до публикации.')
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
                    Toggle::make('source_blocked')
                        ->label('Полностью исключить источник')
                        ->helperText('Домен не попадёт ни в Web Search, ни в HTML/Playwright-обработку.'),
                    Textarea::make('source_block_reason')
                        ->label('Причина блокировки источника')
                        ->rows(3)
                        ->columnSpanFull(),
                    TextInput::make('success_count')->label('Успешных запусков')->disabled()->dehydrated(false),
                    TextInput::make('failure_count')->label('Ошибок')->disabled()->dehydrated(false),
                    Textarea::make('last_error')->label('Последняя ошибка')->rows(3)->disabled()->dehydrated(false)->columnSpanFull(),
                ])
                ->columns(2)
                ->columnSpanFull(),
            Section::make('Безопасный Playwright JSON-рецепт')
                ->description('Разрешены только CSS-селекторы, атрибуты изображений и жёстко ограниченные клики. Произвольный JavaScript не хранится и не запускается.')
                ->schema([
                    Textarea::make('recipe_text')
                        ->label('Полный ответ AI (JSON-рецепт)')
                        ->helperText('Точный структурированный ответ агента, который исполняет Playwright.')
                        ->rows(18)
                        ->disabled()
                        ->dehydrated(false)
                        ->columnSpanFull(),
                    Textarea::make('reason')
                        ->label('Объяснение AI')
                        ->rows(4)
                        ->disabled()
                        ->dehydrated(false)
                        ->columnSpanFull(),
                    TextInput::make('confidence')
                        ->label('Уверенность AI')
                        ->disabled()
                        ->dehydrated(false),
                    Toggle::make('gallery_present')
                        ->label('Галерея подтверждена')
                        ->disabled()
                        ->dehydrated(false),
                    TextInput::make('expected_image_count')
                        ->label('Ожидается фото')
                        ->disabled()
                        ->dehydrated(false),
                    Textarea::make('expected_count_evidence')
                        ->label('Почему ожидается это количество')
                        ->rows(3)
                        ->disabled()
                        ->dehydrated(false)
                        ->columnSpanFull(),
                    TagsInput::make('pre_click_selectors')
                        ->label('Кнопки перед сбором')
                        ->helperText('Только открытие галереи, cookies или Continue shopping.')
                        ->columnSpanFull(),
                    TagsInput::make('collect_selectors')
                        ->label('Сбор изображений')
                        ->helperText('Например: [data-old-hires]')
                        ->columnSpanFull(),
                    TagsInput::make('thumbnail_selectors')->label('Миниатюры слайдера')->columnSpanFull(),
                    TagsInput::make('open_selectors')->label('Открытие галереи')->columnSpanFull(),
                    TagsInput::make('next_selectors')->label('Следующее изображение')->columnSpanFull(),
                    TagsInput::make('attributes')
                        ->label('Атрибуты full-resolution URL')
                        ->helperText('Например: data-old-hires, data-zoom-image, href')
                        ->columnSpanFull(),
                    TextInput::make('max_thumbnail_clicks')->label('Кликов по миниатюрам')->numeric()->minValue(0)->maxValue(20),
                    TextInput::make('max_next_clicks')->label('Кликов Далее')->numeric()->minValue(0)->maxValue(15),
                    TextInput::make('wait_after_click_ms')->label('Ожидание после клика, мс')->numeric()->minValue(50)->maxValue(1000),
                ])
                ->columns(3)
                ->columnSpanFull(),
        ]);
    }
}
