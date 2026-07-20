<?php

namespace App\Filament\Resources\TelegramUpdates\Schemas;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class TelegramUpdateForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('update_id')
                    ->required()
                    ->numeric(),
                TextInput::make('telegram_user_id')
                    ->tel(),
                TextInput::make('chat_id'),
                TextInput::make('message_id')
                    ->numeric(),
                TextInput::make('username'),
                Textarea::make('text')
                    ->columnSpanFull(),
                Textarea::make('payload')
                    ->required()
                    ->columnSpanFull(),
                TextInput::make('status')
                    ->required()
                    ->default('received'),
                Textarea::make('error')
                    ->columnSpanFull(),
                DateTimePicker::make('processed_at'),
            ]);
    }
}
