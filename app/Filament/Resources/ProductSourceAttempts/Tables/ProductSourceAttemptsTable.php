<?php

namespace App\Filament\Resources\ProductSourceAttempts\Tables;

use App\Filament\Resources\ProductDrafts\ProductDraftResource;
use App\Models\AiRun;
use Filament\Actions\ActionGroup;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\TextInput;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Grouping\Group as TableGroup;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ProductSourceAttemptsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            // A journal grows without bound: "all" rows no longer fit in memory.
            ->paginationPageOptions([25, 50, 100])
            ->defaultSort('id', 'desc')
            ->poll(fn (): ?string => AiRun::query()->where('status', 'running')->exists() ? '15s' : null)
            ->columns([
                TextColumn::make('id')->label('#')->sortable(),
                TextColumn::make('created_at')->label(__('Время'))->dateTime('d.m.Y H:i:s')->sortable(),
                TextColumn::make('domain')->label(__('Домен'))->searchable()->sortable(),
                TextColumn::make('productDraft.id')
                    ->label(__('Черновик'))
                    ->url(fn ($record): ?string => $record->product_draft_id
                        ? ProductDraftResource::getUrl('view', ['record' => $record->product_draft_id])
                        : null)
                    ->placeholder('—'),
                TextColumn::make('product_url')->label(__('Страница'))->limit(55)->url(fn ($record): string => $record->product_url)->openUrlInNewTab()->copyable(),
                TextColumn::make('actor')->label(__('Исполнитель'))->badge(),
                TextColumn::make('phase')->label(__('Этап'))->badge(),
                TextColumn::make('action')->label(__('Действие'))->searchable(),
                TextColumn::make('status')->label(__('Статус'))->badge()->color(fn (string $state): string => match ($state) {
                    'completed', 'success', 'complete' => 'success',
                    'partial', 'skipped' => 'warning',
                    'failed', 'blocked', 'interrupted' => 'danger',
                    default => 'info',
                }),
                TextColumn::make('decision')->label(__('Решение'))->placeholder('—')->toggleable(),
                TextColumn::make('round')->label(__('Раунд'))->placeholder('—')->toggleable(),
                TextColumn::make('duration_ms')->label(__('мс'))->numeric()->placeholder('—')->toggleable(),
                TextColumn::make('message')->label(__('Итог'))->limit(80)->tooltip(fn ($record): ?string => $record->message),
                TextColumn::make('telegramUpdate.id')
                    ->label(__('Запрос #'))
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('recipeVersion.id')
                    ->label(__('Версия рецепта'))
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('actor')->options([
                    'server' => __('Сервер'),
                    'web_search' => 'Web Search',
                    'html' => 'HTML',
                    'html_resolver' => 'HTML resolver',
                    'downloader' => 'Downloader',
                    'ai' => 'AI',
                    'playwright' => 'Playwright',
                    'vision' => 'Vision',
                    'system' => __('Система'),
                ]),
                SelectFilter::make('status')->options([
                    'running' => __('В процессе'),
                    'interrupted' => __('Прервано'),
                    'complete' => __('Полный результат'),
                    'completed' => __('Выполнено'),
                    'success' => __('Успех'),
                    'partial' => __('Частично'),
                    'skipped' => __('Пропущено'),
                    'failed' => __('Ошибка'),
                    'blocked' => __('Заблокировано'),
                ]),
                Filter::make('telegram_update_id')
                    ->schema([TextInput::make('value')->label(__('ID запроса Telegram'))->numeric()])
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        filled($data['value'] ?? null),
                        fn (Builder $q) => $q->where('telegram_update_id', $data['value']),
                    )),
                Filter::make('domain')
                    ->schema([TextInput::make('value')->label(__('Домен'))])
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        filled($data['value'] ?? null),
                        fn (Builder $q) => $q->where('domain', $data['value']),
                    )),
                Filter::make('product_draft_id')
                    ->schema([TextInput::make('value')->label(__('ID черновика'))])
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        filled($data['value'] ?? null),
                        fn (Builder $q) => $q->where('product_draft_id', $data['value']),
                    )),
            ])
            ->groups([
                TableGroup::make('product_draft_id')->label(__('Черновик')),
                TableGroup::make('domain')->label(__('Домен')),
                TableGroup::make('phase')->label(__('Этап')),
            ])
            ->recordActions([
                ActionGroup::make([
                    ViewAction::make(),
                ]),
            ]);
    }
}
