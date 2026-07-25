<?php

namespace App\Filament\Resources\ImageSourcePriorities\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ImageSourcePriorityForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Источник изображений')
                ->description('Чем выше приоритет, тем раньше этот сайт проверяется при создании готового черновика.')
                ->schema([
                    TextInput::make('name')
                        ->label('Название')
                        ->required()
                        ->maxLength(255),
                    TextInput::make('domain')
                        ->label('Основной домен')
                        ->placeholder('amazon.com')
                        ->helperText('Без пути. Можно вставить полный URL — сохранится только домен.')
                        ->required()
                        ->unique(ignoreRecord: true)
                        ->maxLength(255),
                    Select::make('source_type')
                        ->label('Тип')
                        ->options([
                            'marketplace' => 'Маркетплейс',
                            'retailer' => 'Магазин',
                            'manufacturer' => 'Производитель',
                            'database' => 'База товаров',
                            'review' => 'Обзор',
                            'web' => 'Другой сайт',
                        ])
                        ->required()
                        ->native(false),
                    TextInput::make('priority')
                        ->label('Приоритет')
                        ->helperText('Большее значение означает более раннюю проверку.')
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(10000)
                        ->default(500)
                        ->required(),
                    TagsInput::make('aliases')
                        ->label('Дополнительные домены')
                        ->placeholder('Добавить домен')
                        ->helperText('Например amazon.de или media-amazon.com.')
                        ->columnSpanFull(),
                    Toggle::make('is_enabled')
                        ->label('Использовать в поиске')
                        ->default(true)
                        ->inline(false),
                    Textarea::make('notes')
                        ->label('Заметки')
                        ->rows(4)
                        ->columnSpanFull(),
                ])
                ->columns(2)
                ->columnSpanFull(),
        ]);
    }
}
