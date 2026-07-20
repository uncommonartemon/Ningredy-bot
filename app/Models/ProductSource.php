<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductSource extends Model
{
    protected $fillable = [
        'product_id', 'product_variant_id', 'product_draft_id', 'title', 'url', 'domain',
        'source_type', 'retrieved_at', 'confidence',
    ];

    protected function casts(): array
    {
        return ['retrieved_at' => 'datetime', 'confidence' => 'decimal:4'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function draft(): BelongsTo
    {
        return $this->belongsTo(ProductDraft::class, 'product_draft_id');
    }
}
