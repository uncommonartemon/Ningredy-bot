<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** How one rendition rule has done on one image host. */
class ImageRenditionRuleOutcome extends Model
{
    protected $fillable = [
        'image_rendition_rule_id', 'host', 'hits', 'misses', 'consecutive_misses', 'last_hit_at', 'last_miss_at', 'disabled_at',
    ];

    protected function casts(): array
    {
        return ['last_hit_at' => 'datetime', 'last_miss_at' => 'datetime', 'disabled_at' => 'datetime'];
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(ImageRenditionRule::class, 'image_rendition_rule_id');
    }
}
