<?php

namespace Tests\Feature;

use App\Models\Category;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CategoryGallerySearchStrategyTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeded_categories_have_the_expected_gallery_search_strategies(): void
    {
        $this->assertSame(
            Category::GALLERY_SEARCH_VISION_FIRST,
            Category::query()->where('slug', 'components')->firstOrFail()->gallerySearchStrategy(),
        );
        $this->assertSame(
            Category::GALLERY_SEARCH_PLAYWRIGHT_FIRST,
            Category::query()->where('slug', 'laptops')->firstOrFail()->gallerySearchStrategy(),
        );
        $this->assertSame(
            Category::GALLERY_SEARCH_PLAYWRIGHT_FIRST,
            Category::query()->where('slug', 'computers')->firstOrFail()->gallerySearchStrategy(),
        );
        $this->assertSame(
            Category::GALLERY_SEARCH_AUTO,
            Category::query()->where('slug', 'other-tech')->firstOrFail()->gallerySearchStrategy(),
        );
        $this->assertSame(1, Category::query()->where('slug', 'components')->firstOrFail()->minimumVerifiedImages());
        $this->assertSame(3, Category::query()->where('slug', 'laptops')->firstOrFail()->minimumVerifiedImages());
    }

    public function test_minimum_image_count_is_clamped_to_a_safe_range(): void
    {
        $category = Category::query()->where('slug', 'other-tech')->firstOrFail();
        $category->forceFill(['minimum_verified_images' => 99])->save();

        $this->assertSame(10, $category->fresh()->minimumVerifiedImages());
    }

    public function test_unknown_database_value_falls_back_to_auto(): void
    {
        $category = Category::query()->where('slug', 'other-tech')->firstOrFail();
        $category->forceFill(['gallery_search_strategy' => 'broken'])->save();

        $this->assertSame(Category::GALLERY_SEARCH_AUTO, $category->fresh()->gallerySearchStrategy());
    }
}
