<?php

namespace App\Filament\Resources\AiRuns\Schemas;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class AiRunForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('telegram_update_id')
                    ->relationship('telegramUpdate', 'id')
                    ->required(),
                TextInput::make('invocation_id'),
                TextInput::make('provider')
                    ->required(),
                TextInput::make('model')
                    ->required(),
                TextInput::make('status')
                    ->required()
                    ->default('running'),
                Textarea::make('prompt')
                    ->required()
                    ->columnSpanFull(),
                Textarea::make('response')
                    ->columnSpanFull(),
                Textarea::make('usage')
                    ->columnSpanFull(),
                Textarea::make('error')
                    ->columnSpanFull(),
                DateTimePicker::make('started_at')
                    ->required(),
                DateTimePicker::make('completed_at'),
            ]);
    }
}
