<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AttributeDefinition extends Model
{
    protected $fillable = [
        'key', 'label', 'data_type', 'default_unit', 'is_filterable', 'is_variant', 'sort_order',
    ];

    protected function casts(): array
    {
        return ['is_filterable' => 'boolean', 'is_variant' => 'boolean'];
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class)->withPivot(['is_required', 'sort_order']);
    }

    public function values(): HasMany
    {
        return $this->hasMany(AttributeValue::class);
    }
}
