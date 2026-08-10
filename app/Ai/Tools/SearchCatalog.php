<?php

namespace App\Ai\Tools;

use App\Models\Product;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class SearchCatalog implements Tool
{
    public function description(): Stringable|string
    {
        return 'Search the complete local electronics catalog, including product attributes and variants. Use this before web research. Pass structured brand, type, CPU, GPU, RAM, color or price filters when the request contains them. Results explicitly show whether a product is published and active.';
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
        $products = Product::query()
            ->with([
                'brand:id,name', 'category:id,name,slug',
                'defaultVariant.attributes.definition:id,key,label',
            ])
            ->when($activeOnly, fn (Builder $builder) => $builder->visibleInCatalog())
            ->when($productType !== '', fn (Builder $builder) => $builder->where('product_type', $productType))
            ->when($brand !== '', fn (Builder $builder) => $builder->whereHas(
                'brand', fn (Builder $brandQuery) => $brandQuery->where('name', 'like', $this->like($brand)),
            ))
            ->when($color !== '', fn (Builder $builder) => $builder->whereHas(
                'variants', fn (Builder $variant) => $variant->where('color', 'like', $this->like($color)),
            ))
            ->when($cpu !== '', fn (Builder $builder) => $this->whereAttributeText($builder, 'cpu', $cpu))
            ->when($gpu !== '', fn (Builder $builder) => $this->whereAttributeText($builder, 'gpu', $gpu))
            ->when($ramMin !== null || $ramMax !== null, function (Builder $builder) use ($ramMin, $ramMax): void {
                $builder->whereHas('variants.attributes', function (Builder $attribute) use ($ramMin, $ramMax): void {
                    $attribute->whereHas('definition', fn (Builder $definition) => $definition->where('key', 'ram'))
                        ->when($ramMin !== null, fn (Builder $value) => $value->where('numeric_value', '>=', $ramMin))
                        ->when($ramMax !== null, fn (Builder $value) => $value->where('numeric_value', '<=', $ramMax));
                });
            })
            ->when($priceMin !== null || $priceMax !== null, function (Builder $builder) use ($priceMin, $priceMax): void {
                $builder->whereHas('variants', fn (Builder $variant) => $variant
                    ->when($priceMin !== null, fn (Builder $price) => $price->where('price', '>=', $priceMin))
                    ->when($priceMax !== null, fn (Builder $price) => $price->where('price', '<=', $priceMax))
                );
            })
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
            ->get()
            ->map(fn (Product $product): array => [
                'id' => $product->id,
                'title' => $product->title,
                'brand' => $product->brand?->name,
                'model' => $product->model,
                'product_type' => $product->product_type,
                'category' => $product->category?->name,
                'status' => $product->status,
                'active' => $product->is_active,
                'variant_id' => $product->defaultVariant?->id,
                'price' => $product->defaultVariant?->price,
                'currency' => $product->defaultVariant?->currency,
                'stock_status' => $product->defaultVariant?->stock_status,
                'color' => $product->defaultVariant?->color,
                'attributes' => $product->defaultVariant?->attributes->mapWithKeys(
                    fn ($value) => [$value->definition?->key ?? 'attribute' => $value->value]
                )->all() ?? [],
            ])->all();

        return json_encode([
            'count' => count($products),
            'searched_local_catalog' => true,
            'products' => $products,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
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
            'price_min' => $schema->number(),
            'price_max' => $schema->number(),
            'active_only' => $schema->boolean(),
            'limit' => $schema->integer(),
        ];
    }

    private function whereAttributeText(Builder $builder, string $key, string $value): void
    {
        $builder->whereHas('variants.attributes', fn (Builder $attribute) => $attribute
            ->whereHas('definition', fn (Builder $definition) => $definition->where('key', $key))
            ->where('value', 'like', $this->like($value)));
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
                '/[^pLpN]+/u',
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

        if (preg_match('/^d+(?:st|nd|rd|th)$/', $term) === 1) {
            return false;
        }

        if (mb_strlen($term) >= 4
            && preg_match('/[a-z]/', $term) === 1
            && preg_match('/d/', $term) === 1) {
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
