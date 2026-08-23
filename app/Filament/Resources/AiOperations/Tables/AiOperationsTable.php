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
            ->defaultSort('executed_at', 'desc')
            ->columns([
                TextColumn::make('id')->label('#')->sortable(),
                TextColumn::make('executed_at')->label('Выполнено')->dateTime('d.m.Y H:i:s')->sortable(),
                TextColumn::make('tool')->label('Инструмент')->badge(),
                TextColumn::make('action')->label('Действие')->searchable(),
                TextColumn::make('target')
                    ->label('Цель')
                    ->state(fn (AiOperation $record): ?string => $record->target_type
                        ? class_basename($record->target_type).' #'.$record->target_id
                        : null)
                    ->placeholder('—'),
                TextColumn::make('status')
                    ->label('Статус')
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
                TextColumn::make('error')->label('Ошибка')->limit(50)->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')->label('Создано')->dateTime('d.m.Y H:i:s')->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('tool')->label('Инструмент')->options(
                    fn (): array => AiOperation::query()->distinct()->orderBy('tool')->pluck('tool', 'tool')->all()
                ),
                SelectFilter::make('status')->label('Статус')->options([
                    'completed' => 'Завершено',
                    'failed' => 'Ошибка',
                ]),
            ])
            ->recordActions([
                ActionGroup::make([
                    ViewAction::make(),
                ]),
            ]);
    }
}
