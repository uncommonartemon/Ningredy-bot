<?php

namespace App\Filament\Resources\ReferenceSites\Schemas;

use App\Models\ReferenceSite;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ReferenceSiteForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->dense()
            ->columns(1)
            ->components([
                Section::make(__('Справочный сайт'))
                    ->description(__('Сайт, которому вы доверяете для определённых товаров. Поиск сам решает, подходит ли товар под строку, и берёт с сайта только то, что здесь разрешено. Бот может добавить такую строку сам, если сказать ему в чате, например: «для SSD всегда смотри harddrivebenchmark.net, фото оттуда не бери». В шаблонах можно писать {code}, {model} и {brand}.'))
                    ->schema([
                        TextInput::make('domain')
                            ->label(__('Сайт'))
                            ->required()
                            ->maxLength(255)
                            ->placeholder('harddrivebenchmark.net'),
                        TextInput::make('applies_to')
                            ->label(__('Для каких товаров'))
                            ->required()
                            ->maxLength(500)
                            ->placeholder(__('SSD и жёсткие диски'))
                            ->hintIcon('heroicon-o-question-mark-circle', tooltip: __('Своими словами: «SSD и HDD», «процессоры Intel», «память Kingston». Поиск сам определяет, подходит ли товар.')),
                        Toggle::make('use_for_facts')
                            ->label(__('Брать характеристики'))
                            ->default(true),
                        Toggle::make('use_for_photos')
                            ->label(__('Брать фото'))
                            ->default(false)
                            ->hintIcon('heroicon-o-question-mark-circle', tooltip: __('Выключено — фото с этого сайта не берутся никогда, даже если поиск их нашёл.')),
                        Radio::make('access')
                            ->label(__('Кто открывает сайт'))
                            ->options([
                                ReferenceSite::ACCESS_BOT => __('Бот ищет и читает сам'),
                                ReferenceSite::ACCESS_PERSON => __('Только человек: сайт закрыт для ботов, бот даёт ссылку'),
                            ])
                            ->default(ReferenceSite::ACCESS_BOT)
                            ->required()
                            ->columnSpanFull(),
                        TextInput::make('search_hint')
                            ->label(__('Как найти товар веб-поиском'))
                            ->maxLength(500)
                            ->placeholder('site:harddrivebenchmark.net {code}'),
                        TextInput::make('link_template')
                            ->label(__('Ссылка на товар'))
                            ->maxLength(1024)
                            ->placeholder('https://www.techpowerup.com/ssd-specs/?q={model}')
                            ->hintIcon('heroicon-o-question-mark-circle', tooltip: __('Адрес, который открывает товар по коду или модели. Для сайта «только человек» эту ссылку бот присылает оператору.')),
                        Textarea::make('instructions')
                            ->label(__('Подсказка агенту'))
                            ->rows(3)
                            ->maxLength(2000)
                            ->columnSpanFull(),
                        TextInput::make('sort_order')
                            ->label(__('Порядок'))
                            ->numeric()
                            ->minValue(0)
                            ->default(100)
                            ->hintIcon('heroicon-o-question-mark-circle', tooltip: __('Меньше — раньше: в таком порядке сайты предлагаются поиску.')),
                        Toggle::make('enabled')
                            ->label(__('Включён'))
                            ->default(true),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),
            ]);
    }
}
