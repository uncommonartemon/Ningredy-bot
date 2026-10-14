<?php

namespace App\Models;

use App\Jobs\DeleteUnreferencedMedia;
use App\Models\Concerns\InvalidatesStaleTextTranslations;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class Product extends Model
{
    use InvalidatesStaleTextTranslations;

    protected $fillable = [
        'translations',
        'category_id', 'brand_id', 'canonical_key', 'product_type', 'status', 'slug',
        'title', 'model', 'description', 'confidence', 'is_active', 'is_featured',
        'sort_order', 'published_at',
    ];

    protected function casts(): array
    {
        return [
            'translations' => 'array',
            'confidence' => 'decimal:4',
            'is_active' => 'boolean',
            'is_featured' => 'boolean',
            'published_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::deleted(function (Product $product): void {
            DeleteUnreferencedMedia::dispatch('public', "products/{$product->id}", (int) $product->id);
        });

        static::creating(function (Product $product): void {
            if (blank($product->slug)) {
                $base = Str::slug($product->title) ?: 'product';
                $product->slug = Product::query()->where('slug', $base)->exists() ? $base.'-'.Str::lower(Str::random(6)) : $base;
            }

            if (blank($product->canonical_key)) {
                $identity = trim(($product->brand?->name ?? '').' '.($product->model ?: $product->title));
                $base = Str::slug($identity) ?: 'product';
                $product->canonical_key = Product::query()->where('canonical_key', $base)->exists()
                    ? $base.'-'.Str::lower(Str::random(6))
                    : $base;
            }
        });
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class);
    }

    public function defaultVariant(): HasOne
    {
        return $this->hasOne(ProductVariant::class)->where('is_default', true);
    }

    public function attributes(): HasMany
    {
        return $this->hasMany(AttributeValue::class);
    }

    public function media(): HasMany
    {
        return $this->hasMany(ProductMedia::class)->orderByDesc('is_primary')->orderBy('sort_order');
    }

    public function primaryMedia(): HasOne
    {
        return $this->hasOne(ProductMedia::class)->where('is_primary', true);
    }

    public function catalogMedia(): HasOne
    {
        $selectedVariant = ProductVariant::query()->available()->select('id')
            ->whereColumn('product_variants.product_id', 'product_media.product_id')
            ->orderByDesc('is_default')->orderBy('id')->limit(1);

        return $this->hasOne(ProductMedia::class)
            ->where('type', 'image')
            ->whereIn('verification_status', ['verified', 'source_verified', 'manual'])
            ->where(fn (Builder $query) => $query->where('product_variant_id', $selectedVariant)->orWhereNull('product_variant_id'))
            ->orderByRaw('product_media.product_variant_id IS NULL')
            ->orderByDesc('is_primary')
            ->orderBy('sort_order')->orderBy('id');
    }

    public function sources(): HasMany
    {
        return $this->hasMany(ProductSource::class);
    }

    public function drafts(): HasMany
    {
        return $this->hasMany(ProductDraft::class, 'approved_product_id');
    }

    public function scopeVisibleInCatalog(Builder $query): Builder
    {
        return $query
            ->where($query->getModel()->qualifyColumn('status'), 'published')
            ->where($query->getModel()->qualifyColumn('is_active'), true);
    }
}
