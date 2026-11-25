<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Services\Catalog\CatalogFilterQuery;
use App\Services\Catalog\ProductCardPayload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The start page's search answers while the visitor types: the first products the
 * catalog's own search finds, so Enter on the same words opens the same list in full.
 */
class SearchSuggestController extends Controller
{
    private const LIMIT = 6;

    public function __invoke(Request $request, CatalogFilterQuery $filterQuery, ProductCardPayload $cards): JsonResponse
    {
        $search = trim((string) $request->validate(['q' => ['required', 'string', 'max:120']])['q']);
        if (mb_strlen($search) < 2) {
            return response()->json(['items' => [], 'total' => 0]);
        }
        $filters = ['search' => $search];
        $query = Product::query()->visibleInCatalog();
        $filterQuery->products($query, $filters);
        $total = (clone $query)->count();
        $products = $query->with([
            'category:id,name,slug',
            'category.translations:id,category_id,locale,name',
            'brand:id,name,country',
            'variants' => function ($query) use ($filterQuery, $filters): void {
                $filterQuery->variants($query->getQuery(), $filters);
                $query->orderByDesc('is_default')->orderBy('id');
            },
            'media' => fn ($query) => $query->where('type', 'image')
                ->whereIn('verification_status', ['verified', 'source_verified', 'manual'])
                ->select(['id', 'product_id', 'product_variant_id', 'disk', 'path', 'url']),
        ])->orderByDesc('published_at')->orderByDesc('id')->limit(self::LIMIT)->get();

        return response()->json([
            'items' => $products->map(function (Product $product) use ($cards): array {
                $card = $cards->for($product, $product->variants->first());

                return collect($card)->only(['id', 'url', 'title', 'translations', 'brand', 'category', 'price', 'currency', 'own_price', 'stock_status', 'image'])->all();
            })->values(),
            'total' => $total,
        ]);
    }
}
