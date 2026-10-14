<?php

namespace App\Services\Products;

use App\Models\AttributeDefinition;
use App\Models\AttributeValue;
use App\Models\Category;
use Illuminate\Support\Str;

/** Form helpers only: no AI, no changes to automatic card assembly. */
class ManualProductAttributes
{
    public function defaults(int|string|null $categoryId): array
    {
        if (! $categoryId) {
            return [];
        }

        return Category::find($categoryId)?->attributeDefinitions()
            ->wherePivot('is_default', true)->get()
            ->mapWithKeys(fn (AttributeDefinition $definition): array => [Str::uuid()->toString() => [
                'attribute_definition_id' => $definition->id,
                'value' => null,
                'unit' => $definition->default_unit,
                '_category_default' => true,
            ]])->all() ?? [];
    }

    /** Replace only untouched preset rows; never delete entered or persisted data. */
    public function applyCategory(array $variants, int|string|null $categoryId): array
    {
        $defaults = $this->defaults($categoryId);
        foreach ($variants as &$variant) {
            $attributes = array_filter($variant['attributes'] ?? [], fn (array $row): bool => ! ($row['_category_default'] ?? false) || filled($row['value'] ?? null) || isset($row['id']));
            $present = array_column($attributes, 'attribute_definition_id');
            foreach ($defaults as $row) {
                if (! in_array($row['attribute_definition_id'], $present)) {
                    $attributes[Str::uuid()->toString()] = $row;
                }
            }
            $variant['attributes'] = $attributes;
        }

        return $variants;
    }

    /** Suggestions belong to this attribute, not to all specs with similar words. */
    public function suggestions(int|string|null $definitionId, ?string $search = null): array
    {
        if (! $definitionId) {
            return [];
        }

        return AttributeValue::query()->where('attribute_definition_id', $definitionId)
            ->where('value', '!=', '')
            ->when(filled($search), fn ($query) => $query->whereLike('value', '%'.trim($search).'%'))
            ->select('value')->distinct()->orderBy('value')->limit(100)->pluck('value')->all();
    }

    public function normalize(array $data): ?array
    {
        $value = trim((string) ($data['value'] ?? ''));
        if ($value === '' || empty($data['attribute_definition_id'])) {
            return null;
        }

        $definition = AttributeDefinition::findOrFail($data['attribute_definition_id']);
        $unit = trim((string) (array_key_exists('unit', $data) ? $data['unit'] : $definition->default_unit));
        $numeric = str_replace(',', '.', $value);
        if ($unit !== '') {
            $numeric = trim(preg_replace('/\s*'.preg_quote($unit, '/').'$/iu', '', $numeric));
        }

        return [
            'attribute_definition_id' => $definition->id,
            'value' => $value,
            'normalized_value' => Str::limit(Str::lower($value), 255, ''),
            'unit' => $unit !== '' ? $unit : null,
            'numeric_value' => $definition->data_type === 'number' && is_numeric($numeric) ? $numeric : null,
            'boolean_value' => $definition->data_type === 'boolean' ? match (Str::lower($value)) {
                '1', 'true', 'yes', 'да', 'ano' => true,
                '0', 'false', 'no', 'нет', 'ne' => false,
                default => null,
            } : null,
        ];
    }
}
