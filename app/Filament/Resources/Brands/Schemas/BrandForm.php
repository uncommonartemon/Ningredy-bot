<?php

namespace App\Filament\Resources\Brands\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class BrandForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Бренд')
                ->schema([
                    TextInput::make('name')->label('Название')->required(),
                    TextInput::make('slug')->label('Slug')->unique(ignoreRecord: true)->required(),
                    TextInput::make('country')->label('Страна'),
                    TextInput::make('website_url')->label('Сайт')->url(),
                    Toggle::make('is_active')->label('Активен')->default(true),
                ])
                ->columns(2),
        ]);
    }
}
