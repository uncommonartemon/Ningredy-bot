<?php

namespace App\Services\Photos;

use App\Models\Category;
use App\Models\ProductDraft;
use App\Services\Ai\AiSettings;
use App\Services\Products\ProductRequestContext;

/** The same category requirements travel with a page in initial search and top-up. */
class GallerySourceContext
{
    public function __construct(private readonly AiSettings $settings) {}

    public function forDraft(ProductDraft $draft, array $source, ?int $minimumImages = null): array
    {
        $slug = trim((string) $draft->category);
        $category = $slug === '' ? null : Category::where('slug', $slug)->first();

        return [
            ...$source,
            ProductRequestContext::SOURCE_KEY => $draft->id,
            '_category_slug' => $slug !== '' ? $slug : null,
            '_minimum_verified_images' => $minimumImages ?? $category?->minimumVerifiedImages() ?? 3,
            '_minimum_image_width' => $category?->minimumImageWidth() ?? $this->settings->imageMinimumWidth(),
            '_minimum_image_height' => $category?->minimumImageHeight() ?? $this->settings->imageMinimumHeight(),
        ];
    }
}
