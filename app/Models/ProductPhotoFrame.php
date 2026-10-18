<?php

namespace App\Models;

use App\Jobs\DeleteUnreferencedMedia;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductPhotoFrame extends Model
{
    protected $fillable = [
        'product_photo_batch_id', 'source_url', 'disk', 'path', 'checksum', 'width', 'height',
        'file_size', 'position', 'status', 'candidate_context', 'review', 'reviewed_at',
    ];

    protected function casts(): array
    {
        return ['candidate_context' => 'array', 'review' => 'array', 'reviewed_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::deleted(function (ProductPhotoFrame $frame): void {
            DeleteUnreferencedMedia::dispatch($frame->disk, $frame->path);
        });
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(ProductPhotoBatch::class, 'product_photo_batch_id');
    }
}
