<?php

namespace App\Filament\Schemas;

use App\Services\Products\CatalogLocales;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;

final class ProductTranslations
{
    /**
     * @param  bool  $configuration  a configuration's tabs also carry its own words, its name and colour
     *                               (decision of 2026-11-27): a form saves only the fields it has, and
     *                               without them every save of the product dropped their translations
     */
    public static function make(bool $configuration = false): Tabs
    {
        return Tabs::make(__('Переводы'))->tabs(array_map(
            fn (string $locale, string $label) => Tab::make($label)->schema([
                TextInput::make("translations.$locale.title")->label(__('Название'))->maxLength(255),
                Textarea::make("translations.$locale.description")->label(__('Описание'))->rows(6)->maxLength(5000)
                    ->helperText(__('product-assistant.translation_fields_help')),
                ...($configuration ? [
                    TextInput::make("translations.$locale.name")->label(__('Название конфигурации'))->maxLength(255),
                    TextInput::make("translations.$locale.color")->label(__('Цвет'))->maxLength(255),
                ] : []),
            ]), array_keys(CatalogLocales::contentTranslations()), array_values(CatalogLocales::contentTranslations()),
        ))->columnSpanFull();
    }
}
