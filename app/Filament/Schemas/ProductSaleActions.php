<?php

namespace App\Filament\Schemas;

use App\Filament\Resources\Products\ProductResource;
use App\Models\Product;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;

/**
 * Two different ways out of the shop's window (operator, 2026-11-11: «можно закинуть в сон или
 * удалить, две разные кнопки… повторы будут однозначно»). Taken off sale, a product sleeps:
 * the storefront no longer shows it and nothing of it is lost, because the same product comes
 * in again. Deleting is for a card that was a mistake, and it says so before it happens.
 */
final class ProductSaleActions
{
    public static function takeOff(): Action
    {
        return Action::make('takeOffSale')
            ->label(__('Снять с продажи'))
            ->icon(Heroicon::OutlinedEyeSlash)
            ->color('gray')
            ->authorize(fn (Product $record): bool => ProductResource::canEdit($record))
            ->visible(fn (Product $record): bool => $record->isOnSale())
            ->action(function (Product $record, Action $action): void {
                $record->takeOffSale();
                $action->success();
            })
            ->successNotificationTitle(__('Товар снят с продажи: на сайте его нет, карточка сохранена'));
    }

    public static function delete(DeleteAction $action): DeleteAction
    {
        return $action
            ->modalHeading(__('Удалить товар навсегда?'))
            ->modalDescription(__('Удалятся карточка, конфигурации, характеристики и фото. Продажи останутся в учёте. Если такой товар может появиться снова, лучше снять его с продажи: карточка сохранится и не придётся искать заново.'))
            ->modalSubmitActionLabel(__('Удалить навсегда'));
    }

    /** For a selection: after a clear-out, everything sold out goes to sleep at once. */
    public static function bulk(): BulkActionGroup
    {
        return BulkActionGroup::make([
            BulkAction::make('takeOffSale')
                ->label(__('Снять с продажи'))
                ->icon(Heroicon::OutlinedEyeSlash)
                ->action(fn (Collection $records) => $records->each(fn (Product $product) => $product->takeOffSale()))
                ->deselectRecordsAfterCompletion()
                ->successNotificationTitle(__('Товары сняты с продажи: карточки сохранены')),
            BulkAction::make('putBackOnSale')
                ->label(__('Вернуть в продажу'))
                ->icon(Heroicon::OutlinedEye)
                ->action(fn (Collection $records) => $records->each(fn (Product $product) => $product->putBackOnSale()))
                ->deselectRecordsAfterCompletion()
                ->successNotificationTitle(__('Товары снова в продаже')),
        ]);
    }
}
