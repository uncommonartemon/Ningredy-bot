<?php

namespace App\Filament\Resources\AiRuns\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class AiRunInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('telegramUpdate.id')
                    ->label('Telegram update'),
                TextEntry::make('invocation_id')
                    ->placeholder('-'),
                TextEntry::make('provider'),
                TextEntry::make('model'),
                TextEntry::make('status'),
                TextEntry::make('prompt')
                    ->columnSpanFull(),
                TextEntry::make('response')
                    ->formatStateUsing(fn (mixed $state): string => self::json($state))
                    ->placeholder('-')
                    ->columnSpanFull(),
                TextEntry::make('usage')
                    ->formatStateUsing(fn (mixed $state): string => self::json($state))
                    ->placeholder('-')
                    ->columnSpanFull(),
                TextEntry::make('error')
                    ->placeholder('-')
                    ->columnSpanFull(),
                TextEntry::make('started_at')
                    ->dateTime(),
                TextEntry::make('completed_at')
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
