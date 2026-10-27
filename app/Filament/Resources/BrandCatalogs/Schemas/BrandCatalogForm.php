<?php

namespace App\Filament\Resources\BrandCatalogs\Schemas;

use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class BrandCatalogForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->dense()
            ->columns(1)
            ->components([
                Section::make(__('Каталог бренда'))
                    ->description(__('Где бренд хранит свой каталог и как найти в нём товар по коду. Icecat остаётся первым; каталог бренда — следующий официальный источник. В шаблонах можно писать {code}, {model} и {brand}.'))
                    ->schema([
                        TextInput::make('brand')
                            ->label(__('Бренд'))
                            ->required()
                            ->maxLength(100),
                        TagsInput::make('aliases')
                            ->label(__('Другие написания'))
                            ->hintIcon('heroicon-o-question-mark-circle', tooltip: __('Например WD для Western Digital. По бренду и этим словам каталог узнаётся в запросе и в найденном товаре.')),
                        TextInput::make('catalog_url')
                            ->label(__('Адрес каталога'))
                            ->url()
                            ->maxLength(1024),
                        TextInput::make('search_hint')
                            ->label(__('Как найти код веб-поиском'))
                            ->hintIcon('heroicon-o-question-mark-circle', tooltip: __('Запрос для агента, например: site:psref.lenovo.com {code}')),
                        TextInput::make('lookup_template')
                            ->label(__('Страница товара по коду'))
                            ->hintIcon('heroicon-o-question-mark-circle', tooltip: __('Адрес, который открывает сам товар по коду, например https://example.com/p/{code}. Страница встаёт в очередь сразу после Icecat.'))
                            ->maxLength(1024),
                        TextInput::make('api_template')
                            ->label(__('Публичный API по коду'))
                            ->hintIcon('heroicon-o-question-mark-circle', tooltip: __('JSON-адрес бренда, например https://api.example.com/products?sku={code}. Какие поля брать — ниже.'))
                            ->maxLength(1024),
                        KeyValue::make('response_map')
                            ->label(__('Поля ответа API'))
                            ->keyLabel(__('Что'))
                            ->valueLabel(__('Путь в JSON'))
                            ->hintIcon('heroicon-o-question-mark-circle', tooltip: __('images — путь к адресам фото (data.images[*].url), title — название; text — путь к тексту характеристик.')),
                        Textarea::make('instructions')
                            ->label(__('Подсказка агенту'))
                            ->rows(4)
                            ->maxLength(2000)
                            ->columnSpanFull(),
                        Toggle::make('enabled')
                            ->label(__('Включён'))
                            ->default(true),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),
            ]);
    }
}
