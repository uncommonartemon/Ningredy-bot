<?php

namespace App\Filament\Resources\AttributeDefinitions\Schemas;

use App\Filament\Schemas\RelatedCatalogForms;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class AttributeDefinitionForm
{
    public static function configure(Schema $schema): Schema
    {
        // Without an explicit columns(1), EditRecord/CreateRecord default
        // this top-level schema to columns(2) and our single Section below
        // only fills one of those tracks, halving the usable width.
        return $schema
            ->columns(1)
            ->components([
                Section::make(__('Атрибут'))
                    ->schema([
                        TextInput::make('label')->label(__('Название'))->required()->maxLength(255),
                        RelatedCatalogForms::attributeGroupSelect(),
                        Select::make('data_type')->label(__('Тип данных'))->options([
                            'text' => __('Текст'),
                            'number' => __('Число'),
                            'boolean' => __('Да/нет'),
                        ])->default('text')->required(),
                        TextInput::make('default_unit')->label(__('Единица по умолчанию'))->maxLength(24),
                        TextInput::make('sort_order')->label(__('Порядок'))->numeric()->minValue(0)->default(0),
                        Toggle::make('is_filterable')->label(__('Показывать в фильтрах'))->default(true),
                        Toggle::make('is_variant')->label(__('Атрибут конфигурации (SKU)'))->default(true),
                    ])
                    ->columns(2),
            ]);
    }
}
