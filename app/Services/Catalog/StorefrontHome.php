<?php

namespace App\Services\Catalog;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Everything the storefront's start page shows, read from the catalog as it is now:
 * the products on the bench, the newest arrivals, ways into the catalog and its counts.
 * Nothing here is written by hand; an empty catalog gives empty lists, not invented ones.
 */
class StorefrontHome
{
    private const BENCH = 5;

    private const ARRIVALS = 12;

    private const BRANDS = 16;

    public function __construct(private readonly ProductCardPayload $cards) {}

    /** @return array<string, mixed> */
    public function payload(): array
    {
        $visible = Product::query()->visibleInCatalog()
            ->get(['id', 'category_id', 'brand_id', 'published_at']);
        $prices = $this->pricesByProduct($visible->pluck('id'));
        $categories = Category::query()->where('is_active', true)
            ->with('translations:id,category_id,locale,name')
            ->orderBy('sort_order')->orderBy('id')->get(['id', 'name', 'slug', 'parent_id', 'icon']);

        return [
            // Laptops first: the shop is mostly laptops and the bench is its face.
            'bench' => $this->cardsOf($this->cardQuery()->whereHas('media', fn (Builder $query) => $this->catalogImages($query))
                ->orderByRaw("CASE WHEN product_type = 'laptop' THEN 0 ELSE 1 END")
                ->orderByDesc('published_at')->orderByDesc('id')->limit(self::BENCH)->get()),
            'arrivals' => $this->cardsOf($this->cardQuery()
                ->orderByDesc('published_at')->orderByDesc('id')->limit(self::ARRIVALS)->get()),
            'categories' => $this->categories($categories, $visible),
            'brands' => $this->brands($visible),
            'budgets' => $this->budgets($prices),
            'stats' => [
                'products' => $visible->count(),
                'in_stock' => ProductVariant::query()->available()->where('stock_status', 'in_stock')
                    ->whereIn('product_id', $visible->pluck('id'))->distinct()->count('product_id'),
                'brands' => $visible->pluck('brand_id')->filter()->unique()->count(),
                'updated_at' => $visible->max('published_at')?->toIso8601String(),
            ],
        ];
    }

    private function cardQuery(): Builder
    {
        return Product::query()->visibleInCatalog()->with([
            'category:id,name,slug',
            'category.translations:id,category_id,locale,name',
            'brand:id,name,country',
            'attributes' => fn ($query) => $query->whereNull('product_variant_id')->with('definition.group'),
            'variants' => fn ($query) => $query->available()->with('attributes.definition.group')
                ->orderByDesc('is_default')->orderBy('id'),
            'media' => fn ($query) => $this->catalogImages($query)
                ->select(['id', 'product_id', 'product_variant_id', 'disk', 'path', 'url']),
        ]);
    }

    private function catalogImages($query)
    {
        return $query->where('type', 'image')->whereIn('verification_status', ['verified', 'source_verified', 'manual']);
    }

    /** @return list<array<string, mixed>> */
    private function cardsOf(Collection $products): array
    {
        return $products->map(fn (Product $product): array => $this->cards->for($product, $product->variants->first()))->values()->all();
    }

    /**
     * The ways into the catalog by category: the first level that offers a choice. A shop
     * whose every product sits under one top category is shown by the kinds under it, so the
     * page never lists a single row. Each entry carries the names its icon may be found by:
     * its own icon and address, then its parents'.
     *
     * @return list<array<string, mixed>>
     */
    private function categories(Collection $categories, Collection $visible): array
    {
        $level = $this->withProducts($categories->whereNull('parent_id'), $categories, $visible);
        while ($level->count() === 1) {
            $below = $this->withProducts($categories->where('parent_id', $level->first()['category']->id), $categories, $visible);
            // Products filed straight under the top category would be lost from the list.
            if ($below->isEmpty() || $below->sum('count') < $level->first()['count']) {
                break;
            }
            $level = $below;
        }

        return $level->map(fn (array $entry): array => [
            'name' => $entry['category']->name,
            'slug' => $entry['category']->slug,
            'translations' => $entry['category']->translations->pluck('name', 'locale')->all(),
            'count' => $entry['count'],
            'icons' => $this->iconNames($entry['category'], $categories),
        ])->values()->all();
    }

    /** @return list<string> the category's icon and address, then each parent's, nearest first */
    private function iconNames(Category $category, Collection $categories): array
    {
        $names = [];
        for ($current = $category, $depth = 0; $current !== null && $depth < 8; $depth++) {
            array_push($names, (string) $current->icon, (string) $current->slug);
            $current = $current->parent_id === null ? null : $categories->firstWhere('id', $current->parent_id);
        }

        return array_values(array_filter($names, fn (string $name): bool => $name !== ''));
    }

    /** @return Collection<int, array{category: Category, ids: list<int>, count: int}> the given categories that hold products */
    private function withProducts(Collection $level, Collection $categories, Collection $visible): Collection
    {
        return $level->map(function (Category $category) use ($categories, $visible): array {
            $ids = $this->treeIds($category, $categories);

            return ['category' => $category, 'ids' => $ids, 'count' => $visible->whereIn('category_id', $ids)->count()];
        })->filter(fn (array $entry): bool => $entry['count'] > 0)->values();
    }

    /** @return list<int> */
    private function treeIds(Category $root, Collection $categories): array
    {
        $ids = [(int) $root->id];
        $frontier = $ids;
        while ($frontier !== []) {
            $frontier = $categories->whereIn('parent_id', $frontier)->pluck('id')
                ->map(fn ($id): int => (int) $id)->reject(fn (int $id): bool => in_array($id, $ids, true))->values()->all();
            $ids = [...$ids, ...$frontier];
        }

        return $ids;
    }

    /** @return list<array{name: string, count: int}> */
    private function brands(Collection $visible): array
    {
        $counts = $visible->pluck('brand_id')->filter()->countBy();
        if ($counts->isEmpty()) {
            return [];
        }

        return Brand::query()->whereIn('id', $counts->keys())->get(['id', 'name'])
            ->map(fn (Brand $brand): array => ['name' => $brand->name, 'count' => (int) $counts[$brand->id]])
            ->sortBy([['count', 'desc'], ['name', 'asc']])->take(self::BRANDS)->values()->all();
    }

    /** @return Collection<int, Collection<int, array{price: float, currency: string}>> product id => its priced configurations */
    private function pricesByProduct(Collection $productIds): Collection
    {
        return ProductVariant::query()->available()->whereIn('product_id', $productIds)->whereNotNull('price')
            ->get(['product_id', 'price', 'currency'])
            ->map(fn (ProductVariant $variant): array => ['product_id' => (int) $variant->product_id,
                'price' => (float) $variant->price, 'currency' => $variant->currency ?: 'CZK'])
            ->groupBy('product_id');
    }

    /**
     * Three price ranges cut at the thirds of the shop's own prices, rounded to plain numbers.
     * They use the ordinary price, which the catalog's price filter uses too, so a range
     * opens exactly the products it counted. Prices in more than one currency give none.
     *
     * @return array{currency: string, ranges: list<array{min: ?int, max: ?int, count: int}>}|null
     */
    private function budgets(Collection $prices): ?array
    {
        $all = $prices->flatten(1);
        if ($all->pluck('currency')->unique()->count() !== 1) {
            return null;
        }
        $lowest = $prices->map(fn (Collection $rows): float => $rows->min('price'))->sort()->values();
        if ($lowest->count() < 6) {
            return null;
        }
        $first = $this->plain($lowest[intdiv($lowest->count(), 3)]);
        $second = $this->plain($lowest[intdiv($lowest->count() * 2, 3)]);
        if ($first >= $second) {
            return null;
        }
        $ranges = collect([[null, $first], [$first, $second], [$second, null]])->map(fn (array $range): array => [
            'min' => $range[0], 'max' => $range[1],
            'count' => $prices->filter(fn (Collection $rows): bool => $rows->contains(fn (array $row): bool => ($range[0] === null || $row['price'] >= $range[0])
                && ($range[1] === null || $row['price'] <= $range[1])))->count(),
        ]);
        if ($ranges->contains('count', 0)) {
            return null;
        }

        return ['currency' => $all->first()['currency'], 'ranges' => $ranges->all()];
    }

    /** 23 456 → 23 000, 1 234 → 1 200: two significant digits. */
    private function plain(float $price): int
    {
        $step = 10 ** max(0, strlen((string) (int) $price) - 2);

        return (int) (round($price / $step) * $step);
    }
}
