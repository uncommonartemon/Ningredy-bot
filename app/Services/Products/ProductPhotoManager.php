<?php

namespace App\Services\Products;

use App\Jobs\RefindCatalogPhotos;
use App\Jobs\StoreProductImages;
use App\Models\Product;
use App\Models\ProductDraft;
use App\Models\TelegramUpdate;
use App\Services\Ai\ProductSearchCancellation;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Post-publish photo editing: reorder, delete, or replace/refind specific
 * gallery photos by their 1-based display position (what the admin sees
 * and refers to in chat - "swap 1 and 3"), not raw database IDs.
 */
class ProductPhotoManager
{
    /** @param array<int, int> $newOrder 1-based positions in the desired new order, e.g. [3,2,1] */
    public function reorder(Product $product, array $newOrder, ?TelegramUpdate $update = null, ?int $variantId = null): void
    {
        DB::transaction(function () use ($product, $newOrder, $update, $variantId): void {
            $locked = Product::query()->lockForUpdate()->findOrFail($product->id);
            app(ProductSearchCancellation::class)->throwIfCancelled($update?->id);
            $this->doReorder($locked, $newOrder, $update, $variantId);
        });
    }

    private function doReorder(Product $product, array $newOrder, ?TelegramUpdate $update, ?int $variantId): void
    {
        [$variant, $media] = app(CatalogPhotoSelection::class)->forAction($product, $update, $variantId);
        $count = $media->count();

        if ($count === 0) {
            throw new RuntimeException('This product has no photos to reorder.');
        }

        if (! $this->isValidPermutation($newOrder, $count)) {
            throw new RuntimeException("new_order must list each position from 1 to {$count} exactly once.");
        }

        $ordered = collect($newOrder)->map(fn ($position) => $media->get($position - 1))
            ->concat(app(CatalogPhotoSelection::class)->gallery($product, $variant)->whereNotIn('id', $media->pluck('id')));
        foreach ($ordered->values() as $index => $image) {
            $image->update([
                'sort_order' => $index,
                'is_primary' => $index === 0,
                'role' => $this->roleFor($index),
            ]);
        }
    }

    /**
     * @param  array<int, int>  $positions  1-based
     * @return int how many photos were actually deleted
     */
    public function delete(Product $product, array $positions, ?TelegramUpdate $update = null, ?int $variantId = null): int
    {
        return DB::transaction(function () use ($product, $positions, $update, $variantId): int {
            $locked = Product::query()->lockForUpdate()->findOrFail($product->id);
            app(ProductSearchCancellation::class)->throwIfCancelled($update?->id);

            return $this->doDelete($locked, $positions, $update, $variantId);
        });
    }

    private function doDelete(Product $product, array $positions, ?TelegramUpdate $update, ?int $variantId): int
    {
        [$variant, $media] = app(CatalogPhotoSelection::class)->forAction($product, $update, $variantId);
        $toDelete = collect($positions)->unique()->map(fn (int $position) => $media->get($position - 1))->filter();

        foreach ($toDelete as $item) {
            $item->delete();
        }

        $remaining = app(CatalogPhotoSelection::class)->gallery($product, $variant)->whereNotIn('id', $toDelete->pluck('id'));
        // Deleting the last own image must not rewrite the global fallback gallery.
        $scope = $media->first()?->product_variant_id;
        foreach ($remaining->where('product_variant_id', $scope)->values() as $index => $item) {
            $item->update(['sort_order' => $index, 'is_primary' => $index === 0, 'role' => $this->roleFor($index)]);
        }

        return $toDelete->count();
    }

    /**
     * Delete the given positions (or everything, if $fresh) and dispatch a
     * fresh search to fill the gallery back up to its target count.
     *
     * @param  array<int, int>  $replacePositions  1-based
     */
    public function refind(Product $product, array $replacePositions = [], bool $fresh = false, ?TelegramUpdate $update = null, ?int $variantId = null): bool
    {
        [$variant, $media, $fingerprint] = app(CatalogPhotoSelection::class)->forAction($product, $update, $variantId);
        $replaceMediaIds = $fresh
            ? $media->pluck('id')->all()
            : collect($replacePositions)
                ->unique()
                ->map(fn (int $position): ?int => $media->get($position - 1)?->id)
                ->filter()
                ->values()
                ->all();

        $draft = ProductDraft::query()->where('approved_product_id', $product->id)
            ->where('approved_variant_id', $variant->id)->where('status', 'approved')->latest('id')->first();

        if (! $draft || ! $variant) {
            return false;
        }

        if ($update) {
            RefindCatalogPhotos::dispatch($product->id, $variant->id, $draft->id, $update->id,
                $fingerprint, $replaceMediaIds)->afterCommit();
        } else {
            StoreProductImages::dispatch($product->id, $variant->id, $draft->id, $replaceMediaIds)->afterCommit();
        }

        return true;
    }

    /**
     * Remove only as many requested old photos as were successfully replaced.
     * If discovery finds nothing, the existing gallery remains untouched.
     *
     * @param  array<int, int>  $replaceMediaIds
     */
    public function completeRefind(Product $product, array $replaceMediaIds, int $stored, ?int $variantId = null): int
    {
        if ($stored <= 0 || $replaceMediaIds === []) {
            return 0;
        }

        $toDelete = $product->media()
            ->when($variantId !== null, fn ($query) => $query->where('product_variant_id', $variantId))
            ->whereIn('id', $replaceMediaIds)
            ->orderBy('sort_order')
            ->limit($stored)
            ->get();

        foreach ($toDelete as $item) {
            $item->delete();
        }

        if ($variantId !== null) {
            $this->renumber($product, $variantId);
        }

        return $toDelete->count();
    }

    private function renumber(Product $product, int $variantId): void
    {
        $variant = app(CatalogPhotoSelection::class)->variant($product, $variantId);
        foreach (app(CatalogPhotoSelection::class)->gallery($product, $variant)->values() as $index => $item) {
            $item->update([
                'sort_order' => $index,
                'is_primary' => $index === 0,
                'role' => $this->roleFor($index),
            ]);
        }
    }

    private function roleFor(int $index): string
    {
        return match ($index) {
            0 => 'primary',
            1 => 'secondary',
            default => 'detail',
        };
    }

    /** @param array<int, int> $order */
    private function isValidPermutation(array $order, int $count): bool
    {
        sort($order);

        return $order === range(1, $count);
    }
}
