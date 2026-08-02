<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductGalleryRecipe extends Model
{
    protected $fillable = [
        'domain', 'path_pattern', 'recipe', 'status', 'source_blocked', 'source_block_reason',
        'source_blocked_at', 'success_count', 'failure_count',
        'consecutive_hard_blocks', 'hard_block_urls', 'last_success_at', 'last_failure_at', 'last_error',
        'last_failure_kind', 'retry_after',
    ];

    protected function casts(): array
    {
        return [
            'recipe' => 'array',
            'source_blocked' => 'boolean',
            'source_blocked_at' => 'datetime',
            'hard_block_urls' => 'array',
            'last_success_at' => 'datetime',
            'last_failure_at' => 'datetime',
            'retry_after' => 'datetime',
        ];
    }

    public function versions(): HasMany
    {
        return $this->hasMany(ProductGalleryRecipeVersion::class);
    }
}
