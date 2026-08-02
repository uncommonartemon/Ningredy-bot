<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductSourceStat extends Model
{
    protected $fillable = [
        'domain',
        'attempt_count',
        'extraction_success_count',
        'accepted_gallery_count',
        'accepted_image_count',
        'failure_count',
        'last_failure_kind',
        'last_attempt_at',
        'last_success_at',
    ];

    protected function casts(): array
    {
        return [
            'last_attempt_at' => 'datetime',
            'last_success_at' => 'datetime',
        ];
    }
}
