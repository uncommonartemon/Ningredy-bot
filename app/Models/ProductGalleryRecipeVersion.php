<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductGalleryRecipeVersion extends Model
{
    protected $fillable = [
        'product_gallery_recipe_id', 'domain', 'product_url', 'trigger', 'status',
        'provider', 'model', 'previous_recipe', 'recipe', 'scout', 'result',
        'score', 'error', 'promoted_at',
    ];

    protected function casts(): array
    {
        return [
            'previous_recipe' => 'array',
            'recipe' => 'array',
            'scout' => 'array',
            'result' => 'array',
            'score' => 'decimal:2',
            'promoted_at' => 'datetime',
        ];
    }

    public function galleryRecipe(): BelongsTo
    {
        return $this->belongsTo(ProductGalleryRecipe::class, 'product_gallery_recipe_id');
    }
}
