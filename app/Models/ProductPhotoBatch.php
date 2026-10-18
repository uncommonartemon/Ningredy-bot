<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A resumable set of photographs from one observed source, independent of the selected draft gallery. */
class ProductPhotoBatch extends Model
{
    protected $fillable = [
        'product_draft_id', 'generation', 'source_url', 'source_context', 'target_fingerprint',
        'policy_fingerprint', 'status', 'interruption_reason', 'gallery_traversal_complete',
        'owner_gallery_signature', 'reviewed_at', 'selected_at',
    ];

    protected function casts(): array
    {
        return ['source_context' => 'array', 'generation' => 'integer',
            'gallery_traversal_complete' => 'boolean', 'reviewed_at' => 'datetime', 'selected_at' => 'datetime'];
    }

    public function draft(): BelongsTo
    {
        return $this->belongsTo(ProductDraft::class, 'product_draft_id');
    }

    public function frames(): HasMany
    {
        return $this->hasMany(ProductPhotoFrame::class)->orderBy('position');
    }
}
