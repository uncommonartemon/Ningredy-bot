<?php

namespace Tests\Unit;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Products\ProductPhotoManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductPhotoManagerTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_reorders_photos_and_updates_primary(): void
    {
        $product = $this->productWithPhotos(3);
        $before = $product->media()->orderBy('sort_order')->pluck('id')->all();

        app(ProductPhotoManager::class)->reorder($product, [3, 2, 1]);

        $after = $product->media()->orderBy('sort_order')->get();
        $this->assertSame($before[2], $after[0]->id);
        $this->assertTrue($after[0]->is_primary);
        $this->assertFalse($after[1]->is_primary);
        $this->assertSame($before[0], $after[2]->id);
    }

    public function test_it_rejects_an_invalid_permutation(): void
    {
        $product = $this->productWithPhotos(3);

        $this->expectException(\RuntimeException::class);
        app(ProductPhotoManager::class)->reorder($product, [1, 2]);
    }

    public function test_it_deletes_by_position_and_renumbers_the_rest(): void
    {
        $product = $this->productWithPhotos(4);
        $ids = $product->media()->orderBy('sort_order')->pluck('id')->all();

        $deleted = app(ProductPhotoManager::class)->delete($product, [2, 4]);

        $this->assertSame(2, $deleted);
        $remaining = $product->media()->orderBy('sort_order')->get();
        $this->assertCount(2, $remaining);
        $this->assertSame([$ids[0], $ids[2]], $remaining->pluck('id')->all());
        $this->assertTrue($remaining->first()->is_primary);
    }

    /** @return Product */
    private function productWithPhotos(int $count)
    {
        $category = Category::query()->where('slug', 'laptops')->firstOrFail();
        $brand = Brand::query()->firstOrCreate(['slug' => 'lenovo'], ['name' => 'Lenovo', 'is_active' => true]);
        $product = Product::query()->create([
            'category_id' => $category->id,
            'brand_id' => $brand->id,
            'canonical_key' => 'photo-manager-test-'.$count,
            'product_type' => 'laptop',
            'status' => 'published',
            'slug' => 'photo-manager-test-'.$count,
            'title' => 'Photo Manager Test',
            'is_active' => true,
            'published_at' => now(),
        ]);
        $variant = ProductVariant::query()->create([
            'product_id' => $product->id,
            'fingerprint' => 'photo-manager-test-'.$count,
            'name' => 'Default',
            'is_default' => true,
            'is_active' => true,
        ]);

        for ($index = 0; $index < $count; $index++) {
            $product->media()->create([
                'product_variant_id' => $variant->id,
                'type' => 'image',
                'url' => "https://example.com/photo-{$index}.jpg",
                'source_url' => "https://example.com/photo-{$index}.jpg",
                'verification_status' => 'verified',
                'is_primary' => $index === 0,
                'sort_order' => $index,
            ]);
        }

        return $product;
    }
}
