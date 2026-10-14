<?php

namespace App\Filament\Resources\ProductGalleryRecipes\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rules\Unique;

class ProductGalleryRecipeForm
{
    public static function configure(Schema $schema): Schema
    {
        // Without an explicit columns(1), EditRecord/CreateRecord default
        // this top-level schema to columns(2) and our single Section below
        // only fills one of those tracks, halving the usable width.
        return $schema
            ->columns(1)
            ->components([
                Section::make(__('Сайт и состояние'))
                    ->description(__('Диагностика, статистика и AI-рецепт доступны на странице просмотра. Здесь редактируются только безопасные поля.'))
                    ->schema([
                        TextInput::make('domain')
                            ->label(__('Домен'))
                            ->required()
                            ->maxLength(255),
                        TextInput::make('path_pattern')
                            ->label(__('Шаблон пути'))
                            ->helperText(__('* означает все товарные страницы домена.'))
                            ->required()
                            ->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule, Get $get): Unique => $rule->where('domain', $get('domain')))
                            ->maxLength(255),
                        Select::make('status')
                            ->label(__('Состояние'))
                            ->options([
                                'active' => __('Активен'),
                                'learning' => __('Обучается'),
                                'disabled' => __('Отключён'),
                            ])
                            ->required()
                            ->native(false),
                        Toggle::make('source_blocked')
                            ->label(__('Исключить область этого рецепта'))
                            ->helperText(__('Блокируется только указанный шаблон пути. Для всего домена используйте «Заблокировать домен» на странице просмотра. Шаблон * действует на весь домен.')),
                        Textarea::make('source_block_reason')
                            ->label(__('Причина блокировки источника'))
                            ->rows(3)
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
            ]);
    }
}
