<?php

namespace App\Filament\Resources\TelegramUpdates\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class TelegramUpdateInfolist
{
    public static function configure(Schema $schema): Schema
    {
        $json = fn (mixed $state): string => $state === null || $state === []
            ? '—'
            : (json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '—');

        return $schema
            ->components([
                Section::make('Обновление')
                    ->schema([
                        TextEntry::make('update_id')->label('#')->numeric(),
                        TextEntry::make('chat_id')->label('Chat ID')->placeholder('—')->copyable(),
                        TextEntry::make('telegram_user_id')->label('Telegram user')->placeholder('—'),
                        TextEntry::make('username')->label('Пользователь')->placeholder('—'),
                        TextEntry::make('message_id')->label('Message ID')->numeric()->placeholder('—'),
                        TextEntry::make('status')
                            ->label('Статус')
                            ->badge()
                            ->formatStateUsing(fn (string $state): string => match ($state) {
                                'processed' => 'Обработано',
                                'received' => 'Получено',
                                'failed' => 'Ошибка',
                                'cancelled' => 'Отменено',
                                default => $state,
                            }),
                        TextEntry::make('text')->label('Текст')->placeholder('—')->columnSpanFull(),
                        TextEntry::make('reply_to_text')->label('В ответ на')->placeholder('—')->columnSpanFull(),
                    ])
                    ->columns(4),
                Section::make('Обработка')
                    ->schema([
                        TextEntry::make('created_at')->label('Получено')->dateTime('d.m.Y H:i:s'),
                        TextEntry::make('processed_at')->label('Обработано')->dateTime('d.m.Y H:i:s')->placeholder('—'),
                        TextEntry::make('cancel_requested_at')->label('Запрошена отмена')->dateTime('d.m.Y H:i:s')->placeholder('—'),
                        TextEntry::make('error')->label('Ошибка')->placeholder('—')->columnSpanFull(),
                    ])
                    ->columns(3),
                Section::make('Payload')
                    ->schema([
                        TextEntry::make('payload')->label(null)->formatStateUsing($json)->columnSpanFull(),
                    ])
                    ->collapsed(),
            ]);
    }
}
