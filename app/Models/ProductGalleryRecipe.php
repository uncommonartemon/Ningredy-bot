<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductGalleryRecipe extends Model
{
    /** Failures that say nothing about the recipe, only that the browser should wait before trying the site again. */
    public const PAUSING_FAILURES = [
        'access_gate', 'rate_limited', 'browser_timeout', 'browser_unavailable', 'browser_process', 'browser_protocol',
    ];

    protected $fillable = [
        'domain', 'path_pattern', 'compatible_path_patterns', 'region', 'sample_path', 'layout_fingerprint',
        'last_observed_layout_fingerprint', 'recipe', 'status', 'source_blocked', 'source_block_reason',
        'source_blocked_at', 'success_count', 'failure_count',
        'consecutive_hard_blocks', 'hard_block_urls', 'last_success_at', 'last_failure_at', 'last_error',
        'last_failure_kind', 'retry_after',
    ];

    protected function casts(): array
    {
        return [
            'recipe' => 'array',
            'compatible_path_patterns' => 'array',
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

    /**
     * Whether the browser waits out a pause on this site. A paused recipe keeps
     * its status: once the pause ends a proven one replays as it is.
     */
    public function playwrightPaused(): bool
    {
        return (bool) $this->retry_after?->isFuture()
            && ($this->status !== 'active' || in_array($this->last_failure_kind, self::PAUSING_FAILURES, true));
    }
}
