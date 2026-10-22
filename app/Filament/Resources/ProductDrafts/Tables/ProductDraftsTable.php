<?php

namespace App\Filament\Resources\ProductDrafts\Tables;

use App\Filament\Support\DraftSearchResult;
use App\Models\ProductDraft;
use App\Services\Products\ProductDraftWorkflow;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ProductDraftsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => DraftSearchResult::withSummary($query)->with('media'))
            ->defaultSort('id', 'desc')
            ->columns([
                ImageColumn::make('preview')
                    ->label(__('Фото'))
                    ->state(fn (ProductDraft $record): ?string => $record->media->first()?->path)
                    ->disk('public')
                    ->square(),
                TextColumn::make('id')->label('#')->sortable(),
                TextColumn::make('title')->label(__('Название'))->searchable()->sortable()->wrap(),
                TextColumn::make('brand')->label(__('Бренд'))->searchable(),
                TextColumn::make('model')->label(__('Модель'))->searchable()->toggleable(),
                TextColumn::make('color')->label(__('Цвет'))->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('product_type')->label(__('Тип'))->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('category')->label(__('Категория'))->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('status')->label(__('Статус'))->badge()->formatStateUsing(fn (string $state): string => match ($state) {
                    'approved' => __('Подтверждён'),
                    'rejected' => __('Отклонён'),
                    default => __('Ожидает проверки'),
                })->color(fn (string $state): string => match ($state) {
                    'approved' => 'success',
                    'rejected' => 'danger',
                    default => 'warning',
                }),
                TextColumn::make('media_count')->label(__('Фото'))->badge()->sortable(),
                DraftSearchResult::column(),
                TextColumn::make('gallery_status')
                    ->label(__('Галерея'))
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        'complete' => __('Полная'),
                        'partial' => __('Частичная'),
                        'missing' => __('Не найдена'),
                        default => __('Ожидание'),
                    })
                    ->color(fn (?string $state): string => match ($state) {
                        'complete' => 'success',
                        'partial' => 'warning',
                        'missing' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('gallery_search_stop_reason')
                    ->label(__('Причина остановки'))
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        'cost_budget' => __('Денежный лимит'),
                        'stalled' => __('Остановлен досрочно'),
                        'time_budget' => __('Временной лимит'),
                        'exhausted' => __('Источники исчерпаны'),
                        'specifications_unreconciled' => __('Характеристики не сверены'),
                        default => '—',
                    })
                    ->toggleable(),
                TextColumn::make('primary_source_url')
                    ->label(__('Источник'))
                    ->formatStateUsing(fn (?string $state): string => $state ? (parse_url($state, PHP_URL_HOST) ?: $state) : '—')
                    ->toggleable(),
                TextColumn::make('confidence')->label('AI')->numeric(decimalPlaces: 2)->sortable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('requested_by_telegram_user_id')->label('Telegram user')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('product.id')->label(__('Товар'))->formatStateUsing(fn ($state): string => $state ? "#{$state}" : '—')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')->label(__('Создан'))->dateTime('d.m.Y H:i')->sortable(),
                TextColumn::make('reviewed_at')->label(__('Проверен'))->dateTime('d.m.Y H:i')->sortable()->placeholder('—')->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('Статус'))->options([
                    'pending_review' => __('Ожидает проверки'),
                    'approved' => __('Подтверждён'),
                    'rejected' => __('Отклонён'),
                ]),
                Filter::make('telegram_update_id')
                    ->schema([TextInput::make('value')->label('Telegram update ID')])
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        filled($data['value'] ?? null),
                        fn (Builder $q) => $q->where('telegram_update_id', $data['value']),
                    )),
            ])
            ->recordActions([
                Action::make('approve')
                    ->label(__('Апрув'))
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (ProductDraft $record): bool => $record->status === 'pending_review')
                    ->disabled(fn (ProductDraft $record): bool => ! $record->media()->exists()
                        || self::specificationsUnreconciled($record))
                    ->tooltip(function (ProductDraft $record): ?string {
                        if (! $record->media()->exists()) {
                            return __('Нельзя опубликовать черновик без пригодных фото.');
                        }

                        return self::specificationsUnreconciled($record)
                            ? __('Характеристики ещё не сверены с источником выбранных фото.')
                            : null;
                    })
                    ->action(function (ProductDraft $record): void {
                        try {
                            app(ProductDraftWorkflow::class)->approve($record, auth()->user());
                        } catch (\RuntimeException $exception) {
                            Notification::make()->title(__('Не удалось подтвердить черновик'))->body($exception->getMessage())->warning()->send();

                            return;
                        }
                        Notification::make()->title(__('Черновик опубликован как товар'))->success()->send();
                    }),
                Action::make('reject')
                    ->label(__('Отклонить'))
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->schema([
                        Textarea::make('reason')->label(__('Причина'))->rows(3),
                    ])
                    ->visible(fn (ProductDraft $record): bool => $record->status === 'pending_review')
                    ->action(function (ProductDraft $record, array $data): void {
                        try {
                            app(ProductDraftWorkflow::class)->reject($record, auth()->user(), reason: $data['reason'] ?? null);
                        } catch (\RuntimeException $exception) {
                            Notification::make()->title(__('Черновик уже обработан'))->body($exception->getMessage())->warning()->send();

                            return;
                        }
                        Notification::make()->title(__('Черновик отклонён'))->danger()->send();
                    }),
                ViewAction::make(),
                ActionGroup::make([
                    EditAction::make(),
                ]),
            ]);
    }

    /**
     * Mirrors the same two-column comparison ProductDraftWorkflow::approve()
     * itself enforces (see UnreconciledDraftSpecificationsException) - kept
     * here only to disable the button up front with a helpful tooltip
     * instead of the operator discovering it via a thrown exception.
     */
    private static function specificationsUnreconciled(ProductDraft $record): bool
    {
        return $record->reconciliationPending();
    }
}
