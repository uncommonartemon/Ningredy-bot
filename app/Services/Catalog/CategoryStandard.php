<?php

namespace App\Services\Catalog;

use App\Models\AttributeDefinition;
use App\Models\Category;
use App\Models\Product;
use App\Services\Products\AiAttributeGroups;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The standard of a category (decision of 2026-11-13): its form - the characteristics a card of
 * the category carries, each with an example of how it is written, «required» or «desired», and
 * its section - set by the operator. The bot fills the form, reports what it did not find or
 * found inexact, and proposes what to add. A category without a form uses its parent's.
 */
class CategoryStandard
{
    public const string MAIN = 'main';

    public const string EXTRA = 'extra';

    /** Characteristics that name one item, not a property: the model row of the form stands for them. */
    private const array IDENTIFIERS = AttributeDefinition::IDENTIFIER_KEYS;

    public function __construct(private readonly AttributeDictionary $dictionary) {}

    /**
     * The category's own form as the admin table holds it.
     *
     * @return list<array{attribute_definition_id: int, example: ?string, is_required: bool, section: string}>
     */
    public function formRows(Category $category): array
    {
        return $category->formDefinitions()->get()->map(fn (AttributeDefinition $definition): array => [
            'attribute_definition_id' => (int) $definition->id,
            'example' => $definition->pivot->example,
            'is_required' => (bool) $definition->pivot->is_required,
            'section' => in_array($definition->pivot->section, [self::MAIN, self::EXTRA], true) ? $definition->pivot->section : self::MAIN,
        ])->values()->all();
    }

    /**
     * Saves the form in the given order. A characteristic taken out of the form keeps its link to
     * the category (its values stay where they are), it is just no longer in the form.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    public function syncForm(Category $category, array $rows): void
    {
        DB::transaction(function () use ($category, $rows): void {
            $category->attributeDefinitions()->newPivotQuery()->update(['is_default' => false, 'is_required' => false]);
            $order = 0;
            $seen = [];
            foreach ($rows as $row) {
                $id = (int) ($row['attribute_definition_id'] ?? 0);
                if ($id < 1 || isset($seen[$id]) || ! AttributeDefinition::query()->whereKey($id)->exists()) {
                    continue;
                }
                $seen[$id] = true;
                $category->attributeDefinitions()->syncWithoutDetaching([$id => [
                    'is_default' => true,
                    'is_required' => (bool) ($row['is_required'] ?? false),
                    'example' => filled($row['example'] ?? null) ? mb_substr(trim((string) $row['example']), 0, 255) : null,
                    'section' => ($row['section'] ?? null) === self::EXTRA ? self::EXTRA : self::MAIN,
                    'sort_order' => $order++,
                ]]);
            }
        });
    }

    /**
     * The form that applies to a category: its own, or the nearest parent's.
     *
     * @return Collection<int, AttributeDefinition> with pivot example, is_required, section
     */
    public function form(?Category $category): Collection
    {
        $source = $category?->standardSource('form');

        return $source === null ? collect() : $source->formDefinitions()->with('group')->get();
    }

    /**
     * How many products of the category (and the kinds under it) carry each characteristic.
     *
     * @return array{total: int, filled: array<int, int>, brand: int, model: int}
     */
    public function coverage(Category $category): array
    {
        $products = Product::query()->whereIn('category_id', $this->treeIds($category));
        $ids = (clone $products)->pluck('id');
        $filled = $ids->isEmpty() ? [] : DB::table('attribute_values')
            ->leftJoin('product_variants', 'product_variants.id', '=', 'attribute_values.product_variant_id')
            ->whereIn(DB::raw('COALESCE(attribute_values.product_id, product_variants.product_id)'), $ids)
            ->selectRaw('attribute_values.attribute_definition_id as definition, COUNT(DISTINCT COALESCE(attribute_values.product_id, product_variants.product_id)) as n')
            ->groupBy('attribute_values.attribute_definition_id')->pluck('n', 'definition')->map(fn (mixed $n): int => (int) $n)->all();

        return ['total' => $ids->count(), 'filled' => $filled,
            'brand' => (clone $products)->whereNotNull('brand_id')->count(),
            'model' => (clone $products)->where(fn ($query) => $query->whereNotNull('model')->where('model', '!=', ''))->count()];
    }

    /** The most frequent value of a characteristic among the category's products, as the card shows it. */
    public function frequentValue(Category $category, int $definitionId): ?string
    {
        $row = DB::table('attribute_values')
            ->leftJoin('product_variants', 'product_variants.id', '=', 'attribute_values.product_variant_id')
            ->leftJoin('products', 'products.id', '=', DB::raw('COALESCE(attribute_values.product_id, product_variants.product_id)'))
            ->where('attribute_values.attribute_definition_id', $definitionId)
            ->whereIn('products.category_id', $this->treeIds($category))
            ->selectRaw('attribute_values.value, attribute_values.unit, COUNT(*) as n')
            ->groupBy('attribute_values.value', 'attribute_values.unit')->orderByDesc('n')->first();

        return $row === null ? null : mb_substr(self::written((string) $row->value, $row->unit), 0, 255);
    }

    /**
     * Rows for what the category's products already carry and the form does not: the most used
     * first, the most frequent value as the example, «required» when nine in ten have it.
     *
     * @param  list<array<string, mixed>>  $existing
     * @return list<array{attribute_definition_id: int, example: ?string, is_required: bool, section: string}>
     */
    public function collectFromProducts(Category $category, array $existing): array
    {
        $coverage = $this->coverage($category);
        $taken = collect($existing)->pluck('attribute_definition_id')->map(fn (mixed $id): int => (int) $id)->flip();
        $identifiers = AttributeDefinition::query()->whereIn('key', self::IDENTIFIERS)->pluck('id')->flip();
        $least = max(2, (int) ceil($coverage['total'] * 0.2));
        $rows = [];
        arsort($coverage['filled']);
        foreach ($coverage['filled'] as $id => $count) {
            if ($count < $least || $taken->has($id) || $identifiers->has($id)) {
                continue;
            }
            $rows[] = ['attribute_definition_id' => (int) $id, 'example' => $this->frequentValue($category, (int) $id),
                'is_required' => $count >= $coverage['total'] * 0.9,
                'section' => count($existing) + count($rows) < 8 ? self::MAIN : self::EXTRA];
        }

        return $rows;
    }

    /**
     * Rows from a table the operator pasted - «Параметр | Пример | Важность», one per line,
     * separated by a tab, «|», «;» or two spaces, with section headings between them. Each name
     * is found in the dictionary; what it does not know goes to its keeper, and a new
     * characteristic is created only as the keeper's answer says.
     *
     * @return array{rows: list<array{attribute_definition_id: int, example: ?string, is_required: bool, section: string}>, created: list<string>, skipped: list<string>}
     */
    public function fromPastedTable(string $text): array
    {
        $section = self::MAIN;
        $lines = [];
        foreach (preg_split('/\R/u', trim($text)) ?: [] as $line) {
            $cells = array_values(array_filter(array_map('trim', preg_split('/\t|\s*\|\s*|\s*;\s*|\s{2,}/u', trim($line)) ?: []), fn (string $cell): bool => $cell !== ''));
            if ($cells === []) {
                continue;
            }
            if (count($cells) === 1) {
                // A heading or a sentence: «2. Дополнительные …» opens the second section.
                $heading = mb_strtolower($cells[0]);
                $section = Str::contains($heading, ['дополнит', 'additional', 'extra', 'додатков', 'další']) ? self::EXTRA
                    : (Str::contains($heading, ['основн', 'main', 'basic', 'hlavní']) ? self::MAIN : $section);

                continue;
            }
            $name = $cells[0];
            if (in_array(mb_strtolower($name), ['параметр', 'parameter', 'характеристика', 'name'], true)) {
                continue;
            }
            $lines[] = ['name' => $name, 'example' => $cells[1] ?? null, 'is_required' => self::required($cells[2] ?? ''), 'section' => $section];
        }

        $rows = [];
        $created = [];
        $skipped = [];
        $unknown = [];
        foreach ($lines as $index => $line) {
            if (self::isProductField($line['name'])) {
                // Brand and model are the product's own fields: always the first rows of the form.
                continue;
            }
            $definition = $this->dictionary->find(null, $line['name']);
            if ($definition === null) {
                $unknown[$index] = ['index' => $index, 'key' => Str::slug($line['name'], '_') ?: 'attribute_'.substr(sha1($line['name']), 0, 12),
                    'name' => $line['name'], 'value' => (string) ($line['example'] ?? '')];
            }
            $rows[$index] = $definition?->id;
        }
        if ($unknown !== []) {
            $verdicts = app(AttributeKeeper::class)->settle(array_values($unknown));
            foreach ($unknown as $index => $row) {
                $verdict = $verdicts[$row['key']] ?? null;
                $definition = ($verdict['decision'] ?? null) === 'same'
                    ? AttributeDefinition::query()->where('key', $verdict['key'])->first() : null;
                if ($definition === null) {
                    $key = ($verdict['decision'] ?? null) === 'new' ? $verdict['key'] : $row['key'];
                    $definition = AttributeDefinition::query()->firstOrCreate(['key' => $key], ['label' => $row['name'], 'data_type' => 'text',
                        'translations' => filled($verdict['name'] ?? null) ? ['en' => $verdict['name']] : null]);
                    if ($definition->wasRecentlyCreated) {
                        $created[] = $row['name'];
                    }
                }
                if ($definition->attribute_group_id === null && ($group = DB::transaction(fn () => AiAttributeGroups::resolve($verdict['group'] ?? null))) !== null) {
                    $definition->update(['attribute_group_id' => $group->id]);
                }
                if ($verdict['remember'] ?? false) {
                    $this->dictionary->remember($definition, $row['name']);
                }
                $rows[$index] = $definition->id;
            }
        }

        $result = [];
        $seen = [];
        foreach ($lines as $index => $line) {
            $id = $rows[$index] ?? null;
            if ($id === null) {
                if (! self::isProductField($line['name'])) {
                    $skipped[] = $line['name'];
                }

                continue;
            }
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $result[] = ['attribute_definition_id' => (int) $id, 'example' => filled($line['example']) ? mb_substr($line['example'], 0, 255) : null,
                'is_required' => $line['is_required'], 'section' => $line['section']];
        }

        return ['rows' => $result, 'created' => $created, 'skipped' => $skipped];
    }

    /** @return list<int> this category and every kind under it */
    public function treeIds(Category $category): array
    {
        return Category::treeIdsBySlug((string) $category->slug) ?: [(int) $category->id];
    }

    /** A value as a card shows it: the unit after the number unless the value already names it. */
    public static function written(string $value, ?string $unit): string
    {
        $value = trim($value);
        $unit = trim((string) $unit);

        return $unit === '' || Str::contains(mb_strtolower($value), mb_strtolower($unit)) ? $value : trim($value.' '.$unit);
    }

    private static function required(string $importance): bool
    {
        return Str::contains(mb_strtolower($importance), ['обязат', 'required', 'must', 'mandatory', 'povinn', "обов'яз", 'обовʼяз', 'обов’яз']);
    }

    /** «Производитель», «Модель / Part Number»: fields of the product, not characteristics. */
    public static function isProductField(string $name): bool
    {
        $words = ['производитель', 'бренд', 'brand', 'manufacturer', 'výrobce', 'značka', 'виробник', 'модель', 'model', 'part number', 'p/n', 'pn', 'mpn'];
        foreach (explode('/', mb_strtolower($name)) as $part) {
            if (in_array(trim($part), $words, true)) {
                return true;
            }
        }

        return false;
    }
}
