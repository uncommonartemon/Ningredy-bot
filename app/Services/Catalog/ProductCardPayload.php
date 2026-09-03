<?php

namespace App\Services\Catalog;

use App\Models\AttributeValue;
use App\Models\Product;
use Illuminate\Support\Facades\Storage;

/**
 * The shape one product takes on its way to a Vue card. It lived inside
 * CatalogController until the home page needed the same cards: two copies of
 * this would drift the moment one of them gained a field, and the front end
 * would then have to guess which page it was rendering on.
 */
class ProductCardPayload
{
    /** @return array<string, mixed> */
    public function for(Product $product): array
    {
        $variant = $product->variants->firstWhere('is_default', true) ?: $product->variants->first();

        return [
            'id' => $product->id,
            'slug' => $product->slug,
            'title' => $product->title,
            'brand' => $product->brand?->name,
            'model' => $product->model,
            'type' => $product->product_type,
            'category' => $product->category ? [
                'name' => $product->category->name,
                'slug' => $product->category->slug,
                'translations' => $product->category->translations->pluck('name', 'locale')->all(),
            ] : null,
            'price' => $variant?->price !== null ? (float) $variant->price : null,
            'compare_at_price' => $variant?->compare_at_price !== null ? (float) $variant->compare_at_price : null,
            'currency' => $variant?->currency ?: 'CZK',
            'stock_status' => $variant?->stock_status ?: 'unknown',
            'image' => $this->imageUrl($product),
            'attributes' => $variant?->attributes->map(fn (AttributeValue $attribute) => [
                'key' => $attribute->definition->key,
                'label' => $attribute->definition->label,
                'value' => $attribute->value,
            ])->values() ?? collect(),
        ];
    }

    private function imageUrl(Product $product): ?string
    {
        $media = $product->catalogMedia;

        if (! $media?->path || ! $media?->disk) {
            return $media?->url;
        }

        return $media->disk === 'public'
            ? '/storage/'.str_replace('\\', '/', $media->path)
            : Storage::disk($media->disk)->url($media->path);
    }
}
