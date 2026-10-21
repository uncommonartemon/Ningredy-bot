<?php

namespace App\Models;

use App\Models\Concerns\GeneratesCatalogCode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AttributeDefinition extends Model
{
    use GeneratesCatalogCode;

    protected function catalogCodeColumn(): string
    {
        return 'key';
    }

    protected function catalogCodeSource(): string
    {
        return 'label';
    }

    /**
     * Codes that name one sellable item. They stay on the product page but are
     * not storefront filters: a checkbox per part number is the catalog again.
     */
    public const IDENTIFIER_KEYS = ['sku', 'mpn', 'gtin', 'ean', 'upc'];

    public static function filterableByDefault(?string $key): bool
    {
        return ! in_array($key, self::IDENTIFIER_KEYS, true);
    }

    protected $fillable = [
        'key', 'label', 'data_type', 'default_unit', 'is_filterable', 'is_variant', 'sort_order', 'translations', 'attribute_group_id',
    ];

    protected function casts(): array
    {
        return ['is_filterable' => 'boolean', 'is_variant' => 'boolean', 'translations' => 'array'];
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class)->withPivot(['is_required', 'sort_order']);
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(AttributeGroup::class, 'attribute_group_id');
    }

    public function values(): HasMany
    {
        return $this->hasMany(AttributeValue::class);
    }
}
