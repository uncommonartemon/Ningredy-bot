<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductSourceAttempt extends Model
{
    protected $fillable = [
        'telegram_update_id', 'product_draft_id', 'product_gallery_recipe_version_id',
        'domain', 'product_url', 'actor', 'phase', 'action', 'status', 'decision',
        'round', 'input', 'output', 'message', 'duration_ms',
    ];

    protected function casts(): array
    {
        return [
            'input' => 'array',
            'output' => 'array',
        ];
    }

    public function telegramUpdate(): BelongsTo
    {
        return $this->belongsTo(TelegramUpdate::class);
    }

    public function productDraft(): BelongsTo
    {
        return $this->belongsTo(ProductDraft::class);
    }

    public function recipeVersion(): BelongsTo
    {
        return $this->belongsTo(ProductGalleryRecipeVersion::class, 'product_gallery_recipe_version_id');
    }
}
