<?php

namespace App\Filament\Resources\ProductDrafts\Tables;

use App\Models\ProductDraft;
use App\Services\Products\ProductDraftWorkflow;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ProductDraftsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('id')->label('#')->sortable(),
                TextColumn::make('title')->label('Название')->searchable()->sortable()->wrap(),
                TextColumn::make('brand')->label('Бренд')->searchable(),
                TextColumn::make('model')->label('Модель')->searchable()->toggleable(),
                TextColumn::make('color')->label('Цвет')->toggleable(),
                TextColumn::make('status')->label('Статус')->badge()->formatStateUsing(fn (string $state): string => match ($state) {
                    'approved' => 'Подтверждён',
                    'rejected' => 'Отклонён',
                    default => 'Ожидает проверки',
                })->color(fn (string $state): string => match ($state) {
                    'approved' => 'success',
                    'rejected' => 'danger',
                    default => 'warning',
                }),
                TextColumn::make('confidence')->label('AI')->numeric(decimalPlaces: 2)->sortable(),
                TextColumn::make('requested_by_telegram_user_id')->label('Telegram user')->toggleable(),
                TextColumn::make('product.id')->label('Товар')->formatStateUsing(fn ($state): string => $state ? "#{$state}" : '—'),
                TextColumn::make('created_at')->label('Создан')->dateTime('d.m.Y H:i')->sortable(),
                TextColumn::make('reviewed_at')->label('Проверен')->dateTime('d.m.Y H:i')->sortable()->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('status')->label('Статус')->options([
                    'pending_review' => 'Ожидает проверки',
                    'approved' => 'Подтверждён',
                    'rejected' => 'Отклонён',
                ]),
            ])
            ->recordActions([
                Action::make('approve')
                    ->label('Апрув')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (ProductDraft $record): bool => $record->status === 'pending_review')
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
                EditAction::make(),
            ]);
    }
}
