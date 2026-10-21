<?php

namespace App\Services\Catalog;

use App\Models\AttributeDefinition;
use App\Models\AttributeGroup;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CatalogFacetBuilder
{
    private ?Collection $categoryRows = null;

    private ?Collection $attributeRows = null;

    public function __construct(private readonly CatalogFilterQuery $query) {}

    public function reset(): void
    {
        $this->categoryRows = null;
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
        $categories = $this->categories()->map(function (Category $category) use ($filters): array {
            $candidate = $this->normalize(array_replace($filters, ['category' => $category->slug]));

            return [
                'label' => $category->name, 'value' => $category->slug,
                'count' => $this->products($candidate)->count(),
                'translations' => $category->translations->pluck('name', 'locale')->all(),
            ];
        })->values();

        $columns = collect([
            'brands' => 'Бренд', 'types' => 'Тип', 'countries' => 'Страна бренда',
            'colors' => 'Цвет', 'stock' => 'Наличие',
        ])->map(function (string $label, string $key) use ($filters, $total): array {
            $universe = DB::query()->fromSub($this->optionRows($key, ['category' => $filters['category'] ?? '']), 'choices')
                ->select('value')->distinct()->orderBy('value')->get()
                ->map(fn ($row) => ['label' => (string) $row->value, 'value' => (string) $row->value]);

            return ['key' => $key, 'label' => $label, 'options' => $this->counts($key, $universe, $filters, $total)];
        })->filter(fn (array $facet) => $facet['options']->isNotEmpty())->values();

        $attributeRows = $this->scopedAttributes($filters['category'] ?? '');
        $groups = AttributeGroup::query()->whereIn('id', $attributeRows->pluck('attribute_group_id')->filter()->unique())->get()->keyBy('id');
        $keys = $attributeRows->pluck('key')->unique()->values();
        // The storefront names a filter and its options in the visitor's language,
        // as the product page does; only the definition's own label was sent before.
        $labelTranslations = AttributeDefinition::query()->whereIn('key', $keys)->pluck('translations', 'key');
        $valueTranslations = $this->valueTranslations($keys);
        $productsWith = $this->productsPerAttribute($filters['category'] ?? '');
        // A filter carried by one laptop in forty narrows almost nothing; the
        // catalog page then lists dozens of such sections.
        $minimumCoverage = (int) ceil(0.1 * $this->products($this->normalize(['category' => $filters['category'] ?? '']))->count());
        $attributes = AttributePresentation::sort($attributeRows->groupBy('key')
            ->reject(fn (Collection $rows, string $key): bool => ($productsWith[$key] ?? 0) < $minimumCoverage
                || $this->namesSingleProducts($rows, $productsWith[$key] ?? 0) || $this->readsLikeProse($rows))
            ->map(function (Collection $rows, string $key) use ($filters, $total, $groups, $labelTranslations, $valueTranslations): array {
                $options = $rows->unique('normalized_value')->sortBy('option_label')
                    ->map(fn ($row) => ['label' => $row->option_label, 'value' => (string) $row->normalized_value,
                        'translations' => $valueTranslations[$key][(string) $row->normalized_value] ?? []])->values();

                return [
                    'key' => $key, 'label' => $rows->first()->label,
                    'label_translations' => $labelTranslations[$key] ?? [],
                    'sort_order' => (int) $rows->first()->sort_order,
                    'group' => AttributePresentation::group($groups->get($rows->first()->attribute_group_id)),
                    'options' => $this->counts($key, $options, $filters, $total, true),
                ];
            }));

        $priceFilters = $filters;
        unset($priceFilters['min_price'], $priceFilters['max_price']);
        $price = $this->variants($priceFilters)->whereNotNull('price')
            ->selectRaw('MIN(price) as min_price, MAX(price) as max_price, MIN(currency) as min_currency, MAX(currency) as max_currency')->first();

        return [
            'categories' => $categories,
            'allCategoriesCount' => $this->products($this->normalize(array_replace($filters, ['category' => ''])))->count(),
            'columns' => $columns, 'attributes' => $attributes,
            'price' => [
                'min' => $price?->min_price !== null ? (float) $price->min_price : null,
                'max' => $price?->max_price !== null ? (float) $price->max_price : null,
                'currency' => $price?->min_currency === $price?->max_currency ? $price?->min_currency : null,
            ],
        ];
    }

    /**
     * A filter whose every product has its own value (weight "1.86 kg",
     * dimensions, a part number) only lists the catalog again as checkboxes;
     * it is left out. Small lists stay, however unique their values.
     */
    private function namesSingleProducts(Collection $rows, int $productsWithAttribute): bool
    {
        $values = $rows->unique('normalized_value')->count();

        return $values >= 12 && $values >= 0.9 * $productsWithAttribute;
    }

    /** A port list or an in-box sentence is a description, not a value to tick. */
    private function readsLikeProse(Collection $rows): bool
    {
        $labels = $rows->unique('normalized_value')->pluck('option_label');

        return $labels->filter(fn ($label): bool => mb_strlen((string) $label) > 60)->count() * 2 > $labels->count();
    }

    /** @return array<string, int> distinct visible products carrying each filterable attribute */
    private function productsPerAttribute(string $category): array
    {
        $categoryId = $category === '' ? null : $this->categories()->firstWhere('slug', $category)?->id;

        return $this->effectiveAttributes()
            ->join('products', 'products.id', '=', 'product_variants.product_id')
            ->when($categoryId !== null, fn (QueryBuilder $query) => $query->where('products.category_id', $categoryId))
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

    private function categories(): Collection
    {
        return $this->categoryRows ??= Category::query()->where('is_active', true)
            ->with('translations:id,category_id,locale,name')->orderBy('sort_order')->orderBy('id')->get(['id', 'name', 'slug']);
    }

    private function scopedAttributes(string $category): Collection
    {
        $rows = $this->attributeRows ??= $this->effectiveAttributes()
            ->join('products', 'products.id', '=', 'product_variants.product_id')
            ->select(['products.category_id', 'attribute_definitions.key', 'attribute_definitions.label',
                'attribute_definitions.sort_order', 'attribute_definitions.attribute_group_id', 'attribute_values.normalized_value'])
            ->selectRaw('MIN(attribute_values.value) as option_label')
            ->groupBy('products.category_id', 'attribute_definitions.key', 'attribute_definitions.label',
                'attribute_definitions.sort_order', 'attribute_definitions.attribute_group_id', 'attribute_values.normalized_value')
            ->orderBy('attribute_definitions.sort_order')->orderBy('attribute_definitions.key')->get();
        if ($category === '') {
            return $rows;
        }
        $categoryId = $this->categories()->firstWhere('slug', $category)?->id;

        return $categoryId === null ? collect() : $rows->where('category_id', $categoryId);
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
        if (in_array($key, ['colors', 'stock'], true)) {
            $column = $key === 'colors' ? 'color' : 'stock_status';

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
