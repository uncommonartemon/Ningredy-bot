<?php

namespace App\Filament\Resources\ProductSourceAttempts\Tables;

use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ProductSourceAttemptsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('id')->label('#')->sortable(),
                TextColumn::make('created_at')->label('Время')->dateTime('d.m.Y H:i:s')->sortable(),
                TextColumn::make('domain')->label('Домен')->searchable()->sortable(),
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
            ])
            ->recordActions([ViewAction::make()]);
    }
}
