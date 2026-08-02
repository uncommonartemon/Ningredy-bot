<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductDraft extends Model
{
    protected $fillable = [
        'telegram_update_id', 'ai_run_id', 'status', 'reviewed_at', 'reviewed_by_user_id',
        'reviewed_by_telegram_user_id', 'rejection_reason', 'requested_by_telegram_user_id',
        'approved_product_id', 'approved_variant_id',
        'title', 'brand', 'model', 'product_type', 'category', 'color', 'description', 'research_notes', 'specifications',
        'sources', 'primary_source_url', 'official_source_url', 'image_urls',
        'excluded_gallery_source_urls', 'excluded_gallery_image_urls', 'excluded_gallery_hashes',
        'telegram_review_chat_id', 'telegram_review_message_ids', 'telegram_review_has_media', 'telegram_review_caption',
        'telegram_control_message_ids', 'telegram_review_finalized_at',
        'images_staged_at', 'gallery_status', 'gallery_notes', 'confidence',
    ];

    protected function casts(): array
    {
        return [
            'specifications' => 'array', 'sources' => 'array', 'image_urls' => 'array',
            'excluded_gallery_source_urls' => 'array',
            'excluded_gallery_image_urls' => 'array',
            'excluded_gallery_hashes' => 'array',
            'telegram_review_message_ids' => 'array',
            'telegram_review_has_media' => 'boolean',
            'telegram_control_message_ids' => 'array',
            'telegram_review_finalized_at' => 'datetime',
            'confidence' => 'decimal:4',
            'reviewed_at' => 'datetime',
            'images_staged_at' => 'datetime',
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

    public function media(): HasMany
    {
        return $this->hasMany(ProductDraftMedia::class)->orderByDesc('is_primary')->orderBy('sort_order');
    }
}
