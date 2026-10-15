<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AttributeGroup extends Model
{
    protected $fillable = ['name', 'translations', 'sort_order', 'highlight_in_filters'];

    protected $attributes = ['highlight_in_filters' => true];

    protected static function booted(): void
    {
        static::saving(function (AttributeGroup $group): void {
            $group->name = trim($group->name);
            if ($group->exists && $group->isDirty('name') && ! $group->isDirty('translations')) {
                $group->translations = null;
            }
        });
    }

    protected function casts(): array
    {
        return ['translations' => 'array', 'sort_order' => 'integer', 'highlight_in_filters' => 'boolean'];
    }

    public function attributeDefinitions(): HasMany
    {
        return $this->hasMany(AttributeDefinition::class)->orderBy('sort_order')->orderBy('key');
    }

    public function localizedName(?string $locale = null): string
    {
        $translation = ($this->translations ?? [])[$locale ?? app()->getLocale()] ?? null;

        return is_string($translation) && trim($translation) !== '' ? $translation : $this->name;
    }
}
