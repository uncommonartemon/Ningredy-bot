<?php

namespace App\Models;

use App\Models\Concerns\GeneratesCatalogCode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Brand extends Model
{
    use GeneratesCatalogCode;

    protected $fillable = ['name', 'slug', 'country', 'country_translations', 'website_url', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'country_translations' => 'array'];
    }

    protected static function booted(): void
    {
        static::saving(function (Brand $brand): void {
            // A translation belongs to the country it was made from; the names' schedule makes new ones.
            if ($brand->exists && $brand->isDirty('country') && ! $brand->isDirty('country_translations')) {
                $brand->country_translations = null;
            }
            if ($brand->isDirty('country_translations')) {
                $brand->country_translations = array_filter($brand->country_translations ?? [],
                    fn (mixed $name): bool => is_string($name) && trim($name) !== '') ?: null;
            }
        });
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }
}
