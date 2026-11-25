<?php

namespace App\Services\Catalog;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Support\Collection;

/**
 * The ways into the catalog by category, one reading for the start page and the catalog's tiles:
 * the active categories, how many visible products each holds with the kinds under it, the first
 * level under a category that offers a choice, the path from the top to a category and the names
 * a category's icon may be found by.
 */
final class CategoryWays
{
    /**
     * @param  Collection<int, Category>  $categories  the active categories in the shop's order
     * @param  Collection<int, int>  $filed  category id => visible products filed straight under it
     */
    public function __construct(private readonly Collection $categories, private readonly Collection $filed) {}

    /** The active categories and the visible products, read once. */
    public static function read(): self
    {
        return new self(self::activeCategories(), Product::query()->visibleInCatalog()
            ->selectRaw('category_id, COUNT(*) as products')->groupBy('category_id')->pluck('products', 'category_id')
            ->map(fn (mixed $count): int => (int) $count));
    }

    /** @return Collection<int, Category> */
    public static function activeCategories(): Collection
    {
        return Category::query()->where('is_active', true)
            ->with('translations:id,category_id,locale,name')
            ->orderBy('sort_order')->orderBy('id')->get(['id', 'name', 'slug', 'parent_id', 'icon']);
    }

    /** @return Collection<int, Category> */
    public function categories(): Collection
    {
        return $this->categories;
    }

    public function find(string $slug): ?Category
    {
        return $slug === '' ? null : $this->categories->firstWhere('slug', $slug);
    }

    /**
     * The category and the active kinds under it.
     *
     * @return list<int>
     */
    public function treeIds(Category $root): array
    {
        $ids = [(int) $root->id];
        $frontier = $ids;
        while ($frontier !== []) {
            $frontier = $this->categories->whereIn('parent_id', $frontier)->pluck('id')
                ->map(fn ($id): int => (int) $id)->reject(fn (int $id): bool => in_array($id, $ids, true))->values()->all();
            $ids = [...$ids, ...$frontier];
        }

        return $ids;
    }

    /** Visible products of the category and the kinds under it. */
    public function count(Category $category): int
    {
        return (int) collect($this->treeIds($category))->sum(fn (int $id): int => (int) ($this->filed[$id] ?? 0));
    }

    /**
     * The first level under a category (the top one for null) that offers a choice. A level of one
     * category is replaced by the kinds under it; when some products are filed straight under that
     * category it stays and its kinds follow it, so no product is lost from the list.
     *
     * @return Collection<int, array{category: Category, count: int}>
     */
    public function choice(?Category $parent): Collection
    {
        $level = $this->withProducts($parent);
        while ($level->count() === 1) {
            $below = $this->withProducts($level->first()['category']);
            if ($below->isEmpty()) {
                break;
            }
            if ($below->sum('count') < $level->first()['count']) {
                $level = $level->concat($below)->values();
                break;
            }
            $level = $below;
        }

        return $level;
    }

    /**
     * The path from the top category down to this one, itself last.
     *
     * @return Collection<int, Category>
     */
    public function trail(Category $category): Collection
    {
        $trail = [];
        for ($current = $category, $depth = 0; $current !== null && $depth < 8; $depth++) {
            array_unshift($trail, $current);
            $current = $this->parent($current);
        }

        return collect($trail);
    }

    /** @return list<string> the category's icon and address, then each parent's, nearest first */
    public function icons(Category $category): array
    {
        $names = [];
        for ($current = $category, $depth = 0; $current !== null && $depth < 8; $depth++) {
            $parent = $this->parent($current);
            // An icon the same as the parent's says nothing of the category itself: its address is asked first.
            if ((string) $current->icon !== (string) $parent?->icon) {
                $names[] = (string) $current->icon;
            }
            $names[] = (string) $current->slug;
            $current = $parent;
        }

        return array_values(array_filter($names, fn (string $name): bool => $name !== ''));
    }

    /** @return array{name: string, slug: string, translations: array<string, string>} */
    public static function name(Category $category): array
    {
        return ['name' => (string) $category->name, 'slug' => (string) $category->slug,
            'translations' => $category->translations->pluck('name', 'locale')->all()];
    }

    private function parent(Category $category): ?Category
    {
        return $category->parent_id === null ? null : $this->categories->firstWhere('id', (int) $category->parent_id);
    }

    /** @return Collection<int, array{category: Category, count: int}> the categories under the parent that hold products */
    private function withProducts(?Category $parent): Collection
    {
        $level = $parent === null ? $this->categories->whereNull('parent_id') : $this->categories->where('parent_id', $parent->id);

        return $level->map(fn (Category $category): array => ['category' => $category, 'count' => $this->count($category)])
            ->filter(fn (array $entry): bool => $entry['count'] > 0)->values();
    }
}
