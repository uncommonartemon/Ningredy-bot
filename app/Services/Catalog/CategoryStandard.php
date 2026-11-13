<?php

namespace App\Services\Catalog;

use App\Models\AttributeDefinition;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductDraft;
use App\Services\Products\AiAttributeGroups;
use App\Services\Products\CatalogLocales;
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

    /**
     * The category's standard as the research and the card assembly read it: the form with the
     * operator's examples and importance, and the note. Null when the category has neither.
     *
     * @return array<string, mixed>|null
     */
    public function forAgents(?Category $category): ?array
    {
        $form = $this->form($category);
        $note = trim((string) ($category?->standardSource('note')?->card_standard ?? ''));
        if ($form->isEmpty() && $note === '') {
            return null;
        }

        return array_filter([
            'category' => $category?->name,
            'form' => $form->map(fn (AttributeDefinition $definition): array => array_filter([
                'key' => $definition->key,
                'name' => (string) ($definition->translations['en'] ?? $definition->label),
                'example' => $definition->pivot->example,
                'importance' => $definition->pivot->is_required ? 'required' : 'desired',
            ], fn (mixed $value): bool => $value !== null && $value !== ''))->values()->all(),
            'operator_note' => $note === '' ? null : $note,
        ], fn (mixed $value): bool => $value !== null && $value !== []);
    }

    /**
     * Each field of the form against a draft's card: filled, not found, or inexact - the card
     * assembly could not settle it, or the value is none of the variants the example names.
     *
     * @return list<array{key: string, name: string, required: bool, status: string, value: ?string, variants: list<string>, reason: ?string}>
     */
    public function statuses(ProductDraft $draft): array
    {
        $category = filled($draft->category) ? Category::query()->where('slug', $draft->category)->first() : null;
        $form = $this->form($category);
        if ($form->isEmpty()) {
            return [];
        }
        $values = [];
        foreach ((array) ($draft->specifications ?? []) as $specification) {
            if (! is_array($specification) || ! is_scalar($specification['value'] ?? null) || trim((string) $specification['value']) === '') {
                continue;
            }
            $key = $this->dictionary->find(is_string($specification['key'] ?? null) ? $specification['key'] : null,
                is_string($specification['name'] ?? null) ? $specification['name'] : null)?->key ?? (string) ($specification['key'] ?? '');
            $values[$key] ??= trim((string) $specification['value']);
        }
        $unsettled = collect((array) data_get($draft->card_repair_state, 'last_failure.repair_context.unresolved_fields', []))
            ->filter(fn (mixed $field): bool => is_array($field))->keyBy(fn (array $field): string => (string) ($field['key'] ?? ''));
        $accepted = (array) data_get($draft->form_review, 'accepted', []);
        $locale = CatalogLocales::telegramCardLocale();

        return $form->map(function (AttributeDefinition $definition) use ($values, $unsettled, $accepted, $locale): array {
            $variants = self::variants($definition->pivot->example);
            $value = $values[$definition->key] ?? null;
            [$status, $reason] = match (true) {
                $value === null => ['missing', null],
                in_array($definition->key, $accepted, true) => ['filled', null],
                $unsettled->has($definition->key) => ['inexact', (string) ($unsettled[$definition->key]['reason'] ?? '')],
                $variants !== [] && self::variant($value, $variants) === null => ['inexact', 'не из вариантов: '.implode(' / ', $variants)],
                default => ['filled', null],
            };

            return ['key' => $definition->key, 'name' => self::name($definition, $locale), 'required' => (bool) $definition->pivot->is_required,
                'status' => $status, 'value' => $value, 'variants' => $variants, 'reason' => $reason];
        })->values()->all();
    }

    /** The form's line on a draft's card; null when the category has no form. */
    public function reportLine(ProductDraft $draft): ?string
    {
        $statuses = $this->statuses($draft);
        if ($statuses === []) {
            return null;
        }
        $filled = count(array_filter($statuses, fn (array $field): bool => $field['status'] === 'filled'));
        $missing = array_filter($statuses, fn (array $field): bool => $field['status'] === 'missing');
        $inexact = array_filter($statuses, fn (array $field): bool => $field['status'] === 'inexact');
        $category = Category::query()->where('slug', (string) $draft->category)->first();
        $lines = ['🧩 Форма «'.($category?->name ?? $draft->category).'»: '.($missing === [] && $inexact === []
            ? 'заполнена, '.$filled.' из '.count($statuses) : 'заполнено '.$filled.' из '.count($statuses))];
        if ($missing !== []) {
            $lines[] = '❓ Не найдено: '.implode(', ', array_map(fn (array $field): string => $field['name'].($field['required'] ? ' (обязательное)' : ''), $missing));
        }
        if ($inexact !== []) {
            $lines[] = '⚠️ Неточность: '.implode('; ', array_map(fn (array $field): string => $field['name'].' — «'.mb_substr((string) $field['value'], 0, 40).'»'
                .($field['reason'] ? ', '.mb_substr($field['reason'], 0, 80) : ''), $inexact));
        }

        return implode("\n", $lines);
    }

    /**
     * A value written the way the category's standard says: one of the example's variants when
     * the value names exactly one of them, a number with the characteristic's unit spaced and
     * spelled as the characteristic spells it. Anything else stays as it was written.
     */
    public function standardValue(AttributeDefinition $definition, string $value, ?string $example): string
    {
        $value = trim($value);
        $variants = self::variants($example);
        if ($variants !== [] && ($variant = self::variant($value, $variants)) !== null) {
            return $variant;
        }
        $unit = trim((string) ($definition->default_unit ?: self::unitOf($example)));
        if ($unit !== '' && preg_match('/^(\d+(?:[.,]\d+)?)\s*(\p{L}+)$/u', $value, $match) === 1
            && self::sameUnit($match[2], $unit)) {
            return $match[1].' '.$unit;
        }

        return $value;
    }

    /**
     * The four rows a catalog card shows for a category with a form: the first filled fields of
     * its main section, in the form's order. Empty without a form.
     *
     * @return list<string>
     */
    public function cardKeys(Product $product): array
    {
        $form = $this->form($product->category()->first())->filter(fn (AttributeDefinition $definition): bool => $definition->pivot->section !== self::EXTRA);
        if ($form->isEmpty()) {
            return [];
        }
        $product->loadMissing(['attributes.definition', 'variants.attributes.definition']);
        $present = $product->attributes->concat($product->variants->flatMap->attributes)
            ->map(fn ($value): ?string => $value->definition?->key)->filter()->flip();

        return $form->filter(fn (AttributeDefinition $definition): bool => $present->has($definition->key))
            ->take(4)->pluck('key')->values()->all();
    }

    /** @return list<string> the variants an example names: «DIMM / SO-DIMM» */
    public static function variants(?string $example): array
    {
        $parts = array_values(array_filter(array_map('trim', explode('/', (string) $example)), fn (string $part): bool => $part !== ''));

        return count($parts) > 1 && str_contains((string) $example, ' / ') ? $parts : [];
    }

    /** The one variant a value names, compared without case, spaces or dashes; the longest when one contains another. */
    public static function variant(string $value, array $variants): ?string
    {
        $plain = fn (string $text): string => (string) preg_replace('/[\s\-_.]+/u', '', mb_strtolower($text));
        $value = $plain($value);
        $named = array_values(array_filter($variants, fn (string $variant): bool => $plain($variant) !== '' && str_contains($value, $plain($variant))));
        usort($named, fn (string $a, string $b): int => mb_strlen($plain($b)) <=> mb_strlen($plain($a)));
        if ($named === []) {
            return null;
        }
        // «DIMM» inside «SO-DIMM» is not a second variant.
        $longest = $plain($named[0]);
        $others = array_filter(array_slice($named, 1), fn (string $variant): bool => ! str_contains($longest, $plain($variant)));

        return $others === [] ? $named[0] : null;
    }

    private static function unitOf(?string $example): string
    {
        return preg_match('/^\s*\d+(?:[.,]\d+)?\s*(\p{L}+)\s*$/u', (string) $example, $match) === 1 ? $match[1] : '';
    }

    /** The same unit written in Latin or Cyrillic letters, in any case: «гб» is «GB». */
    private static function sameUnit(string $written, string $unit): bool
    {
        $latin = fn (string $text): string => strtr(mb_strtolower($text), ['гб' => 'gb', 'мб' => 'mb', 'тб' => 'tb', 'кб' => 'kb', 'ггц' => 'ghz',
            'мгц' => 'mhz', 'гц' => 'hz', 'вт' => 'w', 'в' => 'v', 'мм' => 'mm', 'г' => 'g', 'дюйм' => 'inch', 'шт' => 'pcs']);

        return $latin($written) === $latin($unit);
    }

    private static function name(AttributeDefinition $definition, string $locale): string
    {
        $name = $definition->translations[$locale] ?? null;

        return is_string($name) && trim($name) !== '' ? trim($name) : (string) __($definition->label);
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
