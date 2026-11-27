<?php

namespace App\Filament\Support;

use App\Models\ProductDraft;
use App\Models\ProductMedia;
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
        ])->addSelect(['product_media_count' => ProductMedia::query()->selectRaw('count(*)')
            ->whereColumn('product_media.product_id', 'product_drafts.approved_product_id')->where('type', 'image')]);
    }

    /**
     * Whether the card's photos now live in its product. Approval moves the draft's files to
     * the product (`ProductImageStorage::adoptStaged`), so the draft itself keeps none (live
     * report 2026-11-27: every approved draft read "0 photos", one of them with nine).
     */
    public static function photosInProduct(ProductDraft $draft): bool
    {
        return $draft->status === 'approved' && $draft->approved_product_id !== null;
    }

    /** The card's photos: the product's for an approved draft, the draft's own otherwise. */
    public static function photos(ProductDraft $draft): int
    {
        if (! self::photosInProduct($draft)) {
            return (int) ($draft->media_count ?? $draft->media()->count());
        }

        return (int) ($draft->product_media_count
            ?? ProductMedia::query()->where('product_id', $draft->approved_product_id)->where('type', 'image')->count());
    }

    /** The count, with where the photos are when they moved to the product. */
    public static function photosText(ProductDraft $draft): string
    {
        return self::photosInProduct($draft)
            ? __('в товаре #:id: :count', ['id' => $draft->approved_product_id, 'count' => self::photos($draft)])
            : (string) self::photos($draft);
    }

    public static function photosColumn(): TextColumn
    {
        return TextColumn::make('photos')->label(__('Фото'))->badge()
            ->state(fn (ProductDraft $record): string => self::photosText($record))
            ->color(fn (ProductDraft $record): string => self::photos($record) > 0 ? 'gray' : 'warning')
            ->tooltip(fn (ProductDraft $record): ?string => self::photosInProduct($record)
                ? __('Черновик подтверждён: его фото перенесены в товар.')
                : null)
            ->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderByRaw(
                '(case when product_drafts.status = \'approved\' and product_drafts.approved_product_id is not null'
                .' then (select count(*) from product_media where product_media.product_id = product_drafts.approved_product_id and product_media.type = \'image\')'
                .' else (select count(*) from product_draft_media where product_draft_media.product_draft_id = product_drafts.id) end) '
                .($direction === 'asc' ? 'asc' : 'desc')));
    }

    /**
     * Why the photo search stopped, in words that fit the outcome. Every source looked at
     * is the end of a search that found nine photos as much as of one that found none;
     * beside a gallery it is said neutrally, not as a failure.
     */
    public static function stopReason(ProductDraft $draft): string
    {
        $photos = self::photos($draft) > 0;

        return match ($draft->gallery_search_stop_reason) {
            'cost_budget' => __('Денежный лимит'),
            'stalled' => __('Остановлен досрочно'),
            'time_budget' => __('Временной лимит'),
            'exhausted' => $photos ? __('Все найденные источники просмотрены') : __('Источники исчерпаны'),
            'specifications_unreconciled' => __('Характеристики не сверены'),
            default => '—',
        };
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
