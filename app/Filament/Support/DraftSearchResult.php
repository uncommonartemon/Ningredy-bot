<?php

namespace App\Filament\Support;

use App\Models\ProductDraft;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Database\Eloquent\Builder;

/** Card facts, independent of browser executions and Telegram delivery. */
final class DraftSearchResult
{
    public static function withSummary(Builder $query): Builder
    {
        return $query->with('telegramUpdate')->withCount([
            'media',
            'media as pending_media_count' => fn (Builder $media) => $media->where('verification_status', 'pending'),
        ]);
    }

    public static function column(): TextColumn
    {
        return TextColumn::make('search_result')->label(__('Итог карточки'))->badge()
            ->state(fn (ProductDraft $record): string => self::state($record))
            ->formatStateUsing(fn (string $state): string => match ($state) {
                'approved' => __('Подтверждён'),
                'rejected' => __('Отклонён'),
                'needs_input' => __('Нужны уточнения'),
                'unreconciled' => __('Сборка не завершена'),
                'pending_verification' => __('Фото не проверены'),
                'no_photos' => __('Без фото'),
                'manual' => __('Материалы пользователя'),
                'complete' => __('Полная галерея'),
                default => __('Частичная галерея'),
            })
            ->color(fn (string $state): string => match ($state) {
                'approved', 'complete' => 'success',
                'manual' => 'info',
                'rejected' => 'gray',
                default => 'warning',
            })
            ->tooltip(__('Состояние карточки по сохранённым данным, фото и сверке характеристик. Не подтверждает доставку в Telegram.'));
    }

    public static function state(ProductDraft $draft): string
    {
        if (in_array($draft->status, ['approved', 'rejected'], true)) {
            return $draft->status;
        }

        if ($draft->isManualUpload() && ($draft->manualIntake()['status'] ?? null) !== 'ready') {
            return 'needs_input';
        }

        if ($draft->reconciliationPending()) {
            return 'unreconciled';
        }

        if ((int) $draft->media_count === 0) {
            return 'no_photos';
        }

        if ((int) $draft->pending_media_count > 0) {
            return 'pending_verification';
        }

        if ($draft->isManualUpload()) {
            return 'manual';
        }

        return $draft->gallery_status === 'complete' ? 'complete' : 'partial';
    }
}
