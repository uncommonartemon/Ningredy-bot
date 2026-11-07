<?php

namespace App\Models;

use App\Models\Concerns\GeneratesCatalogCode;
use App\Services\Products\CatalogLocales;
use App\Services\Products\ProductPhotoIdentity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class Category extends Model
{
    use GeneratesCatalogCode;

    public const GALLERY_SEARCH_AUTO = 'auto';

    public const GALLERY_SEARCH_VISION_FIRST = 'vision_first';

    public const GALLERY_SEARCH_PLAYWRIGHT_FIRST = 'playwright_first';

    /** What is visible on a component, for the agents that judge its photos (seeded into component categories). */
    public const COMPONENT_PHOTO_IDENTITY_HINT = 'На самой детали есть наклейка с её кодом (P/N, model) и объёмом; виден форм-фактор: длина платы M.2 (2230/2242/2280), SO-DIMM или DIMM, 2.5" или 3.5", сокет процессора. Код на наклейке — это и есть товар: другой код или объём на наклейке — другой товар, даже если адрес страницы содержит нужный код.';

    protected $fillable = [
        'parent_id', 'name', 'slug', 'product_type_affinity', 'icon', 'sort_order', 'is_active', 'gallery_search_strategy',
        'photo_identity', 'photo_identity_hint',
        'minimum_verified_images', 'product_search_hint', 'minimum_image_width', 'minimum_image_height',
    ];

    protected static function booted(): void
    {
        static::saving(function (Category $category): void {
            if (! $category->isDirty('parent_id')) {
                return;
            }
            // A shared ordered lock prevents A->B and B->A racing in two forms.
            self::query()->orderBy('id')->lockForUpdate()->get(['id']);
            if (! $category->acceptsParent($category->parent_id === null ? null : (int) $category->parent_id)) {
                throw ValidationException::withMessages(['parent_id' => __('Категория не может быть родителем самой себя или своего предка.')]);
            }
        });
    }

    public function save(array $options = []): bool
    {
        return DB::transaction(fn (): bool => parent::save($options));
    }

    public function acceptsParent(?int $parentId): bool
    {
        $seen = $this->exists ? [(int) $this->id] : [];
        while ($parentId !== null) {
            if (in_array($parentId, $seen, true)) {
                return false;
            }
            $seen[] = $parentId;
            $parent = self::query()->find($parentId, ['id', 'parent_id']);
            if (! $parent) {
                return false;
            }
            $parentId = $parent->parent_id === null ? null : (int) $parent->parent_id;
        }

        return true;
    }

    /** Also works when repairing legacy cycles: traversal has a visited set. */
    public function excludedParentIds(): array
    {
        if (! $this->exists) {
            return [];
        }
        $ids = [(int) $this->id];
        $frontier = $ids;
        while ($frontier !== []) {
            $frontier = self::query()->whereIn('parent_id', $frontier)->whereNotIn('id', $ids)->pluck('id')->map(fn ($id): int => (int) $id)->all();
            $ids = [...$ids, ...$frontier];
        }

        return $ids;
    }

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'minimum_verified_images' => 'integer',
            'minimum_image_width' => 'integer',
            'minimum_image_height' => 'integer',
        ];
    }

    public function gallerySearchStrategy(): string
    {
        return in_array($this->gallery_search_strategy, self::gallerySearchStrategies(), true)
            ? $this->gallery_search_strategy
            : self::GALLERY_SEARCH_AUTO;
    }

    /** @return array<int, string> */
    public static function gallerySearchStrategies(): array
    {
        return [
            self::GALLERY_SEARCH_AUTO,
            self::GALLERY_SEARCH_VISION_FIRST,
            self::GALLERY_SEARCH_PLAYWRIGHT_FIRST,
        ];
    }

    /** What identifies a product of this category in a photograph: its page and body, or its own markings. */
    public function photoIdentity(): string
    {
        return in_array($this->photo_identity, ProductPhotoIdentity::OPTIONS, true)
            ? $this->photo_identity
            : ProductPhotoIdentity::PAGE;
    }

    public function minimumVerifiedImages(): int
    {
        return max(1, min(10, (int) ($this->minimum_verified_images ?: 3)));
    }

    /** Null means "use the global AI settings default" - not every category needs its own override. */
    public function minimumImageWidth(): ?int
    {
        return $this->minimum_image_width !== null
            ? max(100, min(4000, (int) $this->minimum_image_width))
            : null;
    }

    /** Null means "use the global AI settings default" - not every category needs its own override. */
    public function minimumImageHeight(): ?int
    {
        return $this->minimum_image_height !== null
            ? max(0, min(4000, (int) $this->minimum_image_height))
            : null;
    }

    /**
     * The category as research reads it: a kind under its parent, the product
     * type it accepts, the operator's search hint and the keys a card of this
     * category must carry (what research cannot find, the card names as missing).
     * Load `parent` and `requiredAttributeDefinitions` before calling for many rows.
     */
    public function productSearchPromptLine(): string
    {
        $hint = trim((string) $this->product_search_hint);
        $parent = $this->parent_id === null ? null : $this->parent;
        $required = $this->requiredAttributeDefinitions
            ->map(fn (AttributeDefinition $definition): string => $definition->key.' ('.(
                is_array($definition->translations) && filled($definition->translations[CatalogLocales::CONTENT_SOURCE] ?? null)
                    ? $definition->translations[CatalogLocales::CONTENT_SOURCE] : $definition->label).')')
            ->implode(', ');

        return '- '.$this->slug.': '.$this->name
            .($parent ? ' (a kind of '.$parent->slug.': prefer it over the parent when it fits)' : '')
            .($this->product_type_affinity ? ' | product_type='.$this->product_type_affinity : '')
            .($hint !== '' ? ' | trusted category search hint: '.$hint : '')
            .($required !== '' ? ' | required specifications, use these exact keys: '.$required : '');
    }

    /**
     * This category and every category under it (a kind of component belongs to
     * «Комплектующие» on the storefront as well). Inactive rows are left out.
     *
     * @return list<int>
     */
    public static function treeIdsBySlug(string $slug): array
    {
        $root = self::query()->where('slug', $slug)->first(['id']);
        if ($root === null) {
            return [];
        }
        $ids = [(int) $root->id];
        $frontier = $ids;
        while ($frontier !== []) {
            $frontier = self::query()->where('is_active', true)->whereIn('parent_id', $frontier)->whereNotIn('id', $ids)
                ->pluck('id')->map(fn ($id): int => (int) $id)->all();
            $ids = [...$ids, ...$frontier];
        }

        return $ids;
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort_order');
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function translations(): HasMany
    {
        return $this->hasMany(CategoryTranslation::class)->orderBy('locale');
    }

    public function attributeDefinitions(): BelongsToMany
    {
        return $this->belongsToMany(AttributeDefinition::class)
            ->withPivot(['is_required', 'sort_order', 'is_default'])
            ->orderByPivot('sort_order');
    }

    /** The characteristics every card of this category must carry, in the category's order. */
    public function requiredAttributeDefinitions(): BelongsToMany
    {
        return $this->attributeDefinitions()->wherePivot('is_required', true);
    }

    /** Marks exactly these characteristics as required; the rest of the category's set stays as it is. */
    public function syncRequiredAttributes(array $ids): void
    {
        DB::transaction(function () use ($ids): void {
            $this->attributeDefinitions()->newPivotQuery()->update(['is_required' => false]);
            $validIds = AttributeDefinition::query()->whereIn('id', $ids)->pluck('id')->all();
            $next = (int) $this->attributeDefinitions()->newPivotQuery()->max('sort_order') + 1;
            foreach (array_values(array_unique($ids)) as $id) {
                if (in_array($id, $validIds)) {
                    $attached = $this->attributeDefinitions()->newPivotQuery()->where('attribute_definition_id', $id)->exists();
                    $this->attributeDefinitions()->syncWithoutDetaching([$id => ['is_required' => true, ...($attached ? [] : ['sort_order' => $next++])]]);
                }
            }
        });
    }

    public function syncDefaultAttributes(array $ids): void
    {
        DB::transaction(function () use ($ids): void {
            $this->attributeDefinitions()->newPivotQuery()->update(['is_default' => false]);
            $validIds = AttributeDefinition::query()->whereIn('id', $ids)->pluck('id')->all();
            foreach (array_values(array_unique($ids)) as $sort => $id) {
                if (in_array($id, $validIds)) {
                    $this->attributeDefinitions()->syncWithoutDetaching([$id => ['is_default' => true, 'sort_order' => $sort]]);
                }
            }
        });
    }
}
