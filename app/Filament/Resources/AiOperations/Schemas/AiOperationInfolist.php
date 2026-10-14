<?php

namespace App\Filament\Resources\AiOperations\Schemas;

use App\Models\AiOperation;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class AiOperationInfolist
{
    public static function configure(Schema $schema): Schema
    {
        $json = fn (mixed $state): string => $state === null || $state === []
            ? '—'
            : (json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '—');

        return $schema
            ->components([
                Section::make(__('Операция'))
                    ->schema([
                        TextEntry::make('tool')->label(__('Инструмент'))->badge(),
                        TextEntry::make('action')->label(__('Действие')),
                        TextEntry::make('target')
                            ->label(__('Цель'))
                            ->state(fn (AiOperation $record): ?string => $record->target_type
                                ? class_basename($record->target_type).' #'.$record->target_id
                                : null)
                            ->placeholder('—'),
                        TextEntry::make('status')->label(__('Статус'))->badge(),
                        TextEntry::make('telegramUpdate.id')->label('Telegram update')->placeholder('—'),
                        TextEntry::make('telegram_user_id')->label('Telegram user')->placeholder('—'),
                        TextEntry::make('idempotency_key')->label('Idempotency key')->placeholder('—')->copyable(),
                        TextEntry::make('executed_at')->label(__('Выполнено'))->dateTime('d.m.Y H:i:s')->placeholder('—'),
                        TextEntry::make('error')->label(__('Ошибка'))->placeholder('—')->columnSpanFull(),
                    ])
                    ->columns(4)
                    ->columnSpanFull(),
                Section::make(__('Payload и результат'))
                    ->schema([
                        TextEntry::make('payload')->label('Payload')->formatStateUsing($json)->columnSpanFull(),
                        TextEntry::make('result')->label(__('Результат'))->formatStateUsing($json)->columnSpanFull(),
                    ])
                    ->collapsed()
                    ->columnSpanFull(),
            ]);
    }
}
