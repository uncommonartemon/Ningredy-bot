<?php

namespace App\Filament\Resources\TelegramUpdates\Tables;

use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class TelegramUpdatesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('update_id')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('telegram_user_id')
                    ->searchable(),
                TextColumn::make('chat_id')
                    ->searchable(),
                TextColumn::make('message_id')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('username')
                    ->searchable(),
                TextColumn::make('status')
                    ->searchable(),
                TextColumn::make('processed_at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }
}
