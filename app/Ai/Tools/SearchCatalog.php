<?php

namespace App\Ai\Tools;

use App\Models\Product;
use App\Models\ProductDraft;
use App\Models\ProductVariant;
use App\Models\TelegramUpdate;
use App\Services\Products\ProductCondition;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class SearchCatalog implements Tool
{
    public function __construct(private readonly ?TelegramUpdate $update = null) {}

    public function description(): Stringable|string
    {
        return 'Search the complete local electronics catalog, including product attributes and variants. Use this before web research. Pass structured brand, type, CPU, GPU, RAM, color or price filters when the request contains them. Results explicitly show whether a product is published and active. A product whose model, SKU, MPN or EAN exactly equals a code in the query is listed first with exact_code_match=true; one whose code stands whole inside a longer code of the query (a sticker line with a lot or serial) follows with code_part_match=true and the rest in extra_in_query.';
    }

    /**
     * Codes from the query that exactly equal a variant's SKU/MPN/GTIN or a
     * product's model. Equality only: a family code that merely starts a SKU
     * is not the same item.
     *
     * @return array<int, array{code: string, variant_id: ?int}>
     */
    private function exactCodeMatches(string $query, bool $activeOnly): array
    {
        $codes = collect(preg_split('/[\s,;]+/u', $query) ?: [])
            ->map(fn (string $token): string => Str::lower(trim($token, " \t\n\r\0\x0B()[]{}\"'«».:!?")))
            ->filter(fn (string $token): bool => mb_strlen($token) >= 5 && preg_match('/\d/', $token) === 1)
            ->unique()->values()->all();
        if ($codes === []) {
            return [];
        }
        // GS1 pads GTIN-8/12/13 to 14 digits with leading zeroes; the stored
        // value may be written in any of those lengths.
        $gtins = collect($codes)->filter(fn (string $code): bool => preg_match('/^\d{8,14}$/', $code) === 1)
            ->flatMap(fn (string $code): array => collect([8, 12, 13, 14])
                ->filter(fn (int $length): bool => strlen(ltrim($code, '0')) <= $length)
                ->map(fn (int $length): string => str_pad(ltrim($code, '0'), $length, '0', STR_PAD_LEFT))->all())
            ->unique()->values()->all();
        $matches = [];
        ProductVariant::query()
            ->when($activeOnly, fn ($variant) => $variant->where('is_active', true)->whereHas('product', fn ($product) => $product->visibleInCatalog()))
            ->where(fn ($variant) => $variant->whereIn(DB::raw('lower(sku)'), $codes)->orWhereIn(DB::raw('lower(mpn)'), $codes)
                ->when($gtins !== [], fn ($gtin) => $gtin->orWhereIn('gtin', $gtins)))
            ->orderBy('id')
            ->get(['id', 'product_id', 'sku', 'mpn', 'gtin'])
            ->each(function (ProductVariant $variant) use (&$matches, $codes, $gtins): void {
                $code = collect([$variant->sku, $variant->mpn])->map(fn ($value) => Str::lower((string) $value))
                    ->first(fn (string $value): bool => in_array($value, $codes, true))
                    ?? (in_array((string) $variant->gtin, $gtins, true) ? (string) $variant->gtin : null);
                $matches[$variant->product_id] ??= ['code' => (string) $code, 'variant_id' => $variant->id];
            });
        Product::query()
            ->when($activeOnly, fn ($product) => $product->visibleInCatalog())
            ->whereIn(DB::raw('lower(model)'), $codes)
            ->orderBy('id')
            ->get(['id', 'model'])
            ->each(function (Product $product) use (&$matches): void {
                $matches[$product->id] ??= ['code' => (string) $product->model, 'variant_id' => null];
            });

        // A sticker prints the part number inside a longer line - a lot, date or serial joined to
        // it («9DE00441-VEUR548G6028K»). A catalog code standing whole inside a code of the query is
        // listed too, marked as a part with the rest named: the assistant sees the item before it
        // pays for research and judges the rest (decision of 2026-11-22; #449 and #454 on the second
        // installation searched a kit the catalog held).
        $parts = [];
        foreach ($codes as $code) {
            foreach (self::wholeParts($code) as $part) {
                $parts[$part] ??= $code;
            }
        }
        $parts = array_diff_key($parts, array_flip($codes));
        if ($parts === []) {
            return $matches;
        }
        ProductVariant::query()
            ->when($activeOnly, fn ($variant) => $variant->where('is_active', true)->whereHas('product', fn ($product) => $product->visibleInCatalog()))
            ->where(fn ($variant) => $variant->whereIn(DB::raw('lower(sku)'), array_keys($parts))->orWhereIn(DB::raw('lower(mpn)'), array_keys($parts)))
            ->orderBy('id')
            ->get(['id', 'product_id', 'sku', 'mpn'])
            ->each(function (ProductVariant $variant) use (&$matches, $parts): void {
                $code = (string) collect([$variant->sku, $variant->mpn])->first(fn ($value): bool => isset($parts[Str::lower((string) $value)]));
                $matches[$variant->product_id] ??= ['code' => $code, 'variant_id' => $variant->id, 'part_of' => $parts[Str::lower($code)]];
            });
        Product::query()
            ->when($activeOnly, fn ($product) => $product->visibleInCatalog())
            ->whereIn(DB::raw('lower(model)'), array_keys($parts))
            ->orderBy('id')
            ->get(['id', 'model'])
            ->each(function (Product $product) use (&$matches, $parts): void {
                $matches[$product->id] ??= ['code' => (string) $product->model, 'variant_id' => null, 'part_of' => $parts[Str::lower((string) $product->model)]];
            });

        return $matches;
    }

    /**
     * The runs of whole segments of a code joined by a hyphen, dot or slash, except the code
     * itself: «9de00441-veur548g6028k» gives «9de00441» and «veur548g6028k». A part has a digit
     * and five characters at least.
     *
     * @return list<string>
     */
    private static function wholeParts(string $code): array
    {
        preg_match_all('/[^-.\/]+/u', $code, $found, PREG_OFFSET_CAPTURE);
        $segments = $found[0];
        $parts = [];
        foreach (array_keys($segments) as $first) {
            for ($last = $first; $last < count($segments); $last++) {
                if ($first === 0 && $last === count($segments) - 1) {
                    continue;
                }
                $part = substr($code, $segments[$first][1], $segments[$last][1] + strlen($segments[$last][0]) - $segments[$first][1]);
                if (mb_strlen($part) >= 5 && preg_match('/\d/', $part) === 1) {
                    $parts[] = $part;
                }
            }
        }

        return array_values(array_unique($parts));
    }

    public function handle(Request $request): Stringable|string
    {
        $input = $request->all();
        $query = trim((string) ($input['query'] ?? ''));
        $limit = min(max((int) ($input['limit'] ?? 5), 1), 10);
        $activeOnly = filter_var($input['active_only'] ?? false, FILTER_VALIDATE_BOOL);
        $productType = trim((string) ($input['product_type'] ?? $this->inferProductType($query)));
        $brand = trim((string) ($input['brand'] ?? ''));
        $cpu = trim((string) ($input['cpu'] ?? ''));
        $gpu = trim((string) ($input['gpu'] ?? ''));
        $color = trim((string) ($input['color'] ?? ''));
        $condition = in_array($input['condition'] ?? null, ProductCondition::VALUES, true) ? $input['condition'] : null;
        $ramMin = $this->number($input['ram_min'] ?? null);
        $ramMax = $this->number($input['ram_max'] ?? null);
        $priceMin = $this->number($input['price_min'] ?? null);
        $priceMax = $this->number($input['price_max'] ?? null);

        if ($ramMin === null && preg_match('/(\d+(?:[.,]\d+)?)\s*(?:gb|гб|ram|рам)\b/iu', $query, $match) === 1) {
            $ramMin = (float) str_replace(',', '.', $match[1]);
            $ramMax = $ramMin;
        }

        $hasSpecificFilters = $brand !== '' || $cpu !== '' || $gpu !== '' || $color !== ''
            || $ramMin !== null || $ramMax !== null || $priceMin !== null || $priceMax !== null;
        $terms = $this->searchTerms($query);

        if ($hasSpecificFilters) {
            $structuredTokens = $this->tokens([$brand, $cpu, $gpu, $color]);
            $terms = collect($terms)
                ->reject(fn (string $term): bool => in_array($term, $structuredTokens, true))
                ->filter(fn (string $term): bool => $this->isIdentityTerm($term))
                ->values()
                ->all();
        }
        $variantFilter = function (Builder $variant) use ($condition, $color, $cpu, $gpu, $ramMin, $ramMax, $priceMin, $priceMax, $activeOnly): void {
            $variant->when($condition !== null, fn ($q) => $q->where('condition', $condition))
                ->when($activeOnly, fn ($q) => $q->where('is_active', true))
                ->when($color !== '', fn ($q) => $q->where('color', 'like', $this->like($color)))
                ->when($priceMin !== null, fn ($q) => $q->where('price', '>=', $priceMin))
                ->when($priceMax !== null, fn ($q) => $q->where('price', '<=', $priceMax));
            foreach (['cpu' => $cpu, 'gpu' => $gpu] as $key => $value) {
                if ($value !== '') {
                    $variant->whereHas('attributes', fn ($a) => $a->whereHas('definition', fn ($d) => $d->where('key', $key))
                        ->where('value', 'like', $this->like($value)));
                }
            }
            if ($ramMin !== null || $ramMax !== null) {
                $variant->whereHas('attributes', fn ($a) => $a->whereHas('definition', fn ($d) => $d->where('key', 'ram'))
                    ->when($ramMin !== null, fn ($q) => $q->where('numeric_value', '>=', $ramMin))
                    ->when($ramMax !== null, fn ($q) => $q->where('numeric_value', '<=', $ramMax)));
            }
        };
        $hasVariantFilters = $condition !== null || $color !== '' || $cpu !== '' || $gpu !== ''
            || $ramMin !== null || $ramMax !== null || $priceMin !== null || $priceMax !== null;
        $products = Product::query()
            ->with([
                'brand:id,name', 'category:id,name,slug',
                'variants' => fn ($q) => $q->where($variantFilter)->orderByDesc('is_default')->orderBy('id'),
                'variants.attributes.definition:id,key,label',
            ])
            ->when($activeOnly, fn (Builder $builder) => $builder->visibleInCatalog())
            ->when($hasVariantFilters, fn ($q) => $q->whereHas('variants', $variantFilter))
            ->when($productType !== '', fn (Builder $builder) => $builder->where('product_type', $productType))
            ->when($brand !== '', fn (Builder $builder) => $builder->whereHas(
                'brand', fn (Builder $brandQuery) => $brandQuery->where('name', 'like', $this->like($brand)),
            ))
            ->when($terms !== [], function (Builder $builder) use ($terms): void {
                foreach ($terms as $term) {
                    $like = $this->like($term);
                    $builder->where(function (Builder $match) use ($like): void {
                        $match->where('title', 'like', $like)
                            ->orWhere('model', 'like', $like)
                            ->orWhere('description', 'like', $like)
                            ->orWhereHas('brand', fn (Builder $brand) => $brand->where('name', 'like', $like))
                            ->orWhereHas('category', fn (Builder $category) => $category
                                ->where('name', 'like', $like)->orWhere('slug', 'like', $like))
                            ->orWhereHas('variants', fn (Builder $variant) => $variant
                                ->where('name', 'like', $like)
                                ->orWhere('sku', 'like', $like)
                                ->orWhere('mpn', 'like', $like)
                                ->orWhere('color', 'like', $like)
                                ->orWhereHas('attributes', fn (Builder $attribute) => $attribute
                                    ->where('value', 'like', $like)
                                    ->orWhereHas('definition', fn (Builder $definition) => $definition
                                        ->where('key', 'like', $like)->orWhere('label', 'like', $like))));
                    });
                }
            })
            ->latest('id')
            ->limit($limit)
            ->get();
        // A code in the request names one sellable item. Its product comes first
        // even when the free-text terms or filters would have missed it, so the
        // assistant can see the catalog already has it before paying for research.
        $exact = $this->exactCodeMatches($query, $activeOnly);
        if ($exact !== []) {
            $products = Product::query()
                ->with(['brand:id,name', 'category:id,name,slug', 'variants' => fn ($q) => $q->orderByDesc('is_default')->orderBy('id'),
                    'variants.attributes.definition:id,key,label'])
                ->whereKey(array_diff(array_keys($exact), $products->modelKeys()))->get()
                ->merge($products)
                ->sortByDesc(fn (Product $product): int => isset($exact[$product->id]) ? (isset($exact[$product->id]['part_of']) ? 1 : 2) : 0)
                ->take($limit)->values();
        }
        $products = $products
            ->map(function (Product $product) use ($exact): array {
                $match = $exact[$product->id] ?? null;
                $variant = ($match ? $product->variants->firstWhere('id', $match['variant_id']) : null) ?? $product->variants->first();

                return [
                    ...($match === null ? [] : (isset($match['part_of'])
                        ? ['code_part_match' => true, 'matched_code' => $match['code'], 'query_code' => Str::upper($match['part_of']),
                            'extra_in_query' => Str::upper(trim(str_ireplace($match['code'], '', $match['part_of']), '-./ '))]
                        : ['exact_code_match' => true, 'matched_code' => $match['code']])),
                    'id' => $product->id,
                    'title' => $product->title,
                    'brand' => $product->brand?->name,
                    'model' => $product->model,
                    'product_type' => $product->product_type,
                    'category' => $product->category?->name,
                    'status' => $product->status,
                    'active' => $product->is_active,
                    // false = taken off sale: the card sleeps until the same product comes in again.
                    'on_sale' => $product->isOnSale(),
                    'in_stock' => (int) $product->variants->sum('quantity'),
                    'variant_id' => $variant?->id,
                    'condition' => $variant?->condition,
                    'price' => $variant?->price,
                    'currency' => $variant?->currency,
                    'stock_status' => $variant?->stock_status,
                    'color' => $variant?->color,
                    'attributes' => $variant?->attributes->mapWithKeys(
                        fn ($value) => [$value->definition?->key ?? 'attribute' => $value->value]
                    )->all() ?? [],
                ];
            })->all();

        return json_encode([
            'count' => count($products),
            'searched_local_catalog' => true,
            'products' => $products,
            'matching_drafts' => $this->matchingDrafts($query),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * Drafts of this operator waiting for a decision about the same product: a new
     * search for one of them would pay again for a card that is already there.
     *
     * @return list<array{id: int, title: ?string, model: ?string}>
     */
    private function matchingDrafts(string $query): array
    {
        $query = trim($query);
        if ($this->update === null || $query === '') {
            return [];
        }
        $codes = collect(preg_split('/[\s,;]+/u', $query) ?: [])
            ->filter(fn (string $word): bool => mb_strlen($word) >= 5 && preg_match('/\d/', $word) === 1)->take(5)->values();

        return ProductDraft::query()->where('status', 'pending_review')
            ->where('requested_by_telegram_user_id', $this->update->telegram_user_id)
            ->where(function (Builder $draft) use ($query, $codes): void {
                $draft->where('title', 'like', $this->like($query))->orWhere('model', 'like', $this->like($query));
                foreach ($codes as $code) {
                    $draft->orWhere('model', 'like', $this->like($code))->orWhere('specifications', 'like', $this->like($code));
                }
            })
            ->latest('id')->limit(5)->get(['id', 'title', 'model'])
            ->map(fn (ProductDraft $draft): array => ['id' => $draft->id, 'title' => $draft->title, 'model' => $draft->model])->all();
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->required(),
            'product_type' => $schema->string()->enum(['laptop', 'desktop', 'component', 'other']),
            'brand' => $schema->string(),
            'cpu' => $schema->string(),
            'gpu' => $schema->string(),
            'ram_min' => $schema->number(),
            'ram_max' => $schema->number(),
            'color' => $schema->string(),
            'condition' => $schema->string()->enum(ProductCondition::VALUES),
            'price_min' => $schema->number(),
            'price_max' => $schema->number(),
            'active_only' => $schema->boolean(),
            'limit' => $schema->integer(),
        ];
    }

    /**
     * Real production bug (2026-08-06): a "browse the catalog" button
     * expands to a full instructional sentence ("Покажи последние активные
     * товары локального каталога."), and every leftover non-stopword term
     * became a required AND-ed match - "последние"/"активные"/"локального"
     * (Russian noun/adjective forms this exact-match stopword list didn't
     * cover) can never appear in a real product's fields, so the search
     * always returned zero results regardless of what was actually in the
     * catalog. $stopWordStems matches by prefix so one entry survives every
     * case/number/gender form instead of needing each one enumerated - an
     * exact-match list is permanent whack-a-mole against Russian inflection.
     *
     * @return array<int, string>
     */
    private function searchTerms(string $query): array
    {
        $stopWords = [
            'найди', 'найти', 'есть', 'наш', 'наша', 'наши', 'база', 'базе',
            'привет', 'здравствуй', 'как', 'дела', 'нас', 'ноутбука',
            'ram', 'рам', 'гб', 'gb', 'find', 'show', 'with', 'product',
            'catalog', 'laptop', 'desktop', 'component',
        ];
        $stopWordStems = [
            'покаж', 'поиск', 'товар', 'котор', 'каталог', 'можеш', 'сможе',
            'пожалуйст', 'нужн', 'имеет', 'ноутбук', 'компьютер', 'деталь',
            'компонент', 'последн', 'актив', 'локальн',
        ];

        return collect(preg_split('/[^\pL\pN._-]+/u', Str::lower($query)) ?: [])
            ->map(fn (string $term): string => rtrim($term, '.-_'))
            ->filter(fn (string $term): bool => mb_strlen($term) >= 2
                && ! in_array($term, $stopWords, true)
                && ! collect($stopWordStems)->contains(fn (string $stem): bool => str_starts_with($term, $stem)))
            ->unique()
            ->take(8)
            ->values()
            ->all();
    }

    /**
     * @param  array<int, string>  $values
     * @return array<int, string>
     */
    private function tokens(array $values): array
    {
        return collect($values)
            ->flatMap(fn (string $value): array => preg_split(
                '/[^\pL\pN]+/u',
                Str::lower($value),
            ) ?: [])
            ->filter(fn (string $term): bool => mb_strlen($term) >= 2)
            ->unique()
            ->values()
            ->all();
    }

    private function isIdentityTerm(string $term): bool
    {
        $term = Str::lower(trim($term));

        // "32gb", "1tb", "144hz" are amounts, not model codes; a required match
        // on them hides the product, whose title spells them "32 GB".
        if (preg_match('/^\d+(?:st|nd|rd|th)$/', $term) === 1 || preg_match('/^\d+(?:[.,]\d+)?[a-z]{1,3}$/', $term) === 1) {
            return false;
        }

        if (mb_strlen($term) >= 4
            && preg_match('/[a-z]/', $term) === 1
            && preg_match('/\d/', $term) === 1) {
            return true;
        }

        return preg_match('/^[a-z]{4,}$/', $term) === 1
            && ! in_array($term, [
                'find', 'show', 'with', 'laptop', 'notebook', 'desktop',
                'component', 'processor', 'graphics', 'gaming', 'generation',
                'inch', 'inches', 'product', 'catalog',
            ], true);
    }

    private function inferProductType(string $query): string
    {
        $query = Str::lower($query);

        return match (true) {
            Str::contains($query, ['laptop', 'notebook', 'ноутбук', 'macbook']) => 'laptop',
            Str::contains($query, ['desktop', 'workstation', 'компьютер', 'готовый пк', 'mini pc']) => 'desktop',
            Str::contains($query, ['gpu', 'cpu', 'видеокарт', 'процессор', 'материн', 'motherboard', 'ssd', 'ram', 'оператив']) => 'component',
            default => '',
        };
    }

    private function like(string $value): string
    {
        return '%'.addcslashes(trim($value), '%_\\').'%';
    }

    private function number(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }
}
