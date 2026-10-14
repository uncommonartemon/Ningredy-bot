<?php

namespace App\Services\Catalog;

use App\Models\Product;
use App\Models\ProductVariant;
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
    public function for(Product $product, ?ProductVariant $selectedVariant = null): array
    {
        $variants = $product->variants->where('is_active', true)->sortBy('id');
        $variant = $selectedVariant ?? ($variants->firstWhere('is_default', true) ?: $variants->first());
        $attributes = $product->relationLoaded('attributes')
            ? $product->attributes->whereNull('product_variant_id') : collect();
        $attributes = $attributes->concat($variant?->attributes ?? [])->keyBy('attribute_definition_id');

        return [
            'id' => $product->id,
            'slug' => $product->slug,
            'variant_id' => $variant?->id,
            'url' => '/products/'.$product->slug.($variant ? '?variant='.$variant->id : ''),
            'title' => $product->title,
            'translations' => array_replace_recursive($product->translations ?? [], $variant?->translations ?? []),
            'condition' => $variant?->condition ?? 'new',
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
            'image' => $this->imageUrl($product, $variant),
            'attributes' => AttributePresentation::values($attributes),
        ];
    }

    private function imageUrl(Product $product, ?ProductVariant $variant): ?string
    {
        $media = $product->relationLoaded('media')
            ? ($product->media->firstWhere('product_variant_id', $variant?->id)
                ?? $product->media->firstWhere('product_variant_id', null))
            : $product->catalogMedia;

        if (! $media?->path || ! $media?->disk) {
            return $media?->url;
        }

        return $media->disk === 'public'
            ? '/storage/'.str_replace('\\', '/', $media->path)
            : Storage::disk($media->disk)->url($media->path);
    }
}
