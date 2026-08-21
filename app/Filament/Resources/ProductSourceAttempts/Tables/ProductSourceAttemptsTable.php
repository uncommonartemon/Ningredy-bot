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
            ->defaultSort('id', 'desc')
            ->poll(fn (): ?string => AiRun::query()->where('status', 'running')->exists() ? '15s' : null)
            ->columns([
                TextColumn::make('id')->label('#')->sortable(),
                TextColumn::make('created_at')->label('Время')->dateTime('d.m.Y H:i:s')->sortable(),
                TextColumn::make('domain')->label('Домен')->searchable()->sortable(),
                TextColumn::make('productDraft.id')
                    ->label('Черновик')
                    ->url(fn ($record): ?string => $record->product_draft_id
                        ? ProductDraftResource::getUrl('view', ['record' => $record->product_draft_id])
                        : null)
                    ->placeholder('—'),
                TextColumn::make('product_url')->label('Страница')->limit(55)->url(fn ($record): string => $record->product_url)->openUrlInNewTab()->copyable(),
                TextColumn::make('actor')->label('Исполнитель')->badge(),
                TextColumn::make('phase')->label('Этап')->badge(),
                TextColumn::make('action')->label('Действие')->searchable(),
                TextColumn::make('status')->label('Статус')->badge()->color(fn (string $state): string => match ($state) {
                    'completed', 'success' => 'success',
                    'partial', 'skipped' => 'warning',
                    'failed', 'blocked' => 'danger',
                    default => 'info',
                }),
                TextColumn::make('decision')->label('Решение')->placeholder('—')->toggleable(),
                TextColumn::make('round')->label('Раунд')->placeholder('—')->toggleable(),
                TextColumn::make('duration_ms')->label('мс')->numeric()->placeholder('—')->toggleable(),
                TextColumn::make('message')->label('Итог')->limit(80)->tooltip(fn ($record): ?string => $record->message),
            ])
            ->filters([
                SelectFilter::make('actor')->options([
                    'web_search' => 'Web Search',
                    'html' => 'HTML',
                    'html_resolver' => 'HTML resolver',
                    'downloader' => 'Downloader',
                    'ai' => 'AI',
                    'playwright' => 'Playwright',
                    'vision' => 'Vision',
                    'system' => 'Система',
                ]),
                SelectFilter::make('status')->options([
                    'completed' => 'Выполнено',
                    'success' => 'Успех',
                    'partial' => 'Частично',
                    'skipped' => 'Пропущено',
                    'failed' => 'Ошибка',
                    'blocked' => 'Заблокировано',
                ]),
                Filter::make('domain')
                    ->schema([TextInput::make('value')->label('Домен')])
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        filled($data['value'] ?? null),
                        fn (Builder $q) => $q->where('domain', $data['value']),
                    )),
                Filter::make('product_draft_id')
                    ->schema([TextInput::make('value')->label('ID черновика')])
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        filled($data['value'] ?? null),
                        fn (Builder $q) => $q->where('product_draft_id', $data['value']),
                    )),
            ])
            ->groups([
                TableGroup::make('product_draft_id')->label('Черновик'),
                TableGroup::make('domain')->label('Домен'),
                TableGroup::make('phase')->label('Этап'),
            ])
            ->recordActions([
                ActionGroup::make([
                    ViewAction::make(),
                ]),
            ]);
    }
}
