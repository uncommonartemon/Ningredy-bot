<?php

namespace Tests\Feature;

use App\Models\AttributeDefinition;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalog_returns_only_visible_products(): void
    {
        $category = Category::query()->where('slug', 'laptops')->firstOrFail();

        $visible = $this->product($category, ['title' => 'Visible laptop']);
        $this->product($category, ['title' => 'Hidden laptop', 'is_active' => false]);

        $this->get('/catalog')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Catalog/Index')
            ->where('products.total', 1)
            ->where('products.data.0.id', $visible->id)
            ->where('products.data.0.category.name', 'Laptops')
            ->where('products.data.0.category.translations.cs', 'Notebooky')
            ->where('products.data.0.category.translations.uk', 'Ноутбуки')
            ->where('products.data.0.category.translations.en', 'Laptops')
            ->where('facets.categories.0.label', 'Laptops')
            ->where('facets.categories.0.translations.cs', 'Notebooky')
            ->has('facets.categories')
            ->has('facets.columns'));
    }

    public function test_catalog_filters_by_category_brand_and_cpu(): void
    {
        $laptops = Category::query()->where('slug', 'laptops')->firstOrFail();
        $computers = Category::query()->where('slug', 'computers')->firstOrFail();

        $match = $this->product($laptops, ['title' => 'Lenovo Ryzen', 'brand' => 'Lenovo']);
        $cpu = AttributeDefinition::query()->where('key', 'cpu')->firstOrFail();
        $match->defaultVariant()->firstOrFail()->attributes()->create([
            'attribute_definition_id' => $cpu->id,
            'value' => 'AMD Ryzen 7',
            'normalized_value' => 'amd ryzen 7',
        ]);
        $this->product($computers, ['title' => 'ASUS Intel', 'brand' => 'ASUS']);

        $this->get('/catalog?category=laptops&brands[]=Lenovo&attributes[cpu][]=amd%20ryzen%207')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('products.total', 1)
                ->where('products.data.0.id', $match->id));
    }

    public function test_visible_product_has_a_details_page_with_local_media(): void
    {
        $category = Category::query()->where('slug', 'laptops')->firstOrFail();
        $product = $this->product($category, ['title' => 'Detailed laptop']);
        $product->media()->create([
            'type' => 'image',
            'disk' => 'public',
            'path' => "products/{$product->id}/primary.webp",
            'url' => '/storage/placeholder.webp',
            'is_primary' => true,
        ]);

        $this->get(route('products.show', $product))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Products/Show')
            ->where('product.id', $product->id)
            ->where('product.category.name', 'Laptops')
            ->where('product.category.translations.cs', 'Notebooky')
            ->where('product.category.translations.uk', 'Ноутбуки')
            ->where('product.media.0.url', "/storage/products/{$product->id}/primary.webp")
            ->has('product.variants', 1));
    }

    public function test_hidden_product_details_return_not_found(): void
    {
        $category = Category::query()->where('slug', 'laptops')->firstOrFail();
        $product = $this->product($category, ['is_active' => false]);

        $this->get(route('products.show', $product))->assertNotFound();
    }

    private function product(Category $category, array $attributes = []): Product
    {
        $brandName = $attributes['brand'] ?? 'Test brand';
        unset($attributes['brand']);
        $brand = Brand::query()->firstOrCreate(
            ['slug' => str($brandName)->slug()->toString()],
            ['name' => $brandName],
        );
        $product = Product::query()->create([
            'category_id' => $category->id,
            'brand_id' => $brand->id,
            'canonical_key' => fake()->unique()->slug(),
            'product_type' => 'laptop',
            'status' => 'published',
            'slug' => fake()->unique()->slug(),
            'title' => 'Test product',
            'is_active' => true,
            'published_at' => now(),
            ...$attributes,
        ]);

        $product->variants()->create([
            'fingerprint' => fake()->unique()->sha1(),
            'condition' => 'new',
            'price' => 1000,
            'currency' => 'CZK',
            'stock_status' => 'in_stock',
            'is_default' => true,
            'is_active' => true,
        ]);

        return $product->refresh();
    }
}
