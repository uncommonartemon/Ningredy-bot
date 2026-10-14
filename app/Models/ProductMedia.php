<?php

namespace App\Models;

use App\Jobs\DeleteUnreferencedMedia;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ProductMedia extends Model
{
    protected $table = 'product_media';

    protected $fillable = [
        'product_id', 'product_variant_id', 'type', 'disk', 'path', 'role', 'url', 'source_url',
        'alt', 'mime_type', 'width', 'height', 'file_size', 'checksum', 'sort_order', 'is_primary',
        'verification_status', 'verification_score', 'verification_model', 'verification_notes', 'verified_at',
    ];

    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
            'verification_score' => 'decimal:4',
            'verified_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (ProductMedia $media): void {
            if ($media->path) {
                $media->disk ??= 'public';
                $media->url = $media->disk === 'public'
                    ? '/storage/'.str_replace('\\', '/', $media->path)
                    : Storage::disk($media->disk)->url($media->path);
            }
        });

        static::created(function (ProductMedia $media): void {
            if (! $media->disk || ! $media->path || ! $media->product_id) {
                return;
            }

            $directory = "products/{$media->product_id}";

            if (Str::startsWith($media->path, $directory.'/')) {
                return;
            }

            $disk = Storage::disk($media->disk);
            $target = $directory.'/'.basename($media->path);

            // Copy first: a rolled-back insert must not remove the original upload.
            if ($disk->exists($target)) {
                $target = $directory.'/'.Str::uuid().'-'.basename($media->path);
            }
            if ($disk->exists($media->path) && $disk->copy($media->path, $target)) {
                $originalPath = $media->path;
                $url = $media->disk === 'public' ? '/storage/'.$target : $disk->url($target);
                $media->forceFill(['path' => $target, 'url' => $url])->saveQuietly();
                DeleteUnreferencedMedia::dispatch($media->disk, $originalPath);
            }
        });

        static::updated(function (ProductMedia $media): void {
            $oldPath = $media->getOriginal('path');

            if ($oldPath && ($oldPath !== $media->path || $media->wasChanged('disk'))) {
                DeleteUnreferencedMedia::dispatch($media->getOriginal('disk') ?: 'public', $oldPath);
            }
        });

        static::deleted(function (ProductMedia $media): void {
            if ($media->disk && $media->path) {
                DeleteUnreferencedMedia::dispatch($media->disk, $media->path);
            }
        });
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }
}
