<?php

namespace App\Services\Catalog;

use App\Jobs\TranslateProductText;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductDraft;
use App\Services\Products\ProductCatalogIdentity;

/**
 * The product title built from the category's parts (decision of 2026-11-13): «{brand} {mpn}
 * {ram} {memory_type}-{data_rate} {module_type}». The server builds it, so the same facts give
 * the same title; a model wrote «Desktop Processor, Tray» on one card and «(Tray)» on the next.
 * A part with no value drops out with what stands before and after it. Without a brand, a model
 * or a code nothing is built and the title stays as it was.
 */
class TitleComposer
{
    /** Parts every category offers besides the characteristics of its form. */
    public const array PRODUCT_PARTS = ['brand', 'model', 'mpn', 'sku', 'color', 'condition', 'text'];

    /** The condition as the card's English title says it; a new product says nothing. */
    private const array CONDITIONS = ['open_box' => 'Open Box', 'used' => 'Used', 'refurbished' => 'Refurbished'];

    /** @param  array<string, string>  $facts  part => value */
    public function compose(?Category $category, array $facts): ?string
    {
        return $this->build((array) ($category?->standardSource('title')?->title_parts ?? []), $facts);
    }

    /**
     * @param  array<array-key, array<string, mixed>>  $parts
     * @param  array<string, string>  $facts
     */
    public function build(array $parts, array $facts): ?string
    {
        $title = '';
        $glue = false;
        $identified = false;
        foreach ($parts as $part) {
            $name = (string) ($part['part'] ?? '');
            $value = trim((string) ($name === 'text' ? ($part['text'] ?? '') : ($facts[$name] ?? '')));
            if ($value === '') {
                continue;
            }
            $identified = $identified || in_array($name, ['brand', 'model', 'mpn', 'sku'], true);
            $piece = (string) ($part['before'] ?? '').$value.(string) ($part['after'] ?? '');
            $title .= ($title === '' || $glue ? '' : ' ').$piece;
            $glue = (bool) ($part['glue'] ?? false);
        }
        $title = trim((string) preg_replace('/\s+/u', ' ', $title));

        return $identified && $title !== '' ? mb_substr($title, 0, 255) : null;
    }

    /** The title of a product in the catalog. */
    public function forProduct(Product $product): ?string
    {
        return $this->compose($product->category()->first(), $this->productFacts($product));
    }

    /** The title of a draft's card. */
    public function forDraft(ProductDraft $draft): ?string
    {
        $category = filled($draft->category) ? Category::query()->where('slug', $draft->category)->first() : null;

        return $this->compose($category, $this->draftFacts($draft));
    }

    /**
     * What a catalog product says, part by part. A value its configurations differ in is not
     * the product's: it does not go into the title.
     *
     * @return array<string, string>
     */
    public function productFacts(Product $product): array
    {
        $product->loadMissing(['brand', 'variants', 'attributes.definition', 'variants.attributes.definition']);
        $variants = $product->variants->sortByDesc('is_default')->values();
        $default = $variants->first();
        $facts = ['brand' => (string) $product->brand?->name, 'model' => (string) $product->model,
            'mpn' => (string) $default?->mpn, 'sku' => (string) $default?->sku, 'color' => (string) $default?->color,
            'condition' => self::CONDITIONS[$default?->condition] ?? ''];
        foreach ($product->attributes->whereNull('product_variant_id') as $value) {
            if ($value->definition !== null) {
                $facts[$value->definition->key] = CategoryStandard::written((string) $value->value, $value->unit);
            }
        }
        $byKey = [];
        foreach ($variants as $variant) {
            foreach ($variant->attributes as $value) {
                if ($value->definition !== null) {
                    $byKey[$value->definition->key][] = CategoryStandard::written((string) $value->value, $value->unit);
                }
            }
        }
        foreach ($byKey as $key => $values) {
            if (count($values) === $variants->count() && count(array_unique($values)) === 1) {
                $facts[$key] = $values[0];
            }
        }

        return $facts;
    }

    /** @return array<string, string> what a draft's card says, part by part */
    public function draftFacts(ProductDraft $draft): array
    {
        $identifiers = ProductCatalogIdentity::identifiers((array) ($draft->specifications ?? []));
        $facts = ['brand' => (string) $draft->brand, 'model' => (string) $draft->model, 'mpn' => (string) ($identifiers['mpn'] ?? ''),
            'sku' => (string) ($identifiers['sku'] ?? ''), 'color' => (string) $draft->color,
            'condition' => self::CONDITIONS[$draft->condition] ?? ''];
        $dictionary = app(AttributeDictionary::class);
        foreach ((array) ($draft->specifications ?? []) as $specification) {
            if (! is_array($specification) || ! is_scalar($specification['value'] ?? null)) {
                continue;
            }
            $key = $dictionary->find(is_string($specification['key'] ?? null) ? $specification['key'] : null,
                is_string($specification['name'] ?? null) ? $specification['name'] : null)?->key ?? (string) ($specification['key'] ?? '');
            if ($key !== '' && ! isset($facts[$key])) {
                $facts[$key] = CategoryStandard::written((string) $specification['value'], $specification['unit'] ?? null);
            }
        }

        return $facts;
    }

    /**
     * The category's products whose title the template changes, at most 300.
     *
     * @return array<int, array{from: string, to: string}> by product id
     */
    public function renames(Category $category): array
    {
        $renames = [];
        $products = Product::query()->whereIn('category_id', app(CategoryStandard::class)->treeIds($category))
            ->with('category')->orderBy('id')->limit(300)->get();
        foreach ($products as $product) {
            $title = $this->forProduct($product);
            if ($title !== null && $title !== $product->title) {
                $renames[$product->id] = ['from' => (string) $product->title, 'to' => $title];
            }
        }

        return $renames;
    }

    /** Gives a product its new title; the address stays, the translated titles are made again. */
    public function retitle(Product $product, string $title): void
    {
        $translations = (array) ($product->translations ?? []);
        foreach ($translations as $locale => $texts) {
            if (is_array($texts)) {
                unset($translations[$locale]['title']);
            }
        }
        $product->forceFill(['title' => $title, 'translations' => $translations ?: null])->save();
        TranslateProductText::dispatch($product->id);
    }

    /**
     * The template as a line: «{brand} {mpn} {ram} {memory_type}-{data_rate}».
     *
     * @param  array<array-key, array<string, mixed>>  $parts
     */
    public static function template(array $parts): string
    {
        $line = '';
        $glue = false;
        foreach ($parts as $part) {
            $name = (string) ($part['part'] ?? '');
            if ($name === '') {
                continue;
            }
            $value = $name === 'text' ? (string) ($part['text'] ?? '') : '{'.$name.'}';
            $line .= ($line === '' || $glue ? '' : ' ').($part['before'] ?? '').$value.($part['after'] ?? '');
            $glue = (bool) ($part['glue'] ?? false);
        }

        return $line;
    }
}
