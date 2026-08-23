<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends Model
{
    public const GALLERY_SEARCH_AUTO = 'auto';

    public const GALLERY_SEARCH_VISION_FIRST = 'vision_first';

    public const GALLERY_SEARCH_PLAYWRIGHT_FIRST = 'playwright_first';

    protected $fillable = [
        'parent_id', 'name', 'slug', 'icon', 'sort_order', 'is_active', 'gallery_search_strategy',
        'minimum_verified_images', 'product_search_hint', 'minimum_image_width', 'minimum_image_height',
    ];

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
            ->withPivot(['is_required', 'sort_order'])
            ->orderByPivot('sort_order');
    }
}
