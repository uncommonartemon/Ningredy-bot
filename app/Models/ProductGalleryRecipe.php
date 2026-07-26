<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductGalleryRecipe extends Model
{
    protected $fillable = [
        'domain', 'path_pattern', 'recipe', 'status', 'success_count', 'failure_count',
        'last_success_at', 'last_failure_at', 'last_error',
    ];

    protected function casts(): array
    {
        return [
            'recipe' => 'array',
            'last_success_at' => 'datetime',
            'last_failure_at' => 'datetime',
        ];
    }
}
