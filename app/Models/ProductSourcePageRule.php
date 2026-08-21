<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductSourcePageRule extends Model
{
    protected $fillable = [
        'domain',
        'path_hash',
        'path',
        'sample_url',
        'layout_fingerprint',
        'page_kind',
        'reason',
        'evidence',
        'confidence',
        'active',
        'hit_count',
        'last_hit_at',
    ];

    protected function casts(): array
    {
        return [
            'evidence' => 'array',
            'confidence' => 'float',
            'active' => 'boolean',
            'last_hit_at' => 'datetime',
        ];
    }
}
