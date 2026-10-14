<?php

namespace App\Models;

use App\Models\Concerns\GeneratesCatalogCode;
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

    protected $fillable = [
        'parent_id', 'name', 'slug', 'product_type_affinity', 'icon', 'sort_order', 'is_active', 'gallery_search_strategy',
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

    public function productSearchPromptLine(): string
    {
        $hint = trim((string) $this->product_search_hint);

        return '- '.$this->slug.': '.$this->name
            .($this->product_type_affinity ? ' | product_type='.$this->product_type_affinity : '')
            .($hint !== '' ? ' | trusted category search hint: '.$hint : '');
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
