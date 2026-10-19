<?php

namespace App\Filament\Resources\Brands\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class BrandForm
{
    public static function configure(Schema $schema): Schema
    {
        // Without an explicit columns(1), EditRecord/CreateRecord default
        // this top-level schema to columns(2), halving the usable width.
        // The layout follows the product page: details left, status right.
        return $schema->dense()
            ->columns(1)
            ->components([
                Grid::make(['default' => 1, 'lg' => 3])->schema([
                    Section::make(__('Бренд'))
                        ->schema([
                            TextInput::make('name')->label(__('Название'))->required()->maxLength(255)->unique(ignoreRecord: true),
                            TextInput::make('country')->label(__('Страна')),
                            TextInput::make('website_url')->label(__('Сайт'))->url(),
                        ])
                        ->gridContainer()->columns(['default' => 1, '@md' => 2, '@xl' => 3])
                        ->columnSpan(['default' => 1, 'lg' => 2]),
                    Section::make(__('Публикация'))
                        ->schema([
                            Toggle::make('is_active')->label(__('Активен'))->default(true),
                        ]),
                ]),
            ]);
    }
}
