<?php

namespace App\Filament\Resources\ProductSourceAttempts\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class ProductSourceAttemptInfolist
{
    public static function configure(Schema $schema): Schema
    {
        $json = fn (mixed $state): string => $state === null
            ? '—'
            : (json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '—');

        return $schema->components([
            TextEntry::make('id')->label('#'),
            TextEntry::make('created_at')->label('Время')->dateTime('d.m.Y H:i:s'),
            TextEntry::make('product_url')->label('Страница')->url(fn ($record): string => $record->product_url)->openUrlInNewTab()->copyable()->columnSpanFull(),
            TextEntry::make('actor')->label('Исполнитель')->badge(),
            TextEntry::make('phase')->label('Этап')->badge(),
            TextEntry::make('action')->label('Действие'),
            TextEntry::make('status')->label('Статус')->badge(),
            TextEntry::make('decision')->label('Решение')->placeholder('—'),
            TextEntry::make('round')->label('Раунд')->placeholder('—'),
            TextEntry::make('duration_ms')->label('Длительность, мс')->placeholder('—'),
            TextEntry::make('message')->label('Итог')->placeholder('—')->columnSpanFull(),
            TextEntry::make('input')->label('Вход')->formatStateUsing($json)->copyable()->columnSpanFull(),
            TextEntry::make('output')->label('Выход')->formatStateUsing($json)->copyable()->columnSpanFull(),
        ]);
    }
}
