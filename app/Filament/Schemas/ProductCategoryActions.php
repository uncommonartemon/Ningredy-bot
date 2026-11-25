<?php

namespace App\Filament\Schemas;

use App\Models\Category;
use App\Models\Product;
use App\Services\Catalog\CatalogMap;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\Select;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Putting the catalog in order (decision of 2026-11-25): products that sit in a node with kinds
 * are found by one tab and moved by one action, to any node of the catalog map.
 */
final class ProductCategoryActions
{
    /** Products placed directly in a node that has kinds: their kind is still to be chosen. */
    public static function awaitingKind(Builder $query): Builder
    {
        return $query->whereIn('category_id', array_column(app(CatalogMap::class)->nodesWithKinds(), 'id'));
    }

    public static function bulkMove(): BulkAction
    {
        return BulkAction::make('moveToCategory')
            ->label(__('Перенести в категорию'))
            ->icon(Heroicon::OutlinedFolderOpen)
            ->modalDescription(__('Товары встанут в выбранную категорию; тип товара станет её типом. Характеристики и фото не меняются.'))
            ->schema([
                Select::make('category_id')->label(__('Категория'))->required()->searchable()
                    ->options(fn (): array => app(CatalogMap::class)->options()),
            ])
            ->action(function (Collection $records, array $data): void {
                $category = Category::query()->findOrFail((int) $data['category_id']);
                $records->each(fn (Product $product) => $product->moveToCategory($category));
            })
            ->deselectRecordsAfterCompletion()
            ->successNotificationTitle(__('Товары перенесены'));
    }
}
