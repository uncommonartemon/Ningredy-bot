<?php

namespace App\Services\Catalog;

use App\Models\AttributeDefinition;
use App\Models\AttributeValue;
use App\Models\Product;
use App\Services\Products\ManualProductAttributes;
use Database\Seeders\StarterCatalogSeeder;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * One entry per meaning in the characteristic dictionary (decision of 2026-11-13). Cards came
 * in with the same property under new names (`memory_size` beside `ram`) because each path
 * looked a characteristic up by its exact key only. Every path now finds it here first: by its
 * key, by another name it was given, or by its name in any language. What this cannot settle
 * goes to AttributeKeeper, the model that reads the whole dictionary; its "same" is kept as
 * another name, so the next card needs no model and a merged characteristic stays merged.
 */
class AttributeDictionary
{
    /** @var Collection<int, AttributeDefinition>|null */
    private ?Collection $definitions = null;

    /** The dictionary's form of a name: lowercase, one space for spaces, underscores and dashes. */
    public static function normalize(string $text): string
    {
        return trim((string) preg_replace('/[\s_\-]+/u', ' ', mb_strtolower(trim($text))));
    }

    /**
     * Keys the code and the starter catalog rely on. A merge keeps them: the starter catalog
     * would create a removed one again, and the code reads them by name.
     *
     * @return list<string>
     */
    public static function protectedKeys(): array
    {
        return array_values(array_unique([...StarterCatalogSeeder::attributeKeys(), ...AttributeDefinition::IDENTIFIER_KEYS]));
    }

    /** @return Collection<int, AttributeDefinition> */
    public function all(): Collection
    {
        return $this->definitions ??= AttributeDefinition::query()->with('group')->orderBy('id')->get();
    }

    public function forget(): void
    {
        $this->definitions = null;
    }

    /**
     * The characteristic a key or name certainly means: its own key, the key written another
     * way (`screen-size`), or a name it was given as another name.
     */
    public function known(?string $key, ?string $name = null): ?AttributeDefinition
    {
        $key = trim((string) $key);
        if ($key !== '' && ($exact = $this->all()->firstWhere('key', $key)) !== null) {
            return $exact;
        }
        foreach (array_filter([$key, trim((string) $name)], fn (string $text): bool => $text !== '') as $index => $text) {
            $normalized = self::normalize($text);
            $matches = $this->all()->filter(fn (AttributeDefinition $definition): bool => in_array($normalized, (array) ($definition->aliases ?? []), true)
                || ($index === 0 && self::normalize((string) $definition->key) === $normalized));
            if ($matches->count() === 1) {
                return $matches->first();
            }
        }

        return null;
    }

    /** The characteristic a name probably means: exactly one has it as a name in some language. */
    public function named(?string $name): ?AttributeDefinition
    {
        $normalized = self::normalize((string) $name);
        if ($normalized === '') {
            return null;
        }
        $matches = $this->all()->filter(fn (AttributeDefinition $definition): bool => in_array($normalized, $this->names($definition), true));

        return $matches->count() === 1 ? $matches->first() : null;
    }

    /** The characteristic a key or name means, certainly or probably; null when none or several. */
    public function find(?string $key, ?string $name = null): ?AttributeDefinition
    {
        return $this->known($key, $name) ?? $this->named($name) ?? $this->named($key);
    }

    /**
     * Every name a characteristic answers to, in the dictionary's form.
     *
     * @return list<string>
     */
    public function names(AttributeDefinition $definition): array
    {
        $names = [$definition->key, $definition->label, __((string) $definition->label),
            ...array_values((array) ($definition->translations ?? [])), ...((array) ($definition->aliases ?? []))];

        return array_values(array_unique(array_filter(array_map(
            fn (mixed $name): string => is_string($name) ? self::normalize($name) : '', $names))));
    }

    /** Names the characteristic answers to from now on. A name another characteristic answers to is left out. */
    public function remember(AttributeDefinition $definition, string ...$names): void
    {
        $taken = $this->all()->reject(fn (AttributeDefinition $other): bool => (int) $other->id === (int) $definition->id)
            ->flatMap(fn (AttributeDefinition $other): array => $this->names($other))->flip();
        $own = $this->names($definition);
        $new = collect($names)->map(fn (string $name): string => self::normalize($name))
            ->filter(fn (string $name): bool => $name !== '' && ! in_array($name, $own, true) && ! $taken->has($name))
            ->unique()->values()->all();
        if ($new !== []) {
            $definition->update(['aliases' => [...((array) ($definition->aliases ?? [])), ...$new]]);
        }
    }

    /**
     * Where each characteristic of a card goes: the key it keeps, the name a new one gets, the
     * group it is offered and the names to remember. A characteristic already under a key of
     * the dictionary keeps it. The others follow the keeper's verdict, else a certain match,
     * else a probable one, else their own key as before. One entry takes one characteristic of
     * a card: two of them on one card were two properties to whoever named them, so the second
     * one keeps its own key rather than overwrite the first one's value.
     *
     * @param  list<array{index: int|string, key: string, name: string, value: string}>  $rows
     * @param  array<string, array{decision: string, key: string, name: ?string, group: ?array, remember: bool}>  $verdicts  by the row's key
     * @return array<int|string, array{key: string, name: ?string, group: ?array, remember: list<string>}> by the row's index
     */
    public function place(array $rows, array $verdicts = []): array
    {
        $claimed = [];
        foreach ($rows as $row) {
            if ($this->all()->firstWhere('key', $row['key']) !== null) {
                $claimed[$row['key']] = true;
            }
        }
        $places = [];
        foreach ($rows as $row) {
            $verdict = $verdicts[$row['key']] ?? null;
            $group = $verdict['group'] ?? null;
            if ($this->all()->firstWhere('key', $row['key']) !== null) {
                $places[$row['index']] = ['key' => $row['key'], 'name' => null, 'group' => $group, 'remember' => []];

                continue;
            }
            $known = $this->known($row['key'], $row['name']);
            [$target, $name, $remember] = match (true) {
                $known !== null => [$known->key, null, []],
                $verdict !== null && in_array($verdict['decision'], ['same', 'new'], true) => [$verdict['key'],
                    $verdict['decision'] === 'new' ? $verdict['name'] : null,
                    $verdict['remember'] ? [$row['key'], $row['name']] : []],
                default => [($this->named($row['name']) ?? $this->named($row['key']))?->key ?? $row['key'], null, []],
            };
            if ($target !== $row['key'] && isset($claimed[$target])) {
                [$target, $name, $remember, $group] = [$row['key'], null, [], null];
            }
            $claimed[$target] = true;
            $places[$row['index']] = ['key' => $target, 'name' => $name, 'group' => $group, 'remember' => $remember];
        }

        return $places;
    }

    /**
     * The whole dictionary for a prompt: one entry per characteristic with its English name and
     * the other names it answers to. The most used first, at most 400.
     */
    public function vocabulary(): string
    {
        return AttributeDefinition::query()->withCount('values')->orderByDesc('values_count')->orderBy('key')->limit(400)->get()
            ->map(fn (AttributeDefinition $definition): string => $definition->key.' ('.($definition->translations['en'] ?? $definition->label)
                .($definition->aliases ? '; also: '.implode(', ', array_slice($definition->aliases, 0, 4)) : '').')')
            ->implode(', ');
    }

    /**
     * The dictionary as the keeper reads it: each entry with its group, unit, two example values,
     * how often it is used, in which categories, and its other names.
     *
     * @return list<array<string, mixed>>
     */
    public function entries(): array
    {
        // Its values as they are written, the most frequent first, and how many products carry it:
        // a model that sees only a name cannot tell a module's capacity from a drive's.
        $values = DB::table('attribute_values')->selectRaw('attribute_definition_id, value, unit, COUNT(*) as n')
            ->groupBy('attribute_definition_id', 'value', 'unit')->get()->groupBy('attribute_definition_id');
        $products = $this->ownedValues()->selectRaw('attribute_values.attribute_definition_id as definition, COUNT(DISTINCT '.self::OWNER.') as n')
            ->groupBy('attribute_values.attribute_definition_id')->pluck('n', 'definition');
        $categories = DB::table('attribute_definition_category')->join('categories', 'categories.id', '=', 'attribute_definition_category.category_id')
            ->get(['attribute_definition_category.attribute_definition_id as definition', 'categories.slug'])->groupBy('definition');

        return $this->all()->map(function (AttributeDefinition $definition) use ($values, $products, $categories): array {
            $written = $values->get($definition->id, collect());

            return array_filter([
                'key' => $definition->key,
                'name' => (string) ($definition->translations['en'] ?? $definition->label),
                'group' => $definition->group?->name,
                'unit' => $definition->default_unit,
                'values' => $written->sortByDesc('n')->take(8)
                    ->map(fn (object $row): string => mb_substr(trim($row->value.' '.($row->unit ?? '')), 0, 60).' ×'.$row->n)->values()->all(),
                'used' => (int) $written->sum('n'),
                'products' => (int) ($products[$definition->id] ?? 0),
                'categories' => $categories->get($definition->id)?->pluck('slug')->unique()->values()->all(),
                'also' => $definition->aliases,
            ], fn (mixed $value): bool => $value !== null && $value !== [] && $value !== '');
        })->values()->all();
    }

    /** The product a value belongs to: a configuration's value belongs to its product. */
    private const string OWNER = 'COALESCE(attribute_values.product_id, product_variants.product_id)';

    private function ownedValues(): Builder
    {
        return DB::table('attribute_values')
            ->leftJoin('product_variants', 'product_variants.id', '=', 'attribute_values.product_variant_id')
            ->join('attribute_definitions', 'attribute_definitions.id', '=', 'attribute_values.attribute_definition_id');
    }

    /**
     * Everything about one characteristic: every value as written with its count, the products
     * that carry it, its categories, and which characteristics stand on the same products.
     *
     * @return array<string, mixed>|null
     */
    public function detail(string $key): ?array
    {
        $definition = $this->known($key);
        if ($definition === null) {
            return null;
        }
        $base = fn (): Builder => $this->ownedValues()->where('attribute_values.attribute_definition_id', $definition->id);
        $productIds = $base()->selectRaw('DISTINCT '.self::OWNER.' as id')->pluck('id')->filter()->values();
        $links = DB::table('attribute_definition_category')->join('categories', 'categories.id', '=', 'attribute_definition_category.category_id')
            ->where('attribute_definition_id', $definition->id)->get(['categories.slug', 'attribute_definition_category.is_required']);

        return [
            'key' => $definition->key,
            'label' => $definition->label,
            'names' => $definition->translations,
            'also' => $definition->aliases,
            'group' => $definition->group?->name,
            'type' => $definition->data_type,
            'unit' => $definition->default_unit,
            'values' => $base()->selectRaw('attribute_values.value, attribute_values.unit, COUNT(*) as n')
                ->groupBy('attribute_values.value', 'attribute_values.unit')->orderByDesc('n')->limit(60)->get()
                ->map(fn (object $row): string => mb_substr(trim($row->value.' '.($row->unit ?? '')), 0, 120).' ×'.$row->n)->all(),
            'products_count' => $productIds->count(),
            'products' => Product::query()->with('category:id,slug')->whereIn('id', $productIds->take(25))->get(['id', 'title', 'category_id'])
                ->map(fn (Product $product): string => '#'.$product->id.' '.$product->title.' ['.$product->category?->slug.']')->all(),
            'categories' => $links->pluck('slug')->unique()->values()->all(),
            'required_in' => $links->where('is_required', true)->pluck('slug')->values()->all(),
            'on_the_same_products_as' => $productIds->isEmpty() ? [] : $this->ownedValues()
                ->whereIn(DB::raw(self::OWNER), $productIds)->where('attribute_values.attribute_definition_id', '!=', $definition->id)
                ->selectRaw('attribute_definitions.key, COUNT(DISTINCT '.self::OWNER.') as n')->groupBy('attribute_definitions.key')
                ->orderByDesc('n')->limit(60)->pluck('n', 'key')->all(),
        ];
    }

    /**
     * Several characteristics side by side on the products that carry more than one of them. A
     * product with two of them at different values had them as two properties.
     *
     * @param  list<string>  $keys
     * @return array<string, mixed>
     */
    public function compare(array $keys): array
    {
        $definitions = collect($keys)->map(fn (mixed $key): ?AttributeDefinition => $this->known((string) $key))->filter()->unique('id');
        if ($definitions->count() < 2) {
            return ['error' => 'Name at least two existing keys.'];
        }
        $rows = $this->ownedValues()->whereIn('attribute_values.attribute_definition_id', $definitions->pluck('id'))
            ->selectRaw(self::OWNER.' as product, attribute_definitions.key as k, attribute_values.value as v, attribute_values.unit as u')->get();
        $several = $rows->groupBy('product')->filter(fn ($group): bool => $group->pluck('k')->unique()->count() > 1);
        $plain = fn (object $row): string => (string) preg_replace('/\s+/u', '', mb_strtolower(trim($row->v.' '.($row->u ?? ''))));
        $titles = Product::query()->whereIn('id', $several->keys()->take(25))->pluck('title', 'id');

        return [
            'products_per_key' => $rows->groupBy('k')->map(fn ($group): int => $group->pluck('product')->unique()->count())->all(),
            'products_with_several' => $several->count(),
            'products_where_they_differ' => $several->filter(fn ($group): bool => $group->map($plain)->unique()->count() > 1)->count(),
            'side_by_side' => $several->take(25)->map(fn ($group, $product): array => ['product' => (string) ($titles[$product] ?? '#'.$product)]
                + $group->mapWithKeys(fn (object $row): array => [$row->k => trim($row->v.' '.($row->u ?? ''))])->all())->values()->all(),
        ];
    }

    /** How many products carry both characteristics at different values. */
    public function conflicts(string $from, string $into): int
    {
        return (int) ($this->compare([$from, $into])['products_where_they_differ'] ?? 0);
    }

    /**
     * Moves every value, category link and name of one characteristic into another and removes
     * the first. A value the product already has under the other one stays as it is. A key the
     * code or the starter catalog relies on is never the one removed: the merge goes the other way.
     *
     * @return array{from: string, into: string, moved: int, dropped: int, swapped: bool}
     */
    public function merge(AttributeDefinition $from, AttributeDefinition $into): array
    {
        throw_if((int) $from->id === (int) $into->id, RuntimeException::class, __('Это одна и та же характеристика.'));
        $swapped = false;
        if (in_array($from->key, self::protectedKeys(), true)) {
            throw_if(in_array($into->key, self::protectedKeys(), true), RuntimeException::class,
                __('Обе характеристики — :from и :into — встроенные: на них опирается код, их нельзя объединить.', ['from' => $from->key, 'into' => $into->key]));
            [$from, $into, $swapped] = [$into, $from, true];
        }

        $result = DB::transaction(function () use ($from, $into): array {
            $moved = $dropped = 0;
            $normalizer = app(ManualProductAttributes::class);
            foreach (AttributeValue::query()->where('attribute_definition_id', $from->id)->get() as $value) {
                $taken = AttributeValue::query()->where('attribute_definition_id', $into->id)
                    ->where(fn ($query) => $query
                        ->when($value->product_id !== null, fn ($query) => $query->orWhere('product_id', $value->product_id))
                        ->when($value->product_variant_id !== null, fn ($query) => $query->orWhere('product_variant_id', $value->product_variant_id)))
                    ->exists();
                if ($taken) {
                    $value->delete();
                    $dropped++;

                    continue;
                }
                $typed = $normalizer->normalize(['attribute_definition_id' => $into->id, 'value' => $value->value, 'unit' => $value->unit]) ?? [];
                $value->forceFill([...array_intersect_key($typed, array_flip(['normalized_value', 'numeric_value', 'boolean_value', 'unit'])),
                    'attribute_definition_id' => $into->id])->save();
                $moved++;
            }

            $links = DB::table('attribute_definition_category');
            foreach ((clone $links)->where('attribute_definition_id', $from->id)->get() as $link) {
                $existing = (clone $links)->where('attribute_definition_id', $into->id)->where('category_id', $link->category_id)->first();
                if ($existing !== null) {
                    (clone $links)->where('attribute_definition_id', $into->id)->where('category_id', $link->category_id)->update([
                        'is_required' => (bool) $existing->is_required || (bool) $link->is_required,
                        'is_default' => (bool) ($existing->is_default ?? false) || (bool) ($link->is_default ?? false),
                    ]);
                    (clone $links)->where('attribute_definition_id', $from->id)->where('category_id', $link->category_id)->delete();
                } else {
                    (clone $links)->where('attribute_definition_id', $from->id)->where('category_id', $link->category_id)
                        ->update(['attribute_definition_id' => $into->id]);
                }
            }

            foreach (Product::query()->where('card_attribute_keys', 'like', '%"'.$from->key.'"%')->get() as $product) {
                $product->forceFill(['card_attribute_keys' => array_values(array_unique(array_map(
                    fn (string $key): string => $key === $from->key ? $into->key : $key, (array) $product->card_attribute_keys)))])->saveQuietly();
            }

            $names = $this->names($from);
            $into->forceFill([
                'attribute_group_id' => $into->attribute_group_id ?? $from->attribute_group_id,
                'default_unit' => $into->default_unit ?? $from->default_unit,
                'translations' => [...((array) ($from->translations ?? [])), ...((array) ($into->translations ?? []))] ?: null,
            ])->save();
            $from->delete();
            $this->forget();
            $this->remember($into->fresh(), ...$names);

            return ['moved' => $moved, 'dropped' => $dropped];
        });

        return ['from' => $from->key, 'into' => $into->key, ...$result, 'swapped' => $swapped];
    }
}
