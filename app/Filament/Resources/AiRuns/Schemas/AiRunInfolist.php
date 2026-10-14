<?php

namespace App\Filament\Resources\AiRuns\Schemas;

use App\Models\AiRun;
use Carbon\CarbonInterface;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class AiRunInfolist
{
    public static function configure(Schema $schema): Schema
    {
        $json = fn (mixed $state): string => $state === null || $state === []
            ? '—'
            : (json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '—');

        return $schema
            ->components([
                Section::make(__('Запуск'))
                    ->schema([
                        TextEntry::make('telegramUpdate.id')->label('Telegram update'),
                        TextEntry::make('invocation_id')->label('Invocation ID')->placeholder('—')->copyable(),
                        TextEntry::make('provider')->label(__('Провайдер'))->badge(),
                        TextEntry::make('model')->label(__('Модель')),
                        TextEntry::make('status')
                            ->label(__('Статус'))
                            ->badge()
                            ->formatStateUsing(fn (string $state): string => match ($state) {
                                'completed' => __('Завершён'),
                                'running' => __('Выполняется'),
                                'failed' => __('Ошибка'),
                                default => $state,
                            }),
                        TextEntry::make('duration')
                            ->label(__('Длительность'))
                            ->state(fn (AiRun $record): ?string => $record->started_at
                                ? $record->started_at->diffForHumans($record->completed_at ?: now(), syntax: CarbonInterface::DIFF_ABSOLUTE, short: true)
                                : null)
                            ->placeholder('—'),
                        TextEntry::make('started_at')->label(__('Начат'))->dateTime('d.m.Y H:i:s'),
                        TextEntry::make('completed_at')->label(__('Завершён'))->dateTime('d.m.Y H:i:s')->placeholder('—'),
                    ])
                    ->columns(4)
                    ->columnSpanFull(),
                Section::make(__('Промпт и ответ'))
                    ->schema([
                        TextEntry::make('prompt')->label(__('Промпт'))->columnSpanFull(),
                        TextEntry::make('response')->label(__('Ответ'))->formatStateUsing($json)->columnSpanFull(),
                        TextEntry::make('error')->label(__('Ошибка'))->placeholder('—')->columnSpanFull(),
                    ])
                    ->collapsed()
                    ->columnSpanFull(),
                Section::make(__('Расход'))
                    ->schema([
                        KeyValueEntry::make('usage')
                            ->label(__('Токены и стоимость'))
                            ->state(fn (AiRun $record): array => is_array($record->usage) ? $record->usage : [])
                            ->columnSpanFull(),
                    ])
                    ->columnSpanFull(),
                Section::make(__('Активность'))
                    ->description(__('Подтверждённые события провайдера (подключение, запуск/выполнение/завершение поиска).'))
                    ->schema([
                        TextEntry::make('activity')->label(null)->formatStateUsing($json)->columnSpanFull(),
                    ])
                    ->collapsed()
                    ->columnSpanFull(),
            ]);
    }
}
