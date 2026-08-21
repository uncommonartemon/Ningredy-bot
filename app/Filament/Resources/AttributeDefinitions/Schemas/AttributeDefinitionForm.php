<?php

namespace App\Filament\Resources\AttributeDefinitions\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class AttributeDefinitionForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Атрибут')
                ->schema([
                    TextInput::make('key')->label('Ключ')->unique(ignoreRecord: true)->required(),
                    TextInput::make('label')->label('Название')->required(),
                    Select::make('data_type')->label('Тип данных')->options([
                        'text' => 'Текст',
                        'number' => 'Число',
                        'boolean' => 'Да/нет',
                    ])->default('text')->required(),
                    TextInput::make('default_unit')->label('Единица по умолчанию')->maxLength(24),
                    TextInput::make('sort_order')->label('Порядок')->numeric()->minValue(0)->default(0),
                    Toggle::make('is_filterable')->label('Показывать в фильтрах')->default(true),
                    Toggle::make('is_variant')->label('Атрибут конфигурации (SKU)')->default(true),
                ])
                ->columns(2),
        ]);
    }
}
