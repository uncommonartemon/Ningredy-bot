<?php

namespace App\Services\DatabaseOperator;

use Illuminate\Database\Eloquent\Model;

/** The native category/attribute pivot has a composite key and no timestamps. */
class AttributeCategoryLink extends Model
{
    protected $table = 'attribute_definition_category';

    public $timestamps = false;

    public $incrementing = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_required' => 'boolean', 'is_default' => 'boolean', 'sort_order' => 'integer'];
    }

    public function getKey(): mixed
    {
        return $this->attribute_definition_id.':'.$this->category_id;
    }

    protected function setKeysForSaveQuery($query)
    {
        return $query->where('attribute_definition_id', $this->getRawOriginal('attribute_definition_id') ?? $this->attribute_definition_id)
            ->where('category_id', $this->getRawOriginal('category_id') ?? $this->category_id);
    }
}
