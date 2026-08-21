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
                Section::make('Запуск')
                    ->schema([
                        TextEntry::make('telegramUpdate.id')->label('Telegram update'),
                        TextEntry::make('invocation_id')->label('Invocation ID')->placeholder('—')->copyable(),
                        TextEntry::make('provider')->label('Провайдер')->badge(),
                        TextEntry::make('model')->label('Модель'),
                        TextEntry::make('status')
                            ->label('Статус')
                            ->badge()
                            ->formatStateUsing(fn (string $state): string => match ($state) {
                                'completed' => 'Завершён',
                                'running' => 'Выполняется',
                                'failed' => 'Ошибка',
                                default => $state,
                            }),
                        TextEntry::make('duration')
                            ->label('Длительность')
                            ->state(fn (AiRun $record): ?string => $record->started_at
                                ? $record->started_at->diffForHumans($record->completed_at ?: now(), syntax: CarbonInterface::DIFF_ABSOLUTE, short: true)
                                : null)
                            ->placeholder('—'),
                        TextEntry::make('started_at')->label('Начат')->dateTime('d.m.Y H:i:s'),
                        TextEntry::make('completed_at')->label('Завершён')->dateTime('d.m.Y H:i:s')->placeholder('—'),
                    ])
                    ->columns(4),
                Section::make('Промпт и ответ')
                    ->schema([
                        TextEntry::make('prompt')->label('Промпт')->columnSpanFull(),
                        TextEntry::make('response')->label('Ответ')->formatStateUsing($json)->columnSpanFull(),
                        TextEntry::make('error')->label('Ошибка')->placeholder('—')->columnSpanFull(),
                    ])
                    ->collapsed(),
                Section::make('Расход')
                    ->schema([
                        KeyValueEntry::make('usage')
                            ->label('Токены и стоимость')
                            ->state(fn (AiRun $record): array => is_array($record->usage) ? $record->usage : [])
                            ->columnSpanFull(),
                    ]),
                Section::make('Активность')
                    ->description('Подтверждённые события провайдера (подключение, запуск/выполнение/завершение поиска).')
                    ->schema([
                        TextEntry::make('activity')->label(null)->formatStateUsing($json)->columnSpanFull(),
                    ])
                    ->collapsed(),
            ]);
    }
}
