<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class ProductVariant extends Model
{
    protected $fillable = [
        'product_id', 'fingerprint', 'name', 'sku', 'mpn', 'gtin', 'color', 'condition',
        'price', 'compare_at_price', 'currency', 'stock_status', 'quantity',
        'warranty_months', 'is_default', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'compare_at_price' => 'decimal:2',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (ProductVariant $variant): void {
            if (blank($variant->fingerprint)) {
                $variant->fingerprint = sha1(implode('|', array_filter([
                    $variant->sku, $variant->mpn, $variant->gtin, $variant->name, $variant->color,
                    Str::uuid()->toString(),
                ])));
            }
        });
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function attributes(): HasMany
    {
        return $this->hasMany(AttributeValue::class);
    }

    public function media(): HasMany
    {
        return $this->hasMany(ProductMedia::class)->orderByDesc('is_primary')->orderBy('sort_order');
    }

    public function sources(): HasMany
    {
        return $this->hasMany(ProductSource::class);
    }

    public function scopeAvailable(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
