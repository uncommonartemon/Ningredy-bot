<?php

namespace App\Services\Products;

use App\Models\Product;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/** Manual admin uploads: no discovery, AI calls, or changes to existing photos. */
final class ProductMediaUploader
{
    /** @param array<array-key, UploadedFile> $photos */
    public function upload(Product $product, array $photos): int
    {
        $photos = array_values($photos);
        Validator::make(['new_photos' => $photos], [
            'new_photos' => ['array'],
            'new_photos.*' => ['required', 'file', 'mimetypes:image/jpeg,image/png,image/webp,image/avif', 'max:8192'],
        ])->validate();

        $dimensions = [];
        foreach ($photos as $index => $photo) {
            $size = $photo instanceof UploadedFile ? @getimagesize($photo->getRealPath()) : false;
            if ($size === false || $size[0] < 1 || $size[1] < 1) {
                throw ValidationException::withMessages(["new_photos.{$index}" => 'Не удалось прочитать изображение.']);
            }
            $dimensions[$index] = $size;
        }

        $storedPaths = [];
        try {
            return DB::transaction(function () use ($product, $photos, $dimensions, &$storedPaths): int {
                $product = Product::query()->lockForUpdate()->findOrFail($product->getKey());
                $order = (int) ($product->media()->max('sort_order') ?? -1) + 1;
                $hasPrimary = $product->media()->where('is_primary', true)->exists();
                $variantId = $product->defaultVariant()->value('id');

                foreach ($photos as $index => $photo) {
                    $path = $photo->store("products/{$product->id}", 'public');
                    if (! is_string($path) || $path === '') {
                        throw new RuntimeException('Не удалось сохранить фотографию.');
                    }
                    $storedPaths[] = $path;
                    $primary = ! $hasPrimary && $index === 0;
                    $product->media()->create([
                        'product_variant_id' => $variantId,
                        'type' => 'image', 'disk' => 'public', 'path' => $path,
                        'alt' => $product->title, 'role' => $primary ? 'primary' : 'secondary',
                        'is_primary' => $primary, 'sort_order' => $order + $index,
                        'mime_type' => $photo->getMimeType(), 'file_size' => $photo->getSize(),
                        'width' => $dimensions[$index][0], 'height' => $dimensions[$index][1],
                        'checksum' => hash_file('sha256', $photo->getRealPath()),
                        'verification_status' => 'manual', 'verified_at' => now(),
                    ]);
                }

                return count($photos);
            });
        } catch (Throwable $exception) {
            // Only this attempt's randomly named files, never existing media.
            Storage::disk('public')->delete($storedPaths);
            throw $exception;
        }
    }
}
