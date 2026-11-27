<?php

namespace App\Services\Catalog;

use App\Models\AttributeDefinition;
use App\Models\AttributeGroup;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Products\ProductCondition;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CatalogFacetBuilder
{
    /**
     * The filters kept in columns of a product or a configuration, in the order of the panel.
     * The leading ones stand above the characteristics: who made it, whether it can be had and
     * in what state is asked of any product. The others stand below them: what the products of
     * a category differ in (capacity, memory type, screen) is chosen before the colour, the
     * coarse type the category has already told, and the country of the brand. A list of states
     * is offered in the order of their meaning, not of the alphabet.
     */
    private const array COLUMNS = [
        'brands' => ['label' => 'Бренд', 'leading' => true, 'kept_list' => true],
        'stock' => ['label' => 'Наличие', 'leading' => true, 'kept_list' => true, 'order' => ['in_stock', 'preorder', 'out_of_stock', 'unknown']],
        'condition' => ['label' => 'Состояние', 'leading' => true, 'kept_list' => true, 'order' => ProductCondition::VALUES],
        'colors' => ['label' => 'Цвет', 'leading' => false, 'kept_list' => false],
        'types' => ['label' => 'Тип', 'leading' => false, 'kept_list' => true],
        'countries' => ['label' => 'Страна бренда', 'leading' => false, 'kept_list' => true],
    ];

    /** Column filters a configuration carries itself, by the column that holds them. */
    private const array VARIANT_COLUMNS = ['colors' => 'color', 'stock' => 'stock_status', 'condition' => 'condition'];

    private ?CategoryWays $ways = null;

    private ?Collection $attributeRows = null;

    public function __construct(private readonly CatalogFilterQuery $query, private readonly CategoryStandard $standard) {}

    public function reset(): void
    {
        $this->ways = null;
        $this->attributeRows = null;
    }

    public function normalize(array $filters): array
    {
        $filters['search'] = trim($filters['search'] ?? '');
        if (isset($filters['min_price'], $filters['max_price']) && (float) $filters['min_price'] > (float) $filters['max_price']) {
            [$filters['min_price'], $filters['max_price']] = [$filters['max_price'], $filters['min_price']];
        }
        $allowed = $this->scopedAttributes($filters['category'] ?? '')->pluck('key')->flip();
        $filters['attributes'] = collect($filters['attributes'] ?? [])
            ->filter(fn ($values, $key) => $allowed->has($key) && is_array($values) && $values !== [])
            ->map(fn (array $values) => array_values(array_unique($values)))->all();

        return $filters;
    }

    public function build(array $filters, int $total): array
    {
        $category = $filters['category'] ?? '';
        $chosen = $this->ways()->find($category);
        // The category is the page, not a filter: the path to it and the kinds under it are links.
        $tiles = $this->ways()->choice($chosen);
        $categoryProducts = $this->products($this->normalize(['category' => $category]))->count();
        // A ticked filter stays where it can be unticked, whatever the rule says of it.
        $offered = fn (array $ticked, Collection $rows, int $carriers): bool => $ticked !== []
            || FilterableAttributes::useful($rows, $carriers, $categoryProducts);

        $columns = collect(self::COLUMNS)->map(function (array $column, string $key) use ($filters, $total, $category, $offered, $categoryProducts): ?array {
            // What the category holds, whatever else is ticked: a section does not come and go with the other filters.
            $choices = DB::query()->fromSub($this->optionRows($key, ['category' => $category]), 'choices');
            $values = (clone $choices)->select('value')->distinct()->orderBy('value')->pluck('value')
                ->map(fn ($value): string => (string) $value);
            $rows = $values->map(fn (string $value): object => (object) ['normalized_value' => $value, 'option_label' => $value]);
            $carriers = (clone $choices)->distinct()->count('product_id');
            // A list the shop keeps (brands, states) is never free text; the colour is written by hand.
            $shown = $column['kept_list']
                ? ($filters[$key] ?? []) !== [] || FilterableAttributes::narrows($rows, $carriers, $categoryProducts)
                : $offered($filters[$key] ?? [], $rows, $carriers);
            if (! $shown) {
                return null;
            }
            $options = $this->columnOrder($values, $column['order'] ?? [])
                ->map(fn (string $value): array => ['label' => $value, 'value' => $value]);

            return ['key' => $key, 'label' => $column['label'], 'leading' => $column['leading'],
                'options' => $this->counts($key, $options, $filters, $total)];
        })->filter(fn (?array $facet): bool => $facet !== null && $facet['options']->isNotEmpty())->values();

        $attributeRows = $this->scopedAttributes($category);
        $groups = AttributeGroup::query()->whereIn('id', $attributeRows->pluck('attribute_group_id')->filter()->unique())->get()->keyBy('id');
        $keys = $attributeRows->pluck('key')->unique()->values();
        // The storefront names a filter and its options in the visitor's language,
        // as the product page does; only the definition's own label was sent before.
        $labelTranslations = AttributeDefinition::query()->whereIn('key', $keys)->pluck('translations', 'key');
        $valueTranslations = $this->valueTranslations($keys);
        $productsWith = $this->productsPerAttribute($category);
        $form = $this->formOrder($chosen);
        // A filter carried by one laptop in forty narrows almost nothing; the
        // catalog page then lists dozens of such sections.
        $attributes = $this->inFormOrder(AttributePresentation::sort($attributeRows->groupBy('key')
            ->filter(fn (Collection $rows, string $key): bool => $offered($filters['attributes'][$key] ?? [], $rows, $productsWith[$key] ?? 0))
            ->map(function (Collection $rows, string $key) use ($filters, $total, $groups, $labelTranslations, $valueTranslations, $form): array {
                $options = $this->optionOrder($rows)
                    ->map(fn (object $row): array => ['label' => $row->option_label, 'value' => (string) $row->normalized_value,
                        'translations' => $valueTranslations[$key][(string) $row->normalized_value] ?? []])->values();

                return [
                    'key' => $key, 'label' => $rows->first()->label,
                    'label_translations' => $labelTranslations[$key] ?? [],
                    'sort_order' => (int) $rows->first()->sort_order,
                    'group' => AttributePresentation::group($groups->get($rows->first()->attribute_group_id)),
                    'in_form' => $form->has($key),
                    'options' => $this->counts($key, $options, $filters, $total, true),
                ];
            })), $form);

        $priceFilters = $filters;
        unset($priceFilters['min_price'], $priceFilters['max_price']);
        $price = $this->variants($priceFilters)->whereNotNull('price')
            ->selectRaw('MIN(price) as min_price, MAX(price) as max_price, MIN(currency) as min_currency, MAX(currency) as max_currency')->first();

        return [
            'trail' => $chosen === null ? [] : $this->ways()->trail($chosen)->map(fn (Category $step): array => CategoryWays::name($step))->values()->all(),
            // One tile is no choice: the strip is left out rather than offering the only way on.
            'tiles' => $tiles->count() < 2 ? [] : $tiles->map(fn (array $entry): array => [
                ...CategoryWays::name($entry['category']),
                'count' => $entry['count'],
                'icons' => $this->ways()->icons($entry['category']),
            ])->values()->all(),
            'columns' => $columns, 'attributes' => $attributes,
            'price' => [
                'min' => $price?->min_price !== null ? (float) $price->min_price : null,
                'max' => $price?->max_price !== null ? (float) $price->max_price : null,
                'currency' => $price?->min_currency === $price?->max_currency ? $price?->min_currency : null,
            ],
        ];
    }

    /** @return array<string, int> distinct visible products carrying each filterable attribute */
    private function productsPerAttribute(string $category): array
    {
        $categoryIds = $category === '' ? null : $this->treeIds($category);

        return $this->effectiveAttributes()
            ->join('products', 'products.id', '=', 'product_variants.product_id')
            ->when($categoryIds !== null, fn (QueryBuilder $query) => $query->whereIn('products.category_id', $categoryIds))
            ->groupBy('attribute_definitions.key')
            ->selectRaw('attribute_definitions.key as attribute_key, COUNT(DISTINCT product_variants.product_id) as products')
            ->pluck('products', 'attribute_key')->map(fn ($count): int => (int) $count)->all();
    }

    /** @return array<string, array<string, array<string, string>>> key => normalized value => locale => text */
    private function valueTranslations(Collection $keys): array
    {
        $translations = [];
        DB::table('attribute_values')
            ->join('attribute_definitions', 'attribute_definitions.id', '=', 'attribute_values.attribute_definition_id')
            ->whereIn('attribute_definitions.key', $keys)->whereNotNull('attribute_values.translations')
            ->orderBy('attribute_values.id')
            ->get(['attribute_definitions.key', 'attribute_values.normalized_value', 'attribute_values.translations'])
            ->each(function (object $row) use (&$translations): void {
                $decoded = json_decode((string) $row->translations, true);
                if (is_array($decoded) && $decoded !== []) {
                    $translations[$row->key][(string) $row->normalized_value] ??= $decoded;
                }
            });

        return $translations;
    }

    private function ways(): CategoryWays
    {
        return $this->ways ??= CategoryWays::read();
    }

    /**
     * The characteristics the category's form names (its own form or the nearest parent's),
     * the required ones first, each in the form's order. Empty when no category is chosen.
     *
     * @return Collection<string, int> key => place
     */
    private function formOrder(?Category $category): Collection
    {
        if ($category === null) {
            return collect();
        }

        return $this->standard->form($category)
            ->sortBy(fn (AttributeDefinition $definition): int => $definition->pivot->is_required ? 0 : 1)
            ->pluck('key')->values()->flip();
    }

    /**
     * The category's own parameters lead in the order its form gives them; whatever else is
     * worth filtering by follows in the shop's usual order.
     *
     * @param  Collection<int, array<string, mixed>>  $attributes  in the usual order
     * @param  Collection<string, int>  $form
     */
    private function inFormOrder(Collection $attributes, Collection $form): Collection
    {
        [$named, $others] = $attributes->partition(fn (array $attribute): bool => $form->has($attribute['key']));

        return $named->sortBy(fn (array $attribute): int => $form[$attribute['key']])->concat($others)->values();
    }

    /**
     * Options in the order a person reads them: by size when the characteristic is a number
     * (8 GB before 16 GB), otherwise by name with its numbers read as numbers (Gen 9 before Gen 10).
     *
     * @param  Collection<int, object>  $rows  rows of one characteristic
     * @return Collection<int, object> one row per value
     */
    private function optionOrder(Collection $rows): Collection
    {
        $numeric = $rows->first()->data_type === 'number';

        return $rows->groupBy(fn (object $row): string => (string) $row->normalized_value)
            ->map(function (Collection $same) use ($numeric): object {
                $numbers = $numeric ? $same->pluck('numeric_value')->filter(fn ($number): bool => $number !== null) : collect();

                return (object) ['normalized_value' => $same->first()->normalized_value, 'option_label' => (string) $same->first()->option_label,
                    'number' => $numbers->isEmpty() ? null : (float) $numbers->min()];
            })
            ->sort(fn (object $left, object $right): int => match (true) {
                $left->number !== null && $right->number !== null && $left->number !== $right->number => $left->number <=> $right->number,
                ($left->number === null) !== ($right->number === null) => $left->number === null ? 1 : -1,
                default => self::natural($left->option_label, $right->option_label)
                    ?: strcmp((string) $left->normalized_value, (string) $right->normalized_value),
            })->values();
    }

    /**
     * A column's values: a list of states in the order of their meaning, anything else by name.
     *
     * @param  Collection<int, string>  $values
     * @param  list<string>  $order
     * @return Collection<int, string>
     */
    private function columnOrder(Collection $values, array $order): Collection
    {
        $place = array_flip($order);

        return $values->sort(fn (string $left, string $right): int => ($place[$left] ?? PHP_INT_MAX) <=> ($place[$right] ?? PHP_INT_MAX)
            ?: self::natural($left, $right))->values();
    }

    private static function natural(string $left, string $right): int
    {
        return strnatcmp(mb_strtolower($left), mb_strtolower($right));
    }

    private function scopedAttributes(string $category): Collection
    {
        $rows = $this->attributeRows ??= $this->effectiveAttributes()
            ->join('products', 'products.id', '=', 'product_variants.product_id')
            ->select(['products.category_id', 'attribute_definitions.key', 'attribute_definitions.label', 'attribute_definitions.data_type',
                'attribute_definitions.sort_order', 'attribute_definitions.attribute_group_id', 'attribute_values.normalized_value'])
            ->selectRaw('MIN(attribute_values.value) as option_label, MIN(attribute_values.numeric_value) as numeric_value')
            ->groupBy('products.category_id', 'attribute_definitions.key', 'attribute_definitions.label', 'attribute_definitions.data_type',
                'attribute_definitions.sort_order', 'attribute_definitions.attribute_group_id', 'attribute_values.normalized_value')
            ->orderBy('attribute_definitions.sort_order')->orderBy('attribute_definitions.key')->get();
        if ($category === '') {
            return $rows;
        }

        return $rows->whereIn('category_id', $this->treeIds($category));
    }

    /**
     * The category and the active kinds under it, by slug; unknown slugs scope to nothing.
     *
     * @return list<int>
     */
    private function treeIds(string $slug): array
    {
        $root = $this->ways()->find($slug);

        return $root === null ? [] : $this->ways()->treeIds($root);
    }

    private function effectiveAttributes(): QueryBuilder
    {
        return DB::table('attribute_values')
            ->join('product_variants', fn (JoinClause $join) => $join
                ->on('product_variants.id', '=', 'attribute_values.product_variant_id')
                ->orOn(fn (JoinClause $inherited) => $inherited
                    ->on('product_variants.product_id', '=', 'attribute_values.product_id')
                    ->whereNull('attribute_values.product_variant_id')))
            ->join('attribute_definitions', 'attribute_definitions.id', '=', 'attribute_values.attribute_definition_id')
            ->where('product_variants.is_active', true)->where('attribute_definitions.is_filterable', true)
            ->whereNotNull('attribute_values.normalized_value')->where('attribute_values.normalized_value', '!=', '')
            ->whereIn('product_variants.product_id', Product::query()->visibleInCatalog()->select('id'))
            ->where(fn (QueryBuilder $effective) => $effective->whereNotNull('attribute_values.product_variant_id')
                ->orWhereNotExists(fn (QueryBuilder $override) => $override
                    ->selectRaw('1')->from('attribute_values as variant_override')
                    ->whereColumn('variant_override.product_variant_id', 'product_variants.id')
                    ->whereColumn('variant_override.attribute_definition_id', 'attribute_values.attribute_definition_id')));
    }

    private function products(array $filters): Builder
    {
        $products = Product::query()->visibleInCatalog();
        $this->query->products($products, $filters);

        return $products;
    }

    private function variants(array $filters): Builder
    {
        $variants = ProductVariant::query()->whereIn('product_id', $this->products($filters)->select('products.id'));
        $this->query->variants($variants, $filters);

        return $variants;
    }

    private function optionRows(string $key, array $filters, bool $attribute = false): QueryBuilder
    {
        if ($attribute) {
            return $this->effectiveAttributes()->where('attribute_definitions.key', $key)
                ->whereIn('product_variants.id', $this->variants($filters)->select('product_variants.id'))
                ->select(['product_variants.product_id', 'attribute_values.normalized_value as value'])->distinct();
        }
        if (isset(self::VARIANT_COLUMNS[$key])) {
            $column = self::VARIANT_COLUMNS[$key];

            return $this->variants($filters)->whereNotNull($column)->where($column, '!=', '')
                ->select(['product_id', $column.' as value'])->toBase();
        }
        $products = $this->products($filters);
        if (in_array($key, ['brands', 'countries'], true)) {
            $products->join('brands', 'brands.id', '=', 'products.brand_id');
            $column = $key === 'brands' ? 'brands.name' : 'brands.country';
        } else {
            $column = 'products.product_type';
        }

        return $products->whereNotNull($column)->where($column, '!=', '')
            ->select(['products.id as product_id', $column.' as value'])->toBase();
    }

    /**
     * Count the actual checkbox toggle, including OR within a group.
     * Aggregate by value in SQL; adding more options does not add more queries.
     */
    private function counts(string $key, Collection $options, array $filters, int $total, bool $attribute = false): Collection
    {
        $selected = array_values(array_unique($attribute ? ($filters['attributes'][$key] ?? []) : ($filters[$key] ?? [])));
        foreach ($selected as $value) {
            if (! $options->contains(fn (array $option) => $option['value'] === $value)) {
                $options->push(['label' => $value, 'value' => $value]);
            }
        }
        if ($options->isEmpty()) {
            return $options;
        }
        $baseFilters = $filters;
        if ($attribute) {
            unset($baseFilters['attributes'][$key]);
        } else {
            unset($baseFilters[$key]);
        }
        $rows = $this->optionRows($key, $baseFilters, $attribute);
        $distribution = DB::query()->fromSub(clone $rows, 'facet_rows')
            ->select('facet_rows.value')->selectRaw('COUNT(DISTINCT facet_rows.product_id) as matches')
            ->groupBy('facet_rows.value');
        if ($selected !== []) {
            $distribution->leftJoinSub($this->products($filters)->select('products.id'), 'current_matches',
                fn (JoinClause $join) => $join->on('current_matches.id', '=', 'facet_rows.product_id'))
                ->selectRaw('COUNT(DISTINCT current_matches.id) as overlap');
        }
        $distribution = $distribution->get()->keyBy('value');
        $withoutGroup = count($selected) === 1 ? $this->products($baseFilters)->count() : 0;
        $exclusive = collect();
        if (count($selected) > 1) {
            $onlyOneSelected = DB::query()->fromSub(clone $rows, 'selected_rows')
                ->whereIn('value', $selected)->select('product_id')->selectRaw('MIN(value) as value')
                ->groupBy('product_id')->havingRaw('COUNT(DISTINCT value) = 1');
            $exclusive = DB::query()->fromSub($onlyOneSelected, 'exclusive_rows')
                ->select('value')->selectRaw('COUNT(*) as matches')->groupBy('value')->get()->keyBy('value');
        }

        return $options->map(function (array $option) use ($selected, $distribution, $withoutGroup, $exclusive, $total): array {
            $value = $option['value'];
            $stats = $distribution->get($value);
            if (in_array($value, $selected, true)) {
                $count = count($selected) === 1 ? $withoutGroup : $total - (int) ($exclusive->get($value)?->matches ?? 0);
            } else {
                $count = (int) ($stats?->matches ?? 0);
                if ($selected !== []) {
                    $count += $total - (int) ($stats?->overlap ?? 0);
                }
            }

            return [...$option, 'count' => max(0, $count)];
        })->values();
    }
}
