<?php

namespace App\Filament\Resources\TelegramUpdates\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class TelegramUpdateInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('update_id')
                    ->numeric(),
                TextEntry::make('telegram_user_id')
                    ->placeholder('-'),
                TextEntry::make('chat_id')
                    ->placeholder('-'),
                TextEntry::make('message_id')
                    ->numeric()
                    ->placeholder('-'),
                TextEntry::make('username')
                    ->placeholder('-'),
                TextEntry::make('text')
                    ->placeholder('-')
                    ->columnSpanFull(),
                TextEntry::make('payload')
                    ->formatStateUsing(fn (mixed $state): string => self::json($state))
                    ->columnSpanFull(),
                TextEntry::make('status'),
                TextEntry::make('error')
                    ->placeholder('-')
                    ->columnSpanFull(),
                TextEntry::make('processed_at')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('created_at')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('updated_at')
                    ->dateTime()
                    ->placeholder('-'),
            ]);
    }

    private static function json(mixed $value): string
    {
        return is_array($value)
            ? json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : (string) $value;
    }
}
