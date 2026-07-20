<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class AttributeValue extends Model
{
    protected $fillable = [
        'attribute_definition_id', 'product_id', 'product_variant_id', 'value',
        'normalized_value', 'numeric_value', 'boolean_value', 'unit',
    ];

    protected static function booted(): void
    {
        static::saving(function (AttributeValue $value): void {
            $value->normalized_value = Str::lower(trim($value->normalized_value ?: $value->value));
        });
    }

    protected function casts(): array
    {
        return ['numeric_value' => 'decimal:4', 'boolean_value' => 'boolean'];
    }

    public function definition(): BelongsTo
    {
        return $this->belongsTo(AttributeDefinition::class, 'attribute_definition_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }
}
