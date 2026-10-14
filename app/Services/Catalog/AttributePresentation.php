<?php

namespace App\Services\Catalog;

use App\Models\AttributeDefinition;
use App\Models\AttributeGroup;
use App\Models\AttributeValue;
use App\Services\Products\AiAttributeGroups;
use Illuminate\Database\Eloquent\Collection as ModelCollection;
use Illuminate\Support\Collection;

/** Shared display metadata; grouping never changes attribute identity or values. */
final class AttributePresentation
{
    public static function group(?AttributeGroup $group): ?array
    {
        return $group ? [
            'id' => $group->id,
            'name' => $group->name,
            'translations' => $group->translations ?? [],
            'sort_order' => (int) $group->sort_order,
            'highlight_in_filters' => (bool) $group->highlight_in_filters,
        ] : null;
    }

    public static function sort(Collection $attributes): Collection
    {
        return $attributes->sort(function (array $left, array $right): int {
            $a = $left['group'] ?? null;
            $b = $right['group'] ?? null;
            if (($a === null) !== ($b === null)) {
                return $a === null ? 1 : -1;
            }
            if ($a !== null && $b !== null && $a['id'] !== $b['id']) {
                return ((int) $a['sort_order'] <=> (int) $b['sort_order'])
                    ?: strcmp(mb_strtolower($a['name']), mb_strtolower($b['name']))
                    ?: ($a['id'] <=> $b['id']);
            }

            return ((int) ($left['sort_order'] ?? 0) <=> (int) ($right['sort_order'] ?? 0))
                ?: strcmp($left['key'] ?? '', $right['key'] ?? '');
        })->values();
    }

    public static function values(Collection $attributes): array
    {
        $models = new ModelCollection($attributes->values()->all());
        $models->loadMissing('definition.group');

        return self::sort($models->filter(fn (AttributeValue $value): bool => $value->definition !== null)
            ->map(fn (AttributeValue $value): array => [
                'key' => $value->definition->key,
                'label' => $value->definition->label,
                'label_translations' => $value->definition->translations ?? [],
                'value' => $value->value,
                'translations' => $value->translations ?? [],
                'unit' => $value->unit ?: $value->definition->default_unit,
                'sort_order' => (int) $value->definition->sort_order,
                'group' => self::group($value->definition->group),
            ]))->all();
    }

    /** Existing definitions win; new attributes can preview their unapproved group proposal. */
    public static function specifications(array $specifications): Collection
    {
        $definitions = AttributeDefinition::query()
            ->whereIn('key', array_filter(array_column($specifications, 'key')))
            ->with('group')->get()->keyBy('key');
        $groups = AttributeGroup::query()->orderBy('id')->get();

        return self::sort(collect($specifications)->map(function (array $specification) use ($definitions, $groups): array {
            $definition = $definitions->get($specification['key'] ?? '');

            return array_replace($specification, [
                'sort_order' => (int) ($definition?->sort_order ?? 0),
                'group' => $definition ? self::group($definition->group)
                    : AiAttributeGroups::preview($specification['group'] ?? null, $groups),
            ]);
        }));
    }

    /** Telegram uses plain text headings, never HTML supplied by group names. */
    public static function lines(Collection $attributes, callable $format): array
    {
        $lines = [];
        $previousGroup = null;
        foreach ($attributes as $attribute) {
            $group = $attribute['group'] ?? null;
            $groupId = $group['id'] ?? null;
            if ($groupId !== $previousGroup) {
                if ($lines !== []) {
                    $lines[] = '';
                }
                if ($group !== null) {
                    $lines[] = '▸ '.$group['name'];
                }
            }
            $lines[] = $format($attribute);
            $previousGroup = $groupId;
        }

        return $lines;
    }
}
