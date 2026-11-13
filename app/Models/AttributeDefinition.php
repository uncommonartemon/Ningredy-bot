<?php

namespace App\Models;

use App\Models\Concerns\GeneratesCatalogCode;
use App\Services\Catalog\AttributeDictionary;
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
        'aliases',
    ];

    protected function casts(): array
    {
        return ['is_filterable' => 'boolean', 'is_variant' => 'boolean', 'translations' => 'array', 'aliases' => 'array'];
    }

    protected static function booted(): void
    {
        // A language's field left empty in the admin is no name at all: the bot fills it later.
        static::saving(function (AttributeDefinition $definition): void {
            if ($definition->isDirty('translations')) {
                $definition->translations = array_filter($definition->translations ?? [],
                    fn (mixed $name): bool => is_string($name) && trim($name) !== '') ?: null;
            }
            // Other names are kept in the dictionary's form, once each, never the characteristic's own key or name.
            if ($definition->isDirty('aliases') || $definition->isDirty('key') || $definition->isDirty('label')) {
                $own = [AttributeDictionary::normalize((string) $definition->key), AttributeDictionary::normalize((string) $definition->label)];
                $definition->aliases = collect((array) ($definition->aliases ?? []))
                    ->filter(fn (mixed $name): bool => is_string($name))
                    ->map(fn (string $name): string => mb_substr(AttributeDictionary::normalize($name), 0, 255))
                    ->filter(fn (string $name): bool => $name !== '' && ! in_array($name, $own, true))
                    ->unique()->values()->all() ?: null;
            }
        });
        // The dictionary reads every characteristic once per request; any change makes it read them again.
        static::saved(fn () => app(AttributeDictionary::class)->forget());
        static::deleted(fn () => app(AttributeDictionary::class)->forget());
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
