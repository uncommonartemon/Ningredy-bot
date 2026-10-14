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

    public function test_filtered_card_and_its_link_use_the_matching_variant_price_specs_and_photo(): void
    {
        $product = $this->product(Category::where('slug', 'laptops')->firstOrFail());
        $default = $product->defaultVariant()->firstOrFail();
        $default->update(['color' => 'Black', 'price' => 1000]);
        $selected = $product->variants()->create(['color' => 'Silver', 'price' => 2000,
            'stock_status' => 'in_stock', 'is_default' => false, 'is_active' => true]);
        $cpu = AttributeDefinition::where('key', 'cpu')->firstOrFail();
        $default->attributes()->create(['attribute_definition_id' => $cpu->id, 'value' => 'Old CPU', 'normalized_value' => 'old cpu']);
        $selected->attributes()->create(['attribute_definition_id' => $cpu->id, 'value' => 'New CPU', 'normalized_value' => 'new cpu']);
        foreach ([$default, $selected] as $variant) {
            $product->media()->create(['type' => 'image', 'product_variant_id' => $variant->id,
                'disk' => 'public', 'path' => 'products/'.$variant->id.'.jpg', 'verification_status' => 'manual', 'is_primary' => true]);
        }
        $this->get('/catalog?colors[]=Silver')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('products.total', 1)
            ->where('products.data.0.variant_id', $selected->id)
            ->where('products.data.0.price', 2000)
            ->where('products.data.0.image', '/storage/products/'.$selected->id.'.jpg')
            ->where('products.data.0.attributes.0.value', 'New CPU')
            ->where('products.data.0.url', '/products/'.$product->slug.'?variant='.$selected->id));
        $this->get('/products/'.$product->slug.'?variant='.$selected->id)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('product.selected_variant_id', $selected->id));
        $this->get('/products/'.$product->slug.'?variant=999999')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('product.selected_variant_id', null));
    }

    public function test_zero_maximum_price_is_a_real_filter(): void
    {
        $category = Category::where('slug', 'laptops')->firstOrFail();
        $free = $this->product($category, ['title' => 'Free item']);
        $free->defaultVariant()->update(['price' => 0]);
        $this->product($category, ['title' => 'Paid item']);
        $this->get('/catalog?max_price=0')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('products.total', 1)->where('products.data.0.id', $free->id)->where('products.data.0.price', 0));
    }

    public function test_inherited_attributes_remain_visible_with_variant_overrides_and_units(): void
    {
        $product = $this->product(Category::where('slug', 'laptops')->firstOrFail(), [
            'translations' => ['cs' => ['title' => 'Notebook', 'description' => 'Popis']],
        ]);
        $cpu = AttributeDefinition::where('key', 'cpu')->firstOrFail();
        $ram = AttributeDefinition::where('key', 'ram')->firstOrFail();
        $product->attributes()->create(['attribute_definition_id' => $cpu->id, 'value' => 'Shared CPU']);
        $product->attributes()->create(['attribute_definition_id' => $ram->id, 'value' => '16', 'unit' => 'GB']);
        $product->defaultVariant()->firstOrFail()->attributes()->create(['attribute_definition_id' => $ram->id, 'value' => '32', 'unit' => 'GB']);
        $product->defaultVariant()->update(['translations' => ['cs' => ['description' => 'Jiný popis']]]);
        $this->get('/catalog')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('products.data.0.attributes', 2)
            ->where('products.data.0.attributes.0.value', 'Shared CPU')
            ->where('products.data.0.attributes.1.value', '32')
            ->where('products.data.0.attributes.1.unit', 'GB')
            ->where('products.data.0.translations.cs.title', 'Notebook')
            ->where('products.data.0.translations.cs.description', 'Jiný popis'));
    }

    public function test_price_sort_orders_the_prices_shown_on_cards_and_ignores_inactive_variants(): void
    {
        $category = Category::where('slug', 'laptops')->firstOrFail();
        $first = $this->product($category, ['title' => 'Many configurations']);
        $first->defaultVariant()->update(['price' => 3000]);
        $cheap = $first->variants()->create(['price' => 500, 'is_active' => true, 'is_default' => false]);
        $first->variants()->create(['price' => 1, 'is_active' => false]);
        $middle = $this->product($category, ['title' => 'Single configuration']);
        $this->get('/catalog?sort=price_asc')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('products.data.0.id', $first->id)->where('products.data.0.variant_id', $cheap->id)
            ->where('products.data.0.price', 500)->where('products.data.1.id', $middle->id)
            ->where('products.data.1.price', 1000));
        $this->get('/catalog?sort=price_desc')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('products.data.0.id', $first->id)->where('products.data.0.price', 3000)
            ->where('products.data.1.price', 1000));
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
