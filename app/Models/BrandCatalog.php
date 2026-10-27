<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A brand's own catalog: how to find a product code there (web search hint,
 * an address template) or read it (a public JSON API and the fields to take).
 * Placeholders: {code}, {model}, {brand}.
 */
class BrandCatalog extends Model
{
    protected $fillable = [
        'brand', 'aliases', 'catalog_url', 'search_hint', 'lookup_template', 'api_template', 'response_map',
        'instructions', 'enabled', 'hits', 'misses', 'last_used_at', 'last_result',
    ];

    protected function casts(): array
    {
        return [
            'aliases' => 'array',
            'response_map' => 'array',
            'enabled' => 'boolean',
            'last_used_at' => 'datetime',
        ];
    }

    /** @return list<string> the brand and its other spellings, lower-case */
    public function names(): array
    {
        return array_values(array_unique(array_filter(array_map(
            fn (mixed $name): string => mb_strtolower(trim((string) $name)),
            [$this->brand, ...(array) ($this->aliases ?? [])],
        ))));
    }
}
