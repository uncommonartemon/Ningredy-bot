<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Services\Catalog\CatalogFacetBuilder;
use App\Services\Catalog\CatalogFilterQuery;
use App\Services\Catalog\ProductCardPayload;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Inertia\Response;
use Inertia\ResponseFactory;

class CatalogController extends Controller
{
    public function __construct(
        private readonly ProductCardPayload $cards,
        private readonly CatalogFilterQuery $filterQuery,
        private readonly CatalogFacetBuilder $facetBuilder,
    ) {}

    public function __invoke(Request $request): Response
    {
        $this->facetBuilder->reset();
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
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);
        $filters = $this->facetBuilder->normalize($filters);
        $query = Product::query()->visibleInCatalog()->with([
            'category:id,name,slug',
            'category.translations:id,category_id,locale,name',
            'brand:id,name,country',
            'attributes' => fn ($query) => $query->whereNull('product_variant_id')->with('definition.group'),
            'variants' => function ($query) use ($filters): void {
                $this->filterQuery->variants($query->getQuery(), $filters);
                if (in_array($filters['sort'] ?? '', ['price_asc', 'price_desc'], true)) {
                    $query->orderByRaw('price IS NULL')->orderBy('price', $filters['sort'] === 'price_desc' ? 'desc' : 'asc');
                }
                $query->with('attributes.definition.group')->orderByDesc('is_default')->orderBy('id');
            },
            'media' => fn ($query) => $query->where('type', 'image')
                ->whereIn('verification_status', ['verified', 'source_verified', 'manual'])
                ->select(['id', 'product_id', 'product_variant_id', 'disk', 'path', 'url']),
        ]);
        $this->filterQuery->products($query, $filters);
        $this->applySort($query, $filters);
        $perPage = min((int) ($filters['per_page'] ?? 12), 50);
        $products = $query->paginate($perPage)->appends(Arr::except($filters, ['page']))
            ->through(fn (Product $product): array => $this->cards->for($product, $product->variants->first()));

        // Keep the address/history consistent with the filters actually applied.
        // Clone the factory so this URL resolver cannot leak into another response.
        $inertia = clone app(ResponseFactory::class);
        $canonicalQuery = Arr::query(array_filter($filters,
            fn ($value) => $value !== null && $value !== '' && $value !== []));
        $canonicalUrl = $request->getBaseUrl().$request->getPathInfo().($canonicalQuery !== '' ? '?'.$canonicalQuery : '');
        $inertia->resolveUrlUsing(fn () => $canonicalUrl);

        return $inertia->render('Catalog/Index', [
            'products' => $products,
            'filters' => $this->filterPayload($filters),
            'facets' => $this->facetBuilder->build($filters, $products->total()),
        ]);
    }

    private function applySort(Builder $query, array $filters): void
    {
        match ($filters['sort'] ?? 'newest') {
            'price_asc' => $query->withMin(
                ['variants as catalog_price' => fn (Builder $variant) => $this->filterQuery->variants($variant, $filters)],
                'price',
            )->orderByRaw('catalog_price IS NULL')->orderBy('catalog_price'),
            'price_desc' => $query->withMax(
                ['variants as catalog_price' => fn (Builder $variant) => $this->filterQuery->variants($variant, $filters)],
                'price',
            )->orderByRaw('catalog_price IS NULL')->orderByDesc('catalog_price'),
            'title' => $query->orderBy('title'),
            default => $query->orderByDesc('published_at')->orderByDesc('id'),
        };
        $query->orderBy('id');
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
            'per_page' => min((int) ($filters['per_page'] ?? 12), 50),
        ];
    }
}
