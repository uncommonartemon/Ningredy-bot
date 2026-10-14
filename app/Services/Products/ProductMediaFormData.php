<?php

namespace App\Services\Products;

use App\Models\Product;
use App\Models\ProductMedia;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Keep the metadata and verification of the image actually saved by the manual form. */
class ProductMediaFormData
{
    public function prepare(Product $product, array $data, ?ProductMedia $record = null): array
    {
        Validator::make($data, ['product_variant_id' => ['nullable', 'integer',
            Rule::exists(ProductVariant::class, 'id')->where('product_id', $product->id),
        ]])->validate();
        $variantId = $data['product_variant_id'] ?? ($record?->product_variant_id);
        if (array_key_exists('product_variant_id', $data)) {
            $variantId = blank($data['product_variant_id']) ? null : (int) $data['product_variant_id'];
        }
        $data['product_variant_id'] = $variantId;
        $path = array_key_exists('path', $data) ? $data['path'] : $record?->path;
        $url = array_key_exists('url', $data) ? $data['url'] : $record?->url;
        $source = array_key_exists('source_url', $data) ? $data['source_url'] : $record?->source_url;
        $changed = ! $record || $path !== $record->path
            || (blank($path) && $url !== $record->url)
            || $variantId !== $record->product_variant_id
            || $source !== $record->source_url;
        if (! $changed) {
            return $data;
        }
        $data = [...$data, 'verification_status' => 'manual', 'verification_score' => null,
            'verification_model' => null, 'verification_notes' => null, 'verified_at' => now(),
            'width' => null, 'height' => null, 'mime_type' => null, 'file_size' => null, 'checksum' => null,
        ];
        if (filled($path)) {
            $disk = Storage::disk('public');
            $size = $disk->exists($path) ? @getimagesize($disk->path($path)) : false;
            if ($size === false || $size[0] < 1 || $size[1] < 1) {
                throw ValidationException::withMessages(['path' => __('Не удалось прочитать изображение.')]);
            }
            $data = [...$data, 'disk' => 'public', 'url' => null,
                'width' => $size[0], 'height' => $size[1], 'mime_type' => $size['mime'],
                'file_size' => $disk->size($path), 'checksum' => hash_file('sha256', $disk->path($path)),
            ];
        }

        return $data;
    }
}
