<?php

namespace App\Models;

use App\Models\Concerns\GeneratesCatalogCode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Brand extends Model
{
    use GeneratesCatalogCode;

    protected $fillable = ['name', 'slug', 'country', 'website_url', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }
}
