<?php

namespace App\Filament\Resources\ProductSourceAttempts\Schemas;

use App\Filament\Resources\ProductDrafts\ProductDraftResource;
use App\Filament\Resources\TelegramUpdates\TelegramUpdateResource;
use App\Filament\Schemas\FailureDiagnosisInfolist;
use App\Services\Admin\DatabaseValueRedactor;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ProductSourceAttemptInfolist
{
    public static function configure(Schema $schema): Schema
    {
        $json = fn (mixed $state): string => app(DatabaseValueRedactor::class)->text($state);

        return $schema->dense()->components([
            TextEntry::make('id')->label('#'),
            TextEntry::make('telegram_update_id')->label(__('Запрос Telegram #'))->placeholder('—')
                ->url(fn ($record): ?string => $record->telegram_update_id ? TelegramUpdateResource::getUrl('view', ['record' => $record->telegram_update_id]) : null),
            TextEntry::make('product_draft_id')->label(__('Черновик #'))->placeholder('—')
                ->url(fn ($record): ?string => $record->product_draft_id ? ProductDraftResource::getUrl('view', ['record' => $record->product_draft_id]) : null),
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
            TextEntry::make('message')->label(__('Итог'))->formatStateUsing($json)->placeholder('—')->columnSpanFull(),
            FailureDiagnosisInfolist::section('output.failure_diagnosis'),
            Section::make(__('Вход и выход'))->schema([
                TextEntry::make('input')->label(__('Вход'))->state(fn ($record): string => $json($record->input))->copyable()->columnSpanFull()
                    ->extraAttributes(['style' => 'white-space: pre-wrap; overflow-wrap: anywhere; max-height: 65vh; overflow: auto;']),
                TextEntry::make('output')->label(__('Выход'))->state(fn ($record): string => $json($record->output))->copyable()->columnSpanFull()
                    ->extraAttributes(['style' => 'white-space: pre-wrap; overflow-wrap: anywhere; max-height: 65vh; overflow: auto;']),
            ])->collapsed()->columnSpanFull(),
        ]);
    }
}
