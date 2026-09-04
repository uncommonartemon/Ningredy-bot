<?php

namespace Tests\Unit;

use App\Services\Products\ProductGalleryRecipeTrainer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

class StaticGalleryMeasurementTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_thumbnail_strip_is_not_a_gallery(): void
    {
        // The failure the distrust of the preflight was written for: a page
        // promised eight photos and Vision confirmed three, because the markup
        // counted thumbnails. Intrinsic width settles it without an argument.
        $page = ['image_candidates' => array_map(fn (int $i): array => [
            'src' => "https://shop.example/thumb-{$i}.jpg",
            'natural_width' => 120,
            'within_media' => true,
        ], range(1, 8))];

        $this->assertSame(0, $this->measure($page));
    }

    public function test_one_photo_served_at_four_sizes_counts_once(): void
    {
        // The other half of the same inflation: renditions of a single frame.
        // They collapse through the asset key the downloader already uses.
        $page = ['image_candidates' => array_map(fn (string $size): array => [
            'src' => "https://cdn.shopify.com/s/files/1/photo_{$size}.jpg",
            'natural_width' => 1600,
            'within_media' => true,
        ], ['600x600', '1024x1024', 'grande', 'master'])];

        $this->assertSame(1, $this->measure($page));
    }

    public function test_a_real_gallery_of_full_size_photos_is_counted(): void
    {
        // Live case (draft #97): the preflight reported eight hires photos, was
        // overridden, and Playwright timed out twice on a page whose gallery was
        // already lying there in full size.
        $page = ['image_candidates' => array_map(fn (int $i): array => [
            'src' => "https://shop.example/hires/product_0{$i}.jpg",
            'natural_width' => 800,
            'within_media' => true,
        ], range(1, 7))];

        $this->assertSame(7, $this->measure($page));
    }

    public function test_photos_outside_the_media_area_do_not_count(): void
    {
        // Recommendations and banners are full-size too, and they are not this
        // product - counting them would trade one wrong verdict for another.
        $page = ['image_candidates' => [
            ['src' => 'https://shop.example/hires/product.jpg', 'natural_width' => 900, 'within_media' => true],
            ['src' => 'https://shop.example/hires/related-1.jpg', 'natural_width' => 900, 'within_media' => false],
            ['src' => 'https://shop.example/hires/related-2.jpg', 'natural_width' => 900, 'within_media' => false],
        ]];

        $this->assertSame(1, $this->measure($page));
    }

    public function test_the_categorys_own_minimum_is_respected(): void
    {
        $page = ['image_candidates' => [
            ['src' => 'https://shop.example/a.jpg', 'natural_width' => 800, 'within_media' => true],
            ['src' => 'https://shop.example/b.jpg', 'natural_width' => 1200, 'within_media' => true],
        ]];

        $this->assertSame(2, $this->measure($page, ['minimum_image_width' => 700]));
        $this->assertSame(1, $this->measure($page, ['minimum_image_width' => 1000]));
    }

    public function test_a_wide_short_banner_is_not_a_photograph(): void
    {
        // Width alone let a banner count as a product photo, and the downloader
        // would have rejected it afterwards - skipping training for nothing.
        $page = ['image_candidates' => [
            ['src' => 'https://shop.example/banner.jpg', 'natural_width' => 1600, 'natural_height' => 200, 'within_media' => true],
            ['src' => 'https://shop.example/photo.jpg', 'natural_width' => 1600, 'natural_height' => 1600, 'within_media' => true],
        ]];

        $this->assertSame(1, $this->measure($page, ['minimum_image_width' => 700, 'minimum_image_height' => 700]));
        // Zero keeps its meaning: height unrestricted, exactly as the downloader
        // reads it.
        $this->assertSame(2, $this->measure($page, ['minimum_image_width' => 700, 'minimum_image_height' => 0]));
    }

    /**
     * @param  array<string, mixed>  $page
     * @param  array<string, mixed>  $context
     */
    private function measure(array $page, array $context = []): int
    {
        $trainer = app(ProductGalleryRecipeTrainer::class);

        return (new ReflectionMethod($trainer, 'usableStaticGallerySize'))->invoke($trainer, $page, $context);
    }
}
