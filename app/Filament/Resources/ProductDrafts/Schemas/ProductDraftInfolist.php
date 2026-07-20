<?php

namespace App\Filament\Resources\ProductDrafts\Schemas;

use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class ProductDraftInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('telegramUpdate.id')
                    ->label('Telegram update'),
                TextEntry::make('aiRun.id')
                    ->label('Ai run'),
                TextEntry::make('status'),
                TextEntry::make('requested_by_telegram_user_id'),
                TextEntry::make('title'),
                TextEntry::make('brand')
                    ->placeholder('-'),
                TextEntry::make('model')
                    ->placeholder('-'),
                TextEntry::make('color')
                    ->placeholder('-'),
                TextEntry::make('description')
                    ->placeholder('-')
                    ->columnSpanFull(),
                TextEntry::make('research_notes')
                    ->label('Внутренние заметки исследования')
                    ->placeholder('-')
                    ->columnSpanFull(),
                TextEntry::make('specifications')
                    ->columnSpanFull(),
                TextEntry::make('sources')
                    ->columnSpanFull(),
                ImageEntry::make('image_urls')
                    ->columnSpanFull(),
                TextEntry::make('confidence')
                    ->numeric(),
                TextEntry::make('created_at')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('updated_at')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('reviewed_at')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('reviewed_by_user_id')
                    ->numeric()
                    ->placeholder('-'),
                TextEntry::make('reviewed_by_telegram_user_id')
                    ->placeholder('-'),
                TextEntry::make('rejection_reason')
                    ->placeholder('-')
                    ->columnSpanFull(),
            ]);
    }
}
