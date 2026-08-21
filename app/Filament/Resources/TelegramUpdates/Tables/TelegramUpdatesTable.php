<?php

namespace App\Filament\Resources\TelegramUpdates\Tables;

use App\Filament\Resources\ProductDrafts\ProductDraftResource;
use App\Models\TelegramUpdate;
use Carbon\CarbonInterface;
use Filament\Actions\ActionGroup;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class TelegramUpdatesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->withCount('productDrafts'))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('update_id')->label('#')->numeric()->sortable(),
                TextColumn::make('chat_id')->label('Chat ID')->searchable()->copyable(),
                TextColumn::make('telegram_user_id')->label('Telegram user')->searchable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('message_id')->label('Message ID')->numeric()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('username')->label('Пользователь')->searchable()->placeholder('—'),
                TextColumn::make('text')->label('Текст')->limit(50)->tooltip(fn (TelegramUpdate $record): ?string => $record->text)->placeholder('—'),
                TextColumn::make('status')
                    ->label('Статус')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'processed' => 'Обработано',
                        'received' => 'Получено',
                        'failed' => 'Ошибка',
                        'cancelled' => 'Отменено',
                        default => $state,
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'processed' => 'success',
                        'received' => 'gray',
                        'failed' => 'danger',
                        'cancelled' => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('duration')
                    ->label('Обработка')
                    ->state(fn (TelegramUpdate $record): ?string => $record->processed_at
                        ? $record->created_at->diffForHumans($record->processed_at, syntax: CarbonInterface::DIFF_ABSOLUTE, short: true)
                        : null)
                    ->placeholder('—'),
                TextColumn::make('product_drafts_count')
                    ->label('Черновики')
                    ->badge()
                    ->url(fn (TelegramUpdate $record): ?string => $record->product_drafts_count > 0
                        ? ProductDraftResource::getUrl('index', ['tableFilters' => ['telegram_update_id' => ['value' => $record->id]]])
                        : null),
                TextColumn::make('created_at')->label('Получено')->dateTime('d.m.Y H:i:s')->sortable(),
                TextColumn::make('processed_at')->label('Обработано в')->dateTime('d.m.Y H:i:s')->sortable()->placeholder('—')->toggleable(),
                TextColumn::make('cancel_requested_at')->label('Запрошена отмена')->dateTime('d.m.Y H:i:s')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('error')->label('Ошибка')->limit(50)->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')->label('Статус')->options([
                    'processed' => 'Обработано',
                    'received' => 'Получено',
                    'failed' => 'Ошибка',
                    'cancelled' => 'Отменено',
                ]),
                Filter::make('chat_id')
                    ->schema([
                        TextInput::make('value')->label('Chat ID'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        filled($data['value'] ?? null),
                        fn (Builder $q) => $q->where('chat_id', $data['value']),
                    )),
                Filter::make('created_at')
                    ->schema([
                        DatePicker::make('from')->label('С'),
                        DatePicker::make('until')->label('По'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $q, $date) => $q->whereDate('created_at', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $q, $date) => $q->whereDate('created_at', '<=', $date))),
            ])
            ->recordActions([
                ActionGroup::make([
                    ViewAction::make(),
                ]),
            ]);
    }
}
