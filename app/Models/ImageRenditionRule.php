<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Where a site keeps the larger rendition of a thumbnail: a seed, an agent's lesson or the operator's word. */
class ImageRenditionRule extends Model
{
    protected $fillable = [
        'host', 'find', 'flags', 'replace', 'origin', 'status', 'position', 'note', 'example_from', 'example_to',
    ];

    public function outcomes(): HasMany
    {
        return $this->hasMany(ImageRenditionRuleOutcome::class);
    }
}
