<?php

namespace App\Filament\Resources\ProductGalleryRecipes\Schemas;

use Filament\Forms\Components\Select;
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
                ->description('Диагностика, статистика и AI-рецепт доступны на странице просмотра. Здесь редактируются только безопасные поля.')
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
                ])
                ->columns(2),
        ]);
    }
}
