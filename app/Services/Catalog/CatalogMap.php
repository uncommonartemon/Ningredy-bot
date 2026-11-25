<?php

namespace App\Services\Catalog;

use App\Models\AttributeDefinition;
use App\Models\Category;
use Database\Seeders\StarterCatalogSeeder;

/**
 * The one description of where products go (decision of 2026-11-25): the active categories as a
 * tree, parents with their kinds to any depth, in the operator's order. Every agent and chat path
 * that chooses or changes a product's category reads this map and takes its list of slugs from it,
 * so the search, the bot and the admin place one product in one place.
 *
 * The map itself stays small - one line per node. What agents need to fill a card of a category
 * (its form, the operator's search hint and note) is a block of its own, keyed by slug.
 *
 * Built from the database each time it is resolved: a category the operator adds is in the next
 * prompt, with no cache to forget.
 */
class CatalogMap
{
    /** @var array<int, array{id: int, slug: string, name: string, phrase: ?string, type: ?string, parent: ?int, depth: int, kinds: list<int>}>|null */
    private ?array $nodes = null;

    /**
     * The map as agents read it: the rule, then one line per node, kinds indented under their parent.
     * With ids for the paths that write category_id (the chat, the admin's form assistant).
     */
    public function forAgents(bool $withIds = false): string
    {
        $lines = [
            'Catalog map - where products go (slug'.($withIds ? ' and id' : '').': name [product_type]):',
            'Put a product in the deepest node that fits it. A node with kinds under it is chosen only when none of its kinds fits.',
            'category and product_type never contradict: a node\'s product_type is in brackets, and a kind has its parent\'s unless it shows its own.',
        ];
        foreach ($this->nodes() as $node) {
            $parent = $node['parent'] === null ? null : $this->nodes()[$node['parent']];
            $type = $parent === null || $parent['type'] !== $node['type'] ? ' ['.($node['type'] ?? 'any type').']' : '';
            $lines[] = str_repeat('  ', $node['depth']).'- '.$node['slug'].($withIds ? ' (id '.$node['id'].')' : '')
                .': '.$node['name'].($node['phrase'] !== null ? ' / '.$node['phrase'] : '').$type;
        }
        if ($withIds && ($waiting = $this->nodesWithKinds()) !== []) {
            $lines[] = 'A product placed directly in a node with kinds waits for its kind: '
                .implode(', ', array_map(fn (array $node): string => $node['slug'].' (id '.$node['id'].')', $waiting)).'.';
        }

        return implode("\n", $lines);
    }

    /**
     * What a card of each category carries, by slug: the operator's form with how each field is
     * written and whether it is required, the operator's search hint and note. A kind without a form
     * of its own fills its parent's. Nothing the operator entered is left out.
     */
    public function formsForAgents(): string
    {
        $categories = Category::query()->whereIn('id', array_keys($this->nodes()))->with('formDefinitions')
            ->get(['id', 'slug', 'product_search_hint', 'card_standard'])->keyBy('id');
        $lines = [];
        foreach ($this->nodes() as $id => $node) {
            $category = $categories->get($id);
            if ($category === null) {
                continue;
            }
            $form = $category->formDefinitions->map(fn (AttributeDefinition $definition): string => $definition->key
                .' ('.($definition->translations['en'] ?? $definition->label)
                .(filled($definition->pivot->example) ? '; write like: '.$definition->pivot->example : '')
                .($definition->pivot->is_required ? '; required' : '').')')->implode(', ');
            $parts = array_filter([
                filled($category->product_search_hint) ? 'search hint: '.trim((string) $category->product_search_hint) : null,
                $form !== '' ? 'form: '.$form : null,
                filled($category->card_standard) ? 'note: '.trim((string) $category->card_standard) : null,
            ]);
            if ($parts !== []) {
                $lines[] = '- '.$node['slug'].': '.implode(' | ', $parts);
            }
        }
        if ($lines === []) {
            return '';
        }

        return implode("\n", [
            'Category forms, by slug (the operator\'s hints, forms and notes). Write each form field you find under exactly its key;',
            'a field no page shows is left out, never guessed or taken from a similar product - the card says it is missing.',
            'A kind without a form of its own fills its parent\'s.',
            ...$lines,
        ]);
    }

    /**
     * The slugs an agent may return, in the map's order. Never empty: a schema enum with no value
     * is a request no answer can satisfy (live 2026-11-01, after a cleanup removed every category).
     *
     * @return list<string>
     */
    public function slugs(): array
    {
        return array_values(array_column($this->nodes(), 'slug')) ?: array_keys(StarterCatalogSeeder::CATEGORIES);
    }

    /** @return array{id: int, slug: string, name: string, phrase: ?string, type: ?string, parent: ?int, depth: int, kinds: list<int>}|null */
    public function node(string $slug): ?array
    {
        foreach ($this->nodes() as $node) {
            if ($node['slug'] === $slug) {
                return $node;
            }
        }

        return null;
    }

    /**
     * The kinds directly under a node.
     *
     * @return list<array{id: int, slug: string, name: string, phrase: ?string, type: ?string, parent: ?int, depth: int, kinds: list<int>}>
     */
    public function kinds(string $slug): array
    {
        return array_map(fn (int $id): array => $this->nodes()[$id], $this->node($slug)['kinds'] ?? []);
    }

    public function hasKinds(string $slug): bool
    {
        return $this->kinds($slug) !== [];
    }

    /**
     * The nodes that have kinds: a product placed directly in one of them waits for its kind.
     *
     * @return list<array{id: int, slug: string, name: string, phrase: ?string, type: ?string, parent: ?int, depth: int, kinds: list<int>}>
     */
    public function nodesWithKinds(): array
    {
        return array_values(array_filter($this->nodes(), fn (array $node): bool => $node['kinds'] !== []));
    }

    /** «Комплектующие › Оперативная память»: the node's name under its parents. */
    public function path(string $slug): string
    {
        $node = $this->node($slug);
        $names = [];
        while ($node !== null) {
            array_unshift($names, $node['name']);
            $node = $node['parent'] === null ? null : $this->nodes()[$node['parent']];
        }

        return implode(' › ', $names);
    }

    /**
     * The places for a product type when nothing settles a deeper one: the highest nodes of that
     * type (a node of the type whose parent is of another type or has none).
     *
     * @return list<array{id: int, slug: string, name: string, phrase: ?string, type: ?string, parent: ?int, depth: int, kinds: list<int>}>
     */
    public function homesOf(string $productType): array
    {
        return array_values(array_filter($this->nodes(), fn (array $node): bool => $node['type'] === $productType
            && ($node['parent'] === null || $this->nodes()[$node['parent']]['type'] !== $productType)));
    }

    /**
     * Category id => its path, in the map's order, for a select in the admin.
     *
     * @return array<int, string>
     */
    public function options(): array
    {
        $options = [];
        foreach ($this->nodes() as $id => $node) {
            $options[$id] = $this->path($node['slug']);
        }

        return $options;
    }

    /**
     * The active categories in the map's order: depth first, siblings by the operator's sort order.
     * A category whose parent is switched off hangs under its nearest active ancestor; one caught in
     * a cycle of parents (a legacy row) stands at the top rather than being lost.
     *
     * @return array<int, array{id: int, slug: string, name: string, phrase: ?string, type: ?string, parent: ?int, depth: int, kinds: list<int>}>
     */
    public function nodes(): array
    {
        if ($this->nodes !== null) {
            return $this->nodes;
        }
        $parents = Category::query()->pluck('parent_id', 'id')->map(fn (mixed $id): ?int => $id === null ? null : (int) $id)->all();
        $active = Category::query()->where('is_active', true)->with('translations')->orderBy('sort_order')->orderBy('id')
            ->get(['id', 'parent_id', 'slug', 'name', 'product_type_affinity'])->keyBy('id');
        $parentOf = [];
        foreach ($active as $id => $category) {
            $seen = [$id => true];
            $parent = $parents[$id] ?? null;
            while ($parent !== null && ! isset($seen[$parent]) && ! $active->has($parent)) {
                $seen[$parent] = true;
                $parent = $parents[$parent] ?? null;
            }
            $parentOf[$id] = $parent !== null && $active->has($parent) && ! isset($seen[$parent]) ? $parent : null;
        }
        $children = [];
        foreach ($active->keys() as $id) {
            $children[$parentOf[$id] ?? 0][] = $id;
        }
        $nodes = [];
        $visit = function (int $id, ?int $parent, int $depth) use (&$visit, &$nodes, $children, $active): void {
            if (isset($nodes[$id])) {
                return;
            }
            $category = $active[$id];
            $english = trim((string) $category->translations->firstWhere('locale', 'en')?->name);
            $nodes[$id] = ['id' => (int) $id, 'slug' => (string) $category->slug, 'name' => (string) $category->name,
                'phrase' => $english !== '' && mb_strtolower($english) !== mb_strtolower((string) $category->name) ? $english : null,
                'type' => $category->product_type_affinity ?: null, 'parent' => $parent, 'depth' => $depth, 'kinds' => []];
            foreach ($children[$id] ?? [] as $child) {
                if (! isset($nodes[$child])) {
                    $nodes[$id]['kinds'][] = (int) $child;
                    $visit($child, (int) $id, $depth + 1);
                }
            }
        };
        foreach ($children[0] ?? [] as $root) {
            $visit($root, null, 0);
        }
        foreach ($active->keys() as $id) {
            $visit($id, null, 0);
        }

        return $this->nodes = $nodes;
    }
}
