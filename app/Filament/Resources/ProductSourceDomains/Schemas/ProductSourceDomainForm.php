<?php

namespace App\Filament\Resources\ProductSourceDomains\Schemas;

use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ProductSourceDomainForm
{
    public static function configure(Schema $schema): Schema
    {
        // Without an explicit columns(1), EditRecord/CreateRecord default
        // this top-level schema to columns(2) and our single Section below
        // only fills one of those tracks, halving the usable width.
        return $schema
            ->columns(1)
            ->components([
                Section::make('Настройки домена')
                    ->description('Постоянная подсказка передаётся AI-предфильтру и тренеру Playwright-рецепта на всех товарных страницах домена.')
                    ->schema([
                        TextInput::make('domain')
                            ->label('Домен')
                            ->helperText('Можно вводить с www или без него; сохраняется канонический домен без www.')
                            ->required()
                            ->unique(ignoreRecord: true)
                            ->maxLength(255),
                        Textarea::make('agent_hint')
                            ->label('Подсказка агенту')
                            ->helperText('Опишите неочевидную механику галереи. Подсказка направляет агента, но не отменяет проверку DOM, безопасности и фактически полученного разрешения.')
                            ->rows(6)
                            ->maxLength(4000)
                            ->columnSpanFull(),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),
            ]);
    }
}
