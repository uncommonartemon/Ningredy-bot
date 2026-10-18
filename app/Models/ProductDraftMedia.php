<?php

namespace App\Models;

use App\Jobs\DeleteUnreferencedMedia;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductDraftMedia extends Model
{
    protected $table = 'product_draft_media';

    protected $fillable = [
        'product_draft_id', 'disk', 'path', 'source_url', 'role', 'mime_type',
        'width', 'height', 'file_size', 'checksum', 'verification_status',
        'verification_score', 'verification_model', 'verification_notes',
        'sort_order', 'is_primary', 'gallery_traversal_complete', 'photo_source_context',
    ];

    protected function casts(): array
    {
        return [
            'verification_score' => 'float',
            'is_primary' => 'boolean',
            'gallery_traversal_complete' => 'boolean',
            'photo_source_context' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::deleted(function (ProductDraftMedia $media): void {
            if ($media->disk && $media->path) {
                DeleteUnreferencedMedia::dispatch($media->disk, $media->path);
            }
        });
    }

    public function draft(): BelongsTo
    {
        return $this->belongsTo(ProductDraft::class, 'product_draft_id');
    }
}
