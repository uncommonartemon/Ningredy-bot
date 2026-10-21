<?php

namespace App\Filament\Resources\AiOperations\Tables;

use App\Filament\Resources\TelegramUpdates\TelegramUpdateResource;
use App\Models\AiOperation;
use Filament\Actions\ActionGroup;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class AiOperationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            // A journal grows without bound: "all" rows no longer fit in memory.
            ->paginationPageOptions([25, 50, 100])
            ->defaultSort('executed_at', 'desc')
            ->columns([
                TextColumn::make('id')->label('#')->sortable(),
                TextColumn::make('executed_at')->label(__('Выполнено'))->dateTime('d.m.Y H:i:s')->sortable(),
                TextColumn::make('tool')->label(__('Инструмент'))->badge(),
                TextColumn::make('action')->label(__('Действие'))->searchable(),
                TextColumn::make('target')
                    ->label(__('Цель'))
                    ->state(fn (AiOperation $record): ?string => $record->target_type
                        ? class_basename($record->target_type).' #'.$record->target_id
                        : null)
                    ->placeholder('—'),
                TextColumn::make('status')
                    ->label(__('Статус'))
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'completed' => 'success',
                        'failed' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('telegramUpdate.id')
                    ->label('Telegram update')
                    ->url(fn (AiOperation $record): ?string => $record->telegram_update_id
                        ? TelegramUpdateResource::getUrl('view', ['record' => $record->telegram_update_id])
                        : null)
                    ->placeholder('—'),
                TextColumn::make('telegram_user_id')->label('Telegram user')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('idempotency_key')->label('Idempotency key')->toggleable(isToggledHiddenByDefault: true)->copyable(),
                TextColumn::make('error')->label(__('Ошибка'))->limit(50)->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')->label(__('Создано'))->dateTime('d.m.Y H:i:s')->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('tool')->label(__('Инструмент'))->options(
                    fn (): array => AiOperation::query()->distinct()->orderBy('tool')->pluck('tool', 'tool')->all()
                ),
                SelectFilter::make('status')->label(__('Статус'))->options([
                    'completed' => __('Завершено'),
                    'failed' => __('Ошибка'),
                ]),
            ])
            ->recordActions([
                ActionGroup::make([
                    ViewAction::make(),
                ]),
            ]);
    }
}
