<?php

namespace App\Services\Products;

use App\Jobs\StoreProductImages;
use App\Models\Product;
use App\Models\ProductDraft;
use RuntimeException;

/**
 * Post-publish photo editing: reorder, delete, or replace/refind specific
 * gallery photos by their 1-based display position (what the admin sees
 * and refers to in chat - "swap 1 and 3"), not raw database IDs.
 */
class ProductPhotoManager
{
    /** @param array<int, int> $newOrder 1-based positions in the desired new order, e.g. [3,2,1] */
    public function reorder(Product $product, array $newOrder): void
    {
        $media = $product->media()->orderBy('sort_order')->get();
        $count = $media->count();

        if ($count === 0) {
            throw new RuntimeException('This product has no photos to reorder.');
        }

        if (! $this->isValidPermutation($newOrder, $count)) {
            throw new RuntimeException("new_order must list each position from 1 to {$count} exactly once.");
        }

        foreach ($newOrder as $index => $position) {
            $media->get($position - 1)?->update([
                'sort_order' => $index,
                'is_primary' => $index === 0,
                'role' => $this->roleFor($index),
            ]);
        }
    }

    /**
     * @param array<int, int> $positions 1-based
     * @return int how many photos were actually deleted
     */
    public function delete(Product $product, array $positions): int
    {
        $media = $product->media()->orderBy('sort_order')->get();
        $toDelete = collect($positions)->unique()->map(fn (int $position) => $media->get($position - 1))->filter();

        foreach ($toDelete as $item) {
            $item->delete();
        }

        $this->renumber($product);

        return $toDelete->count();
    }

    /**
     * Delete the given positions (or everything, if $fresh) and dispatch a
     * fresh search to fill the gallery back up to its target count.
     *
     * @param array<int, int> $replacePositions 1-based
     */
    public function refind(Product $product, array $replacePositions = [], bool $fresh = false): bool
    {
        if ($fresh) {
            foreach ($product->media()->get() as $item) {
                $item->delete();
            }
        } elseif ($replacePositions !== []) {
            $this->delete($product, $replacePositions);
        }

        $draft = ProductDraft::query()->where('approved_product_id', $product->id)->latest('id')->first();
        $variant = $product->defaultVariant ?? $product->variants()->first();

        if (! $draft || ! $variant) {
            return false;
        }

        StoreProductImages::dispatch($product->id, $variant->id, $draft->id)->afterCommit();

        return true;
    }

    private function renumber(Product $product): void
    {
        foreach ($product->media()->orderBy('sort_order')->get()->values() as $index => $item) {
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
