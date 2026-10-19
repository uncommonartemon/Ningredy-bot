<?php

namespace App\Filament\Resources\TelegramUpdates\Schemas;

use App\Models\TelegramUpdate;
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

        return $schema->dense()
            ->components([
                Section::make(__('Обновление'))
                    ->schema([
                        TextEntry::make('update_id')->label('#')->numeric(),
                        TextEntry::make('chat_id')->label('Chat ID')->placeholder('—')->copyable(),
                        TextEntry::make('telegram_user_id')->label('Telegram user')->placeholder('—'),
                        TextEntry::make('username')->label(__('Пользователь'))->placeholder('—'),
                        TextEntry::make('message_id')->label('Message ID')->numeric()->placeholder('—'),
                        TextEntry::make('status')
                            ->label(__('Статус'))
                            ->badge()
                            ->formatStateUsing(fn (string $state): string => match ($state) {
                                'processed' => __('Обработано'),
                                'received' => __('Получено'),
                                'delivering' => __('Отправляется ответ'),
                                'failed' => __('Ошибка'),
                                'cancelled' => __('Отменено'),
                                default => $state,
                            })
                            ->color(fn (?TelegramUpdate $record): ?string => $record?->status === 'delivering' ? 'gray' : null),
                        TextEntry::make('text')->label(__('Текст'))->placeholder('—')->columnSpanFull(),
                        TextEntry::make('reply_to_text')->label(__('В ответ на'))->placeholder('—')->columnSpanFull(),
                    ])
                    ->columns(4)
                    ->columnSpanFull(),
                Section::make(__('Обработка'))
                    ->schema([
                        TextEntry::make('created_at')->label(__('Получено'))->dateTime('d.m.Y H:i:s'),
                        TextEntry::make('processed_at')->label(__('Обработано'))->dateTime('d.m.Y H:i:s')->placeholder('—'),
                        TextEntry::make('cancel_requested_at')->label(__('Запрошена отмена'))->dateTime('d.m.Y H:i:s')->placeholder('—'),
                        TextEntry::make('error')->label(__('Ошибка'))->placeholder('—')->columnSpanFull(),
                    ])
                    ->columns(3)
                    ->columnSpanFull(),
                Section::make('Payload')
                    ->schema([
                        TextEntry::make('payload')->label(null)->formatStateUsing($json)->columnSpanFull(),
                    ])
                    ->collapsed()
                    ->columnSpanFull(),
            ]);
    }
}
