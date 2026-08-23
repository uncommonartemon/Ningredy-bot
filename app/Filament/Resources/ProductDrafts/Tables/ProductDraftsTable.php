<?php

namespace App\Filament\Resources\ProductDrafts\Tables;

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
            ->modifyQueryUsing(fn ($query) => $query->with('media')->withCount('media'))
            ->defaultSort('id', 'desc')
            ->columns([
                ImageColumn::make('preview')
                    ->label('Фото')
                    ->state(fn (ProductDraft $record): ?string => $record->media->first()?->path)
                    ->disk('public')
                    ->square(),
                TextColumn::make('id')->label('#')->sortable(),
                TextColumn::make('title')->label('Название')->searchable()->sortable()->wrap(),
                TextColumn::make('brand')->label('Бренд')->searchable(),
                TextColumn::make('model')->label('Модель')->searchable()->toggleable(),
                TextColumn::make('color')->label('Цвет')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('product_type')->label('Тип')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('category')->label('Категория')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('status')->label('Статус')->badge()->formatStateUsing(fn (string $state): string => match ($state) {
                    'approved' => 'Подтверждён',
                    'rejected' => 'Отклонён',
                    default => 'Ожидает проверки',
                })->color(fn (string $state): string => match ($state) {
                    'approved' => 'success',
                    'rejected' => 'danger',
                    default => 'warning',
                }),
                TextColumn::make('media_count')->label('Фото')->badge()->sortable(),
                TextColumn::make('gallery_status')
                    ->label('Галерея')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        'complete' => 'Полная',
                        'partial' => 'Частичная',
                        'missing' => 'Не найдена',
                        default => 'Ожидание',
                    })
                    ->color(fn (?string $state): string => match ($state) {
                        'complete' => 'success',
                        'partial' => 'warning',
                        'missing' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('gallery_search_stop_reason')
                    ->label('Причина остановки')
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        'cost_budget' => 'Денежный лимит',
                        'time_budget' => 'Временной лимит',
                        'exhausted' => 'Источники исчерпаны',
                        default => '—',
                    })
                    ->toggleable(),
                TextColumn::make('primary_source_url')
                    ->label('Источник')
                    ->formatStateUsing(fn (?string $state): string => $state ? (parse_url($state, PHP_URL_HOST) ?: $state) : '—')
                    ->toggleable(),
                TextColumn::make('confidence')->label('AI')->numeric(decimalPlaces: 2)->sortable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('requested_by_telegram_user_id')->label('Telegram user')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('product.id')->label('Товар')->formatStateUsing(fn ($state): string => $state ? "#{$state}" : '—')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')->label('Создан')->dateTime('d.m.Y H:i')->sortable(),
                TextColumn::make('reviewed_at')->label('Проверен')->dateTime('d.m.Y H:i')->sortable()->placeholder('—')->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')->label('Статус')->options([
                    'pending_review' => 'Ожидает проверки',
                    'approved' => 'Подтверждён',
                    'rejected' => 'Отклонён',
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
                    ->label('Апрув')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (ProductDraft $record): bool => $record->status === 'pending_review')
                    ->disabled(fn (ProductDraft $record): bool => ! $record->media()->exists())
                    ->tooltip(fn (ProductDraft $record): ?string => $record->media()->exists()
                        ? null
                        : 'Нельзя опубликовать черновик без пригодных фото.')
                    ->action(function (ProductDraft $record): void {
                        app(ProductDraftWorkflow::class)->approve($record, auth()->user());
                        Notification::make()->title('Черновик опубликован как товар')->success()->send();
                    }),
                Action::make('reject')
                    ->label('Отклонить')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->schema([
                        Textarea::make('reason')->label('Причина')->rows(3),
                    ])
                    ->visible(fn (ProductDraft $record): bool => $record->status === 'pending_review')
                    ->action(function (ProductDraft $record, array $data): void {
                        app(ProductDraftWorkflow::class)->reject($record, auth()->user(), reason: $data['reason'] ?? null);
                        Notification::make()->title('Черновик отклонён')->danger()->send();
                    }),
                ViewAction::make(),
                ActionGroup::make([
                    EditAction::make(),
                ]),
            ]);
    }
}
