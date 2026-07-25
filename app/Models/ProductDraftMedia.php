<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ProductDraftMedia extends Model
{
    protected $table = 'product_draft_media';

    protected $fillable = [
        'product_draft_id', 'disk', 'path', 'source_url', 'role', 'mime_type',
        'width', 'height', 'file_size', 'checksum', 'verification_status',
        'verification_score', 'verification_model', 'verification_notes',
        'sort_order', 'is_primary',
    ];

    protected function casts(): array
    {
        return [
            'verification_score' => 'float',
            'is_primary' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(function (ProductDraftMedia $media): void {
            try {
                if ($media->disk && $media->path) {
                    Storage::disk($media->disk)->delete($media->path);
                }
            } catch (Throwable $exception) {
                report($exception);
            }
        });
    }

    public function draft(): BelongsTo
    {
        return $this->belongsTo(ProductDraft::class, 'product_draft_id');
    }
}
