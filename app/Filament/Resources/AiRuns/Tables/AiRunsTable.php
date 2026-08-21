<?php

namespace App\Filament\Resources\AiRuns\Tables;

use App\Filament\Resources\TelegramUpdates\TelegramUpdateResource;
use App\Models\AiRun;
use Carbon\CarbonInterface;
use Filament\Actions\ActionGroup;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class AiRunsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('started_at', 'desc')
            ->poll(fn (): ?string => AiRun::query()->where('status', 'running')->exists() ? '15s' : null)
            ->columns([
                TextColumn::make('id')->label('#')->sortable(),
                TextColumn::make('telegramUpdate.id')
                    ->label('Telegram update')
                    ->url(fn (AiRun $record): ?string => $record->telegram_update_id
                        ? TelegramUpdateResource::getUrl('view', ['record' => $record->telegram_update_id])
                        : null)
                    ->searchable(),
                TextColumn::make('invocation_id')->label('Invocation ID')->copyable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('provider')->label('Провайдер')->badge(),
                TextColumn::make('model')->label('Модель')->searchable(),
                TextColumn::make('status')
                    ->label('Статус')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'completed' => 'Завершён',
                        'running' => 'Выполняется',
                        'failed' => 'Ошибка',
                        default => $state,
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'completed' => 'success',
                        'running' => 'warning',
                        'failed' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('duration')
                    ->label('Длительность')
                    ->state(fn (AiRun $record): ?string => self::duration($record))
                    ->placeholder('—'),
                TextColumn::make('started_at')->label('Начат')->dateTime('d.m.Y H:i:s')->sortable(),
                TextColumn::make('completed_at')->label('Завершён')->dateTime('d.m.Y H:i:s')->sortable()->toggleable(),
                TextColumn::make('created_at')->label('Создан')->dateTime('d.m.Y H:i:s')->sortable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')->label('Изменён')->dateTime('d.m.Y H:i:s')->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')->label('Статус')->options([
                    'completed' => 'Завершён',
                    'running' => 'Выполняется',
                    'failed' => 'Ошибка',
                ]),
                Filter::make('started_at')
                    ->schema([
                        DatePicker::make('from')->label('С'),
                        DatePicker::make('until')->label('По'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $q, $date) => $q->whereDate('started_at', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $q, $date) => $q->whereDate('started_at', '<=', $date))),
            ])
            ->recordActions([
                ActionGroup::make([
                    ViewAction::make(),
                ]),
            ]);
    }

    private static function duration(AiRun $record): ?string
    {
        if (! $record->started_at) {
            return null;
        }

        $end = $record->completed_at ?: now();

        return $record->started_at->diffForHumans($end, syntax: CarbonInterface::DIFF_ABSOLUTE, short: true);
    }
}
