<?php

namespace App\Filament\Schemas;

use App\Services\Products\CatalogLocales;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;

final class ProductTranslations
{
    public static function make(): Tabs
    {
        return Tabs::make(__('Переводы'))->tabs(array_map(
            fn (string $locale, string $label) => Tab::make($label)->schema([
                TextInput::make("translations.$locale.title")->label(__('Название'))->maxLength(255),
                Textarea::make("translations.$locale.description")->label(__('Описание'))->rows(6)->maxLength(5000)
                    ->helperText(__('product-assistant.translation_fields_help')),
            ]), array_keys(CatalogLocales::CONTENT_TRANSLATIONS), array_values(CatalogLocales::CONTENT_TRANSLATIONS),
        ))->columnSpanFull();
    }
}
