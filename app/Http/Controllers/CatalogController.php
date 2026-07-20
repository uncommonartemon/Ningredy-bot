<?php

namespace App\Http\Controllers;

use App\Models\AttributeValue;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class CatalogController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'category' => ['nullable', 'string', 'max:80'],
            'brands' => ['nullable', 'array', 'max:50'],
            'brands.*' => ['string', 'max:80'],
            'types' => ['nullable', 'array', 'max:20'],
            'types.*' => ['string', 'max:80'],
            'countries' => ['nullable', 'array', 'max:50'],
            'countries.*' => ['string', 'max:80'],
            'colors' => ['nullable', 'array', 'max:50'],
            'colors.*' => ['string', 'max:80'],
            'stock' => ['nullable', 'array', 'max:20'],
            'stock.*' => ['string', 'max:80'],
            'attributes' => ['nullable', 'array', 'max:30'],
            'attributes.*' => ['array', 'max:50'],
            'attributes.*.*' => ['string', 'max:160'],
            'min_price' => ['nullable', 'numeric', 'min:0'],
            'max_price' => ['nullable', 'numeric', 'min:0'],
            'sort' => ['nullable', 'string', 'in:newest,price_asc,price_desc,title'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $query = Product::query()->visibleInCatalog()->with([
            'category:id,name,slug',
            'category.translations:id,category_id,locale,name',
            'brand:id,name,country',
            'variants' => fn ($query) => $query->available()->with(['attributes.definition'])->orderByDesc('is_default')->orderBy('id'),
            'catalogMedia:id,product_id,product_variant_id,disk,path,url',
        ]);

        $this->applyFilters($query, $filters);
        $this->applySort($query, $filters['sort'] ?? 'newest');

        $products = $query->paginate(12)->withQueryString()
            ->through(fn (Product $product): array => $this->productPayload($product));

        return Inertia::render('Catalog/Index', [
            'products' => $products,
            'filters' => $this->filterPayload($filters),
            'facets' => $this->facets(),
        ]);
    }

    private function applyFilters(Builder $query, array $filters): void
    {
        $query
            ->when($filters['search'] ?? null, function (Builder $query, string $search): void {
                $term = '%'.addcslashes(trim($search), '%_\\').'%';
                $query->where(fn (Builder $query) => $query
                    ->where('title', 'like', $term)
                    ->orWhere('model', 'like', $term)
                    ->orWhere('description', 'like', $term)
                    ->orWhereHas('brand', fn (Builder $query) => $query->where('name', 'like', $term))
                    ->orWhereHas('variants', fn (Builder $query) => $query
                        ->where('sku', 'like', $term)->orWhere('mpn', 'like', $term)->orWhere('gtin', 'like', $term)
                        ->orWhereHas('attributes', fn (Builder $query) => $query->where('value', 'like', $term))));
            })
            ->when($filters['category'] ?? null, fn (Builder $query, string $category) => $query
                ->whereHas('category', fn (Builder $query) => $query->where('slug', $category)))
            ->when($filters['brands'] ?? null, fn (Builder $query, array $values) => $query
                ->whereHas('brand', fn (Builder $query) => $query->whereIn('name', $values)))
            ->when($filters['types'] ?? null, fn (Builder $query, array $values) => $query->whereIn('product_type', $values))
            ->when($filters['countries'] ?? null, fn (Builder $query, array $values) => $query
                ->whereHas('brand', fn (Builder $query) => $query->whereIn('country', $values)));

        $hasVariantFilters = ! empty($filters['colors']) || ! empty($filters['stock'])
            || isset($filters['min_price']) || isset($filters['max_price']) || ! empty($filters['attributes']);

        if ($hasVariantFilters) {
            $query->whereHas('variants', function (Builder $query) use ($filters): void {
                $query->available()
                    ->when($filters['colors'] ?? null, fn (Builder $query, array $values) => $query->whereIn('color', $values))
                    ->when($filters['stock'] ?? null, fn (Builder $query, array $values) => $query->whereIn('stock_status', $values))
                    ->when($filters['min_price'] ?? null, fn (Builder $query, mixed $price) => $query->where('price', '>=', $price))
                    ->when($filters['max_price'] ?? null, fn (Builder $query, mixed $price) => $query->where('price', '<=', $price));

                foreach ($filters['attributes'] ?? [] as $key => $values) {
                    if (is_array($values) && $values !== []) {
                        $query->whereHas('attributes', fn (Builder $query) => $query
                            ->whereIn('normalized_value', $values)
                            ->whereHas('definition', fn (Builder $query) => $query->where('key', $key)));
                    }
                }
            });
        }
    }

    private function applySort(Builder $query, string $sort): void
    {
        match ($sort) {
            'price_asc' => $query->withMin(
                ['variants as catalog_price' => fn (Builder $variant) => $variant->available()],
                'price',
            )->orderByRaw('catalog_price IS NULL')->orderBy('catalog_price'),
            'price_desc' => $query->withMax(
                ['variants as catalog_price' => fn (Builder $variant) => $variant->available()],
                'price',
            )->orderByRaw('catalog_price IS NULL')->orderByDesc('catalog_price'),
            'title' => $query->orderBy('title'),
            default => $query->orderByDesc('published_at')->orderByDesc('id'),
        };
    }

    private function filterPayload(array $filters): array
    {
        return [
            'search' => $filters['search'] ?? '',
            'category' => $filters['category'] ?? '',
            'brands' => Arr::wrap($filters['brands'] ?? []),
            'types' => Arr::wrap($filters['types'] ?? []),
            'countries' => Arr::wrap($filters['countries'] ?? []),
            'colors' => Arr::wrap($filters['colors'] ?? []),
            'stock' => Arr::wrap($filters['stock'] ?? []),
            'attributes' => $filters['attributes'] ?? [],
            'min_price' => $filters['min_price'] ?? null,
            'max_price' => $filters['max_price'] ?? null,
            'sort' => $filters['sort'] ?? 'newest',
        ];
    }

    private function facets(): array
    {
        $visibleProducts = fn (Builder $query) => $query->visibleInCatalog();
        $categories = Category::query()->where('is_active', true)
            ->with('translations:id,category_id,locale,name')
            ->withCount(['products as products_count' => $visibleProducts])
            ->orderBy('sort_order')->get(['id', 'name', 'slug'])
            ->filter(fn (Category $category) => $category->products_count > 0)
            ->values()->map(fn (Category $category) => [
                'label' => $category->name,
                'value' => $category->slug,
                'count' => $category->products_count,
                'translations' => $category->translations->pluck('name', 'locale')->all(),
            ]);

        $attributes = AttributeValue::query()
            ->join('product_variants', 'product_variants.id', '=', 'attribute_values.product_variant_id')
            ->whereNotNull('product_variant_id')
            ->where('product_variants.is_active', true)
            ->whereHas('definition', fn (Builder $query) => $query->where('is_filterable', true))
            ->whereHas('variant.product', $visibleProducts)
            ->with('definition:id,key,label,sort_order')
            ->select(['attribute_values.attribute_definition_id', 'attribute_values.value', 'attribute_values.normalized_value'])
            ->selectRaw('COUNT(DISTINCT product_variants.product_id) as products_count')
            ->groupBy('attribute_values.attribute_definition_id', 'attribute_values.value', 'attribute_values.normalized_value')
            ->orderBy('value')->get()
            ->groupBy('attribute_definition_id')
            ->sortBy(fn ($items) => $items->first()->definition->sort_order)
            ->map(fn ($items) => [
                'key' => $items->first()->definition->key,
                'label' => $items->first()->definition->label,
                'options' => $items->map(fn (AttributeValue $attribute) => [
                    'label' => $attribute->value,
                    'value' => $attribute->normalized_value,
                    'count' => $attribute->products_count,
                ])->values(),
            ])->values();

        $price = ProductVariant::query()->available()->whereNotNull('price')
            ->whereHas('product', $visibleProducts)
            ->selectRaw('MIN(price) as min_price, MAX(price) as max_price')->first();

        return [
            'categories' => $categories,
            'columns' => collect([
                $this->brandFacet(),
                $this->productColumnFacet('product_type', 'types', 'Тип'),
                $this->countryFacet(),
                $this->variantColumnFacet('color', 'colors', 'Цвет'),
                $this->variantColumnFacet('stock_status', 'stock', 'Наличие'),
            ])->filter(fn (array $facet) => $facet['options']->isNotEmpty())->values(),
            'attributes' => $attributes,
            'price' => [
                'min' => $price?->min_price !== null ? (float) $price->min_price : null,
                'max' => $price?->max_price !== null ? (float) $price->max_price : null,
            ],
        ];
    }

    private function brandFacet(): array
    {
        $options = Brand::query()->where('is_active', true)
            ->withCount(['products as products_count' => fn (Builder $query) => $query->visibleInCatalog()])
            ->orderBy('name')->get()
            ->filter(fn (Brand $brand) => $brand->products_count > 0)
            ->values()
            ->map(fn (Brand $brand) => ['label' => $brand->name, 'value' => $brand->name, 'count' => $brand->products_count]);

        return ['key' => 'brands', 'label' => 'Бренд', 'options' => $options];
    }

    private function countryFacet(): array
    {
        $options = Product::query()->visibleInCatalog()
            ->join('brands', 'brands.id', '=', 'products.brand_id')
            ->whereNotNull('brands.country')->where('brands.country', '!=', '')
            ->select('brands.country')->selectRaw('COUNT(DISTINCT products.id) as products_count')
            ->groupBy('brands.country')->orderBy('brands.country')->get()
            ->map(fn (Product $product) => [
                'label' => $product->country,
                'value' => $product->country,
                'count' => $product->products_count,
            ]);

        return ['key' => 'countries', 'label' => 'Страна бренда', 'options' => $options];
    }

    private function productColumnFacet(string $column, string $key, string $label): array
    {
        $labels = ['laptop' => 'Ноутбук', 'desktop' => 'Готовый ПК', 'component' => 'Комплектующая', 'other' => 'Другая техника'];
        $options = Product::query()->visibleInCatalog()->whereNotNull($column)->where($column, '!=', '')
            ->select($column)->selectRaw('COUNT(*) as products_count')->groupBy($column)->orderBy($column)->get()
            ->map(fn (Product $product) => [
                'label' => $labels[$product->{$column}] ?? $product->{$column},
                'value' => $product->{$column}, 'count' => $product->products_count,
            ]);

        return ['key' => $key, 'label' => $label, 'options' => $options];
    }

    private function variantColumnFacet(string $column, string $key, string $label): array
    {
        $labels = ['in_stock' => 'В наличии', 'out_of_stock' => 'Нет в наличии', 'preorder' => 'Предзаказ', 'unknown' => 'Уточнить'];
        $options = ProductVariant::query()->available()->whereHas('product', fn (Builder $query) => $query->visibleInCatalog())
            ->whereNotNull($column)->where($column, '!=', '')
            ->select($column)->selectRaw('COUNT(DISTINCT product_id) as products_count')->groupBy($column)->orderBy($column)->get()
            ->map(fn (ProductVariant $variant) => [
                'label' => $labels[$variant->{$column}] ?? $variant->{$column},
                'value' => $variant->{$column}, 'count' => $variant->products_count,
            ]);

        return ['key' => $key, 'label' => $label, 'options' => $options];
    }

    private function productPayload(Product $product): array
    {
        $variant = $product->variants->firstWhere('is_default', true) ?: $product->variants->first();

        return [
            'id' => $product->id,
            'slug' => $product->slug,
            'title' => $product->title,
            'brand' => $product->brand?->name,
            'model' => $product->model,
            'type' => $product->product_type,
            'category' => $product->category ? [
                'name' => $product->category->name,
                'slug' => $product->category->slug,
                'translations' => $product->category->translations->pluck('name', 'locale')->all(),
            ] : null,
            'price' => $variant?->price !== null ? (float) $variant->price : null,
            'compare_at_price' => $variant?->compare_at_price !== null ? (float) $variant->compare_at_price : null,
            'currency' => $variant?->currency ?: 'CZK',
            'stock_status' => $variant?->stock_status ?: 'unknown',
            'image' => $product->catalogMedia?->path && $product->catalogMedia?->disk
                ? ($product->catalogMedia->disk === 'public'
                    ? '/storage/'.str_replace('\\', '/', $product->catalogMedia->path)
                    : Storage::disk($product->catalogMedia->disk)->url($product->catalogMedia->path))
                : $product->catalogMedia?->url,
            'attributes' => $variant?->attributes->map(fn (AttributeValue $attribute) => [
                'key' => $attribute->definition->key,
                'label' => $attribute->definition->label,
                'value' => $attribute->value,
            ])->values() ?? collect(),
        ];
    }
}
