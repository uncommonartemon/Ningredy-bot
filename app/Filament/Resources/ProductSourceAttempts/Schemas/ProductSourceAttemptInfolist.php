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
            TextEntry::make('telegram_update_id')->label(__('Запрос Telegram #'))->placeholder('—'),
            TextEntry::make('product_draft_id')->label(__('Черновик #'))->placeholder('—'),
            TextEntry::make('product_gallery_recipe_version_id')->label(__('Версия рецепта #'))->placeholder('—'),
            TextEntry::make('created_at')->label(__('Время'))->dateTime('d.m.Y H:i:s'),
            TextEntry::make('product_url')->label(__('Страница'))->url(fn ($record): string => $record->product_url)->openUrlInNewTab()->copyable()->columnSpanFull(),
            TextEntry::make('actor')->label(__('Исполнитель'))->badge(),
            TextEntry::make('phase')->label(__('Этап'))->badge(),
            TextEntry::make('action')->label(__('Действие')),
            TextEntry::make('status')->label(__('Статус'))->badge(),
            TextEntry::make('decision')->label(__('Решение'))->placeholder('—'),
            TextEntry::make('round')->label(__('Раунд'))->placeholder('—'),
            TextEntry::make('duration_ms')->label(__('Длительность, мс'))->placeholder('—'),
            TextEntry::make('message')->label(__('Итог'))->placeholder('—')->columnSpanFull(),
            TextEntry::make('input')->label(__('Вход'))->formatStateUsing($json)->copyable()->columnSpanFull(),
            TextEntry::make('output')->label(__('Выход'))->formatStateUsing($json)->copyable()->columnSpanFull(),
        ]);
    }
}
