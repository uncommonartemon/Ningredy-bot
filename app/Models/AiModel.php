<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiModel extends Model
{
    protected $fillable = [
        'provider',
        'model',
        'label',
        'input_per_million',
        'cached_input_per_million',
        'output_per_million',
        'source_url',
        'pricing_checked_at',
        'is_available',
        'is_enabled',
    ];

    protected function casts(): array
    {
        return [
            'input_per_million' => 'float',
            'cached_input_per_million' => 'float',
            'output_per_million' => 'float',
            'pricing_checked_at' => 'date',
            'is_available' => 'boolean',
            'is_enabled' => 'boolean',
        ];
    }
}
