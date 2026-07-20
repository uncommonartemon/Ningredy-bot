<?php

namespace App\Filament\Resources\Categories\Schemas;

use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
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
