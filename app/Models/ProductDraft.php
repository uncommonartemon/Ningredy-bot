<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductDraft extends Model
{
    protected $fillable = [
        'telegram_update_id', 'ai_run_id', 'status', 'reviewed_at', 'reviewed_by_user_id',
        'reviewed_by_telegram_user_id', 'rejection_reason', 'requested_by_telegram_user_id',
        'approved_product_id', 'approved_variant_id',
        'title', 'brand', 'model', 'product_type', 'color', 'description', 'research_notes', 'specifications',
        'sources', 'image_urls', 'confidence',
    ];

    protected function casts(): array
    {
        return [
            'specifications' => 'array', 'sources' => 'array', 'image_urls' => 'array',
            'confidence' => 'decimal:4',
            'reviewed_at' => 'datetime',
        ];
    }

    public function telegramUpdate(): BelongsTo
    {
        return $this->belongsTo(TelegramUpdate::class);
    }

    public function aiRun(): BelongsTo
    {
        return $this->belongsTo(AiRun::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'approved_product_id');
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'approved_variant_id');
    }
}
