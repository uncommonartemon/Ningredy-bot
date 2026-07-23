<?php

namespace App\Http\Controllers;

use App\Models\AttributeValue;
use App\Models\Product;
use App\Models\ProductMedia;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class ProductController extends Controller
{
    public function __invoke(Product $product): Response
    {
        abort_unless($product->status === 'published' && $product->is_active, 404);

        $product->load([
            'brand:id,name,country,website_url',
            'category:id,name,slug',
            'category.translations:id,category_id,locale,name',
            'attributes.definition:id,key,label,default_unit,sort_order',
            'media',
            'sources:id,product_id,title,url,domain,source_type,retrieved_at',
            'variants' => fn ($query) => $query->available()->with([
                'attributes.definition:id,key,label,default_unit,sort_order',
            ])->orderByDesc('is_default')->orderBy('id'),
        ]);

        return Inertia::render('Products/Show', [
            'product' => [
                'id' => $product->id,
                'slug' => $product->slug,
                'title' => $product->title,
                'model' => $product->model,
                'description' => $product->description,
                'type' => $product->product_type,
                'brand' => $product->brand?->only(['name', 'country', 'website_url']),
                'category' => $product->category ? [
                    'name' => $product->category->name,
                    'slug' => $product->category->slug,
                    'translations' => $product->category->translations->pluck('name', 'locale')->all(),
                ] : null,
                'featured' => $product->is_featured,
                'published_at' => $product->published_at?->toIso8601String(),
                'media' => $product->media->where('type', 'image')
                    ->whereIn('verification_status', ['verified', 'source_verified', 'manual'])->map(fn (ProductMedia $media) => [
                        'id' => $media->id,
                        'url' => $this->mediaUrl($media),
                        'alt' => $media->alt ?: $product->title,
                        'role' => $media->role,
                        'primary' => $media->is_primary,
                    ])->values(),
                'attributes' => $this->attributes($product->attributes),
                'variants' => $product->variants->map(fn ($variant) => [
                    'id' => $variant->id,
                    'name' => $variant->name,
                    'sku' => $variant->sku,
                    'mpn' => $variant->mpn,
                    'gtin' => $variant->gtin,
                    'color' => $variant->color,
                    'condition' => $variant->condition,
                    'price' => $variant->price !== null ? (float) $variant->price : null,
                    'compare_at_price' => $variant->compare_at_price !== null ? (float) $variant->compare_at_price : null,
                    'currency' => $variant->currency ?: 'CZK',
                    'stock_status' => $variant->stock_status,
                    'quantity' => $variant->quantity,
                    'warranty_months' => $variant->warranty_months,
                    'is_default' => $variant->is_default,
                    'attributes' => $this->attributes($variant->attributes),
                ])->values(),
                'sources' => $product->sources->map->only([
                    'id', 'title', 'url', 'domain', 'source_type', 'retrieved_at',
                ])->values(),
            ],
        ]);
    }

    private function attributes($attributes): array
    {
        return $attributes->sortBy('definition.sort_order')->map(fn (AttributeValue $attribute) => [
            'key' => $attribute->definition->key,
            'label' => $attribute->definition->label,
            'value' => $attribute->value,
            'unit' => $attribute->unit ?: $attribute->definition->default_unit,
        ])->values()->all();
    }

    private function mediaUrl(ProductMedia $media): string
    {
        if ($media->disk && $media->path) {
            if ($media->disk === 'public') {
                return '/storage/'.str_replace('\\', '/', $media->path);
            }

            return Storage::disk($media->disk)->url($media->path);
        }

        return $media->url;
    }
}
