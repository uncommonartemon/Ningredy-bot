<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\ProductDrafts\ProductDraftResource;
use App\Filament\Support\DraftSearchResult;
use App\Models\ProductDraft;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class RecentSearchesWidget extends TableWidget
{
    protected static ?string $heading = 'Последние карточки';

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->heading(__('Последние карточки'))
            ->description(__('Созданные черновики. Запросы, завершившиеся до создания карточки, доступны в журнале Telegram.'))
            ->query(DraftSearchResult::withSummary(ProductDraft::query())->with('aiRun')->latest('id'))
            ->columns([
                TextColumn::make('title')->label(__('Запрос'))->searchable()->wrap()->limit(50),
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
                TextColumn::make('media_count')->label(__('Фото'))->badge(),
                TextColumn::make('primary_source_url')
                    ->label(__('Источник'))
                    ->formatStateUsing(fn (?string $state): string => $state ? (parse_url($state, PHP_URL_HOST) ?: $state) : '—'),
                TextColumn::make('cost')
                    ->label(__('Стоимость'))
                    ->state(fn (ProductDraft $record): ?string => self::cost($record))
                    ->placeholder('—'),
                TextColumn::make('created_at')->label(__('Время'))->since()->sortable(),
            ])
            ->recordActions([
                ViewAction::make()
                    ->url(fn (ProductDraft $record): string => ProductDraftResource::getUrl('view', ['record' => $record])),
            ]);
    }

    private static function cost(ProductDraft $record): ?string
    {
        $usage = $record->aiRun?->usage;

        if (! is_array($usage)) {
            return null;
        }

        $cost = $usage['cost_usd'] ?? $usage['total_cost_usd'] ?? null;
        $tokens = $usage['total_tokens'] ?? $usage['tokens'] ?? null;

        if ($cost === null && $tokens === null) {
            return null;
        }

        return trim(
            ($tokens !== null ? $tokens.__(' ток.') : '').
            ($cost !== null ? ' (~$'.number_format((float) $cost, 3).')' : '')
        );
    }
}
