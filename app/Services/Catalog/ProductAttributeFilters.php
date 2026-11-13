<?php

namespace App\Services\Catalog;

use App\Models\AttributeGroup;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The characteristics the admin's product list can be filtered by (decision of 2026-11-12):
 * the same filterable characteristics as on the storefront, chosen by the same rule, but over
 * every product of the category, hidden ones and drafts included, because the admin works with
 * all of them. Scoped to a request: the filters form asks many times while it is drawn.
 */
final class ProductAttributeFilters
{
    /** @var array<string, array<string, array{label: string, options: array<string, string>}>> */
    private array $byCategory = [];

    /** @var array<string, string>|null */
    private ?array $labels = null;

    /**
     * The characteristics worth filtering by in a category and the kinds under it, in the
     * order the card shows them, each with the values its products carry.
     *
     * @return array<string, array{label: string, options: array<string, string>}>
     */
    public function forCategory(int|string|null $categoryId): array
    {
        $categoryId = (int) $categoryId;
        if ($categoryId <= 0) {
            return [];
        }
        if (isset($this->byCategory[$categoryId])) {
            return $this->byCategory[$categoryId];
        }
        $slug = Category::query()->whereKey($categoryId)->value('slug');
        $categories = $slug === null ? [] : Category::treeIdsBySlug($slug);
        if ($categories === []) {
            return $this->byCategory[$categoryId] = [];
        }

        $rows = $this->values($categories)
            ->select(['attribute_definitions.key', 'attribute_definitions.label', 'attribute_definitions.translations',
                'attribute_definitions.sort_order', 'attribute_definitions.attribute_group_id', 'attribute_values.normalized_value'])
            ->selectRaw('MIN(attribute_values.value) as option_label, MIN(attribute_values.unit) as unit')
            ->groupBy('attribute_definitions.key', 'attribute_definitions.label', 'attribute_definitions.translations',
                'attribute_definitions.sort_order', 'attribute_definitions.attribute_group_id', 'attribute_values.normalized_value')
            ->get();
        $productsWith = $this->values($categories)->groupBy('attribute_definitions.key')
            ->selectRaw('attribute_definitions.key as attribute_key, COUNT(DISTINCT products.id) as products')
            ->pluck('products', 'attribute_key')->map(fn ($count): int => (int) $count)->all();
        $minimumCoverage = FilterableAttributes::minimumCoverage(Product::query()->whereIn('category_id', $categories)->count());
        $groups = AttributeGroup::query()->whereIn('id', $rows->pluck('attribute_group_id')->filter()->unique())->get()->keyBy('id');

        $attributes = $rows->groupBy('key')
            ->filter(fn (Collection $values, string $key): bool => FilterableAttributes::useful($values, $productsWith[$key] ?? 0, $minimumCoverage))
            ->map(fn (Collection $values, string $key): array => [
                'key' => $key,
                'label' => $this->label($values->first()),
                'sort_order' => (int) $values->first()->sort_order,
                'group' => AttributePresentation::group($groups->get($values->first()->attribute_group_id)),
                'options' => $values->sortBy(fn (object $value): string => mb_strtolower((string) $value->option_label))
                    ->mapWithKeys(fn (object $value): array => [(string) $value->normalized_value => $this->optionLabel($value)])->all(),
            ]);

        return $this->byCategory[$categoryId] = AttributePresentation::sort($attributes)
            ->mapWithKeys(fn (array $attribute): array => [$attribute['key'] => ['label' => $attribute['label'], 'options' => $attribute['options']]])
            ->all();
    }

    /**
     * Every filterable characteristic some product carries, key => name: the fields the
     * filters form holds, of which a category shows its own.
     *
     * @return array<string, string>
     */
    public function labels(): array
    {
        return $this->labels ??= DB::table('attribute_definitions')->where('is_filterable', true)
            ->whereExists(fn (QueryBuilder $used) => $used->selectRaw('1')->from('attribute_values')
                ->whereColumn('attribute_values.attribute_definition_id', 'attribute_definitions.id'))
            ->orderBy('sort_order')->orderBy('key')
            ->get(['key', 'label', 'translations'])
            ->mapWithKeys(fn (object $definition): array => [(string) $definition->key => $this->label($definition)])
            ->all();
    }

    /** A chosen value as the list shows it: «16 GB», not its normalized «16 gb». */
    public function optionName(string $key, string $value): string
    {
        $label = DB::table('attribute_values')
            ->join('attribute_definitions', 'attribute_definitions.id', '=', 'attribute_values.attribute_definition_id')
            ->where('attribute_definitions.key', $key)->where('attribute_values.normalized_value', $value)
            ->selectRaw('MIN(attribute_values.value) as option_label, MIN(attribute_values.unit) as unit')->first();

        return $label?->option_label === null ? $value : $this->optionLabel($label);
    }

    /** Values of filterable characteristics of products in these categories, own or of a configuration. */
    private function values(array $categories): QueryBuilder
    {
        return DB::table('attribute_values')
            ->join('attribute_definitions', 'attribute_definitions.id', '=', 'attribute_values.attribute_definition_id')
            ->leftJoin('product_variants', 'product_variants.id', '=', 'attribute_values.product_variant_id')
            ->join('products', 'products.id', '=', DB::raw('COALESCE(attribute_values.product_id, product_variants.product_id)'))
            ->where('attribute_definitions.is_filterable', true)
            ->whereNotNull('attribute_values.normalized_value')->where('attribute_values.normalized_value', '!=', '')
            ->whereIn('products.category_id', $categories);
    }

    private function label(object $definition): string
    {
        $translations = json_decode((string) ($definition->translations ?? ''), true);
        $translated = is_array($translations) ? ($translations[app()->getLocale()] ?? null) : null;

        return is_string($translated) && trim($translated) !== '' ? $translated : (string) $definition->label;
    }

    private function optionLabel(object $value): string
    {
        $label = trim((string) $value->option_label);
        $unit = trim((string) ($value->unit ?? ''));

        return $unit !== '' && ! str_contains(mb_strtolower($label), mb_strtolower($unit)) ? "{$label} {$unit}" : $label;
    }
}
