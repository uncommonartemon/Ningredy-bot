<?php

namespace App\Services\Catalog;

use App\Models\Brand;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Products\ProductCondition;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;

/**
 * Everything the storefront's start page shows, read from the catalog as it is now: the
 * products on the plates and in the row of new ones (the shop's showcase first), the ways
 * into the catalog with their counts and lowest prices, and the catalog's own numbers.
 * Nothing here is written by hand; an empty catalog gives empty lists, not invented ones.
 */
class StorefrontHome
{
    private const PLATES = 3;

    private const ARRIVALS = 12;

    private const BRANDS = 16;

    public function __construct(private readonly ProductCardPayload $cards) {}

    /** @return array<string, mixed> */
    public function payload(): array
    {
        $visible = Product::query()->visibleInCatalog()
            ->get(['id', 'category_id', 'brand_id', 'product_type', 'published_at']);
        $configurations = ProductVariant::query()->available()->whereIn('product_id', $visible->pluck('id'))
            ->get(['product_id', 'condition', 'price', 'currency'])
            ->map(fn (ProductVariant $variant): array => ['product_id' => (int) $variant->product_id,
                'condition' => (string) $variant->condition, 'price' => $variant->price === null ? null : (float) $variant->price,
                'currency' => $variant->currency ?: 'CZK']);
        $priced = $configurations->whereNotNull('price')->values();
        $ways = new CategoryWays(CategoryWays::activeCategories(), $visible->countBy('category_id'));
        $chosen = $this->chosen();
        $withPhotos = fn (Builder $query): Builder => $query->whereHas('media', fn (Builder $media) => $this->catalogImages($media));

        return [
            // The shop's choice first; then laptops with photos, newest first: the shop is mostly
            // laptops and the plates are its face.
            'plates' => $this->cardsOf($chosen->concat($withPhotos($this->cardQuery())->whereNotIn('id', $chosen->pluck('id'))
                ->orderByRaw("CASE WHEN product_type = 'laptop' THEN 0 ELSE 1 END")
                ->orderByDesc('published_at')->orderByDesc('id')->limit(self::PLATES)->get())->take(self::PLATES)),
            'arrivals' => $this->cardsOf($chosen->concat($this->cardQuery()->whereNotIn('id', $chosen->pluck('id'))
                ->orderByDesc('published_at')->orderByDesc('id')->limit(self::ARRIVALS)->get())->take(self::ARRIVALS)),
            'ways' => $this->ways($visible, $configurations),
            'categories' => $this->categories($ways, $visible, $priced),
            'brands' => $this->brands($visible),
            'budgets' => $this->budgets($priced->groupBy('product_id')),
            'stats' => [
                'products' => $visible->count(),
                'in_stock' => ProductVariant::query()->available()->where('stock_status', 'in_stock')
                    ->whereIn('product_id', $visible->pluck('id'))->distinct()->count('product_id'),
                'lowest' => $this->lowest($priced),
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

    /**
     * The showcase picked in the admin, in its order: only products the catalog shows and that
     * have a photo, because a plate or a card without its photo says nothing.
     *
     * @return Collection<int, Product>
     */
    private function chosen(): Collection
    {
        $ids = StorefrontShowcase::ids();
        if ($ids === []) {
            return collect();
        }
        $order = array_flip($ids);

        return $this->cardQuery()->whereIn('id', $ids)
            ->whereHas('media', fn (Builder $media) => $this->catalogImages($media))->get()
            ->sortBy(fn (Product $product): int => $order[$product->id])->values();
    }

    /** @return list<array<string, mixed>> */
    private function cardsOf(Collection $products): array
    {
        return $products->map(fn (Product $product): array => $this->cards->for($product, $product->variants->first()))->values()->all();
    }

    /**
     * Three ways into the catalog: the restored (any condition but new that the catalog holds),
     * the new and the parts. Each carries the catalog address that opens exactly the products it
     * counted - the same condition and type filters the catalog applies - and the lowest price of
     * that slice. A way that holds nothing is left out.
     *
     * @param  Collection<int, array{product_id: int, condition: string, price: ?float, currency: string}>  $configurations
     * @return list<array{key: string, count: int, lowest: ?array{price: float, currency: string}, href: string}>
     */
    private function ways(Collection $visible, Collection $configurations): array
    {
        $occurring = $configurations->pluck('condition')->unique()->all();
        $restored = array_values(array_intersect(array_diff(ProductCondition::VALUES, ['new']), $occurring));
        $parts = $visible->where('product_type', 'component')->pluck('id')->flip();
        $slices = [
            // A condition filter lists products with an available configuration in that condition.
            'restored' => [['condition' => $restored], $configurations->whereIn('condition', $restored)],
            'new' => [['condition' => ['new']], $configurations->where('condition', 'new')],
            // A type filter lists the products of that type, priced or not.
            'parts' => [['types' => ['component']], $configurations->filter(fn (array $row): bool => $parts->has($row['product_id']))],
        ];

        return collect($slices)->map(fn (array $slice, string $key): array => [
            'key' => $key,
            'count' => $key === 'parts' ? $parts->count() : $slice[1]->pluck('product_id')->unique()->count(),
            'lowest' => $this->lowest($slice[1]),
            'href' => '/catalog?'.Arr::query($slice[0]),
        ])->filter(fn (array $way): bool => $way['count'] > 0)->values()->all();
    }

    /**
     * The ways into the catalog by category: the first level that offers a choice (see
     * CategoryWays::choice), so the page never lists a single row while there is a choice. Each
     * entry carries the names its icon may be found by (its own icon and address, then its
     * parents') and the lowest price of the products under it.
     *
     * @return list<array<string, mixed>>
     */
    private function categories(CategoryWays $ways, Collection $visible, Collection $priced): array
    {
        return $ways->choice(null)->map(function (array $entry) use ($ways, $visible, $priced): array {
            $products = $visible->whereIn('category_id', $ways->treeIds($entry['category']))->pluck('id')->all();

            return [
                ...CategoryWays::name($entry['category']),
                'count' => $entry['count'],
                'icons' => $ways->icons($entry['category']),
                'lowest' => $this->lowest($priced->whereIn('product_id', $products)),
            ];
        })->values()->all();
    }

    /**
     * The lowest ordinary price among the configurations: the price the catalog filters and sorts
     * by. Prices in more than one currency cannot be compared, so they give none.
     *
     * @param  Collection<int, array{price: ?float, currency: string}>  $configurations
     * @return array{price: float, currency: string}|null
     */
    private function lowest(Collection $configurations): ?array
    {
        $priced = $configurations->whereNotNull('price');
        if ($priced->isEmpty() || $priced->pluck('currency')->unique()->count() !== 1) {
            return null;
        }

        return ['price' => (float) $priced->min('price'), 'currency' => $priced->first()['currency']];
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

    /**
     * Three price ranges cut at the thirds of the shop's own prices, rounded to plain numbers.
     * They use the ordinary price, which the catalog's price filter uses too, so a range
     * opens exactly the products it counted. Prices in more than one currency give none.
     *
     * @param  Collection<int, Collection<int, array{price: float, currency: string}>>  $prices  product id => its priced configurations
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
