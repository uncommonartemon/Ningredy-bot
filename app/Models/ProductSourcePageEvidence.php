<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductSourcePageEvidence extends Model
{
    protected $fillable = [
        'product_draft_id', 'url_hash', 'url', 'final_url', 'specification_text', 'identity_evidence',
        'captured_via', 'open_selector', 'click_outcome',
    ];

    protected function casts(): array
    {
        return [
            'click_outcome' => 'array',
        ];
    }

    public function productDraft(): BelongsTo
    {
        return $this->belongsTo(ProductDraft::class);
    }
}
