<?php

namespace App\Filament\Resources\ProductGalleryRecipes\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
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
        return $schema->dense()
            ->columns(1)
            ->components([
                Grid::make(['default' => 1, 'lg' => 3])->schema([
                    Section::make(__('Сайт'))
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
                        ])
                        ->columns(['default' => 1, 'md' => 2])
                        ->columnSpan(['default' => 1, 'lg' => 2]),
                    Section::make(__('Состояние'))
                        ->schema([
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
                                ->hintIcon('heroicon-o-question-mark-circle', tooltip: __('Блокируется только указанный шаблон пути. Для всего домена используйте «Заблокировать домен» на странице просмотра. Шаблон * действует на весь домен.')),
                            Textarea::make('source_block_reason')
                                ->label(__('Причина блокировки источника'))
                                ->rows(2)->autosize(),
                        ]),
                ]),
            ]);
    }
}
