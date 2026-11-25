<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class StorefrontHomeTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_start_page_shows_the_catalog_as_it_is(): void
    {
        $lenovo = Brand::query()->create(['name' => 'Lenovo', 'slug' => 'lenovo']);
        $asus = Brand::query()->create(['name' => 'ASUS', 'slug' => 'asus']);
        $laptop = $this->product('ThinkPad E14', 'laptops', $lenovo, 24990, 'laptop', photo: true);
        $this->product('Zenbook 14', 'laptops', $asus, 31990, 'laptop', stock: 'out_of_stock');
        $this->product('Samsung 990 Pro', 'components', null, 3490, 'component', photo: true);
        // Taken off sale: it sleeps, so the start page does not count or show it.
        $this->product('Sleeping laptop', 'laptops', $lenovo, 19990, 'laptop', active: false, photo: true);

        $this->get('/')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Home')
            // A laptop with a photo leads the bench even when a component is newer.
            ->where('bench.0.id', $laptop->id)
            ->has('bench', 2)
            ->has('arrivals', 3)
            ->where('stats.products', 3)
            ->where('stats.in_stock', 2)
            ->where('stats.brands', 2)
            ->where('categories.0.slug', 'laptops')
            ->where('categories.0.count', 2)
            // A category names its icon: its own, then its address.
            ->where('categories.0.icons', ['laptop', 'laptops'])
            ->where('categories.1.slug', 'components')
            ->where('brands', [['name' => 'ASUS', 'count' => 1], ['name' => 'Lenovo', 'count' => 1]])
            // Fewer than six priced products give no price ranges rather than thin ones.
            ->where('budgets', null)
            ->etc());
    }

    public function test_price_ranges_cut_the_shops_own_prices_into_thirds(): void
    {
        foreach ([9990, 12490, 18990, 23990, 31990, 45990] as $number => $price) {
            $this->product("Laptop {$number}", 'laptops', null, $price, 'laptop');
        }

        $this->get('/')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('budgets.currency', 'CZK')
            ->where('budgets.ranges', [
                ['min' => null, 'max' => 19000, 'count' => 3],
                ['min' => 19000, 'max' => 32000, 'count' => 2],
                ['min' => 32000, 'max' => null, 'count' => 1],
            ])
            ->etc());
    }

    public function test_a_shop_with_one_top_category_is_shown_by_the_kinds_under_it(): void
    {
        // One row of categories is no choice: the page lists the level that offers one.
        $components = Category::query()->where('slug', 'components')->value('id');
        foreach (['ssd' => 31, 'ram' => 32] as $slug => $order) {
            Category::query()->updateOrCreate(['slug' => $slug], ['name' => strtoupper($slug), 'parent_id' => $components, 'sort_order' => $order, 'is_active' => true]);
        }
        $this->product('Samsung 990 Pro', 'ssd', null, 3490, 'component', photo: true);
        $this->product('Kingston Fury 16GB', 'ram', null, 1290, 'component');

        $this->get('/')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('categories', 2)
            ->where('categories.0.slug', 'ssd')->where('categories.0.count', 1)
            ->where('categories.1.slug', 'ram')
            // A kind without an icon of its own is found by its address, then by its parent.
            ->where('categories.1.icons', fn ($icons) => array_slice(collect($icons)->all(), -3) === ['ram', 'component', 'components'])
            ->etc());

        // A product filed straight under the top category would be lost from such a list:
        // the category stays and its kinds follow it.
        $this->product('Odd part', 'components', null, 500, 'component');

        $this->get('/')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('categories', 3)->where('categories.0.slug', 'components')->where('categories.0.count', 3)
            ->where('categories.1.slug', 'ssd')->where('categories.2.slug', 'ram')->etc());
    }

    public function test_an_empty_catalog_gives_an_empty_start_page_not_an_error(): void
    {
        $this->get('/')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Home')->where('bench', [])->where('arrivals', [])->where('categories', [])
            ->where('stats.products', 0)->where('stats.updated_at', null)->etc());
    }

    public function test_the_catalog_with_its_filters_stays_at_its_own_address(): void
    {
        $this->get('/catalog')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Catalog/Index')->etc());
    }

    public function test_search_suggestions_are_the_catalogs_own_search_results(): void
    {
        $this->product('ThinkPad E14', 'laptops', null, 24990, 'laptop', photo: true);
        $this->product('ThinkPad X1 Carbon', 'laptops', null, 41990, 'laptop');
        $this->product('ThinkPad T14 asleep', 'laptops', null, 21990, 'laptop', active: false);
        $this->product('Zenbook 14', 'laptops', null, 31990, 'laptop');

        $response = $this->getJson('/search/suggest?q=thinkpad')->assertOk()->assertJsonPath('total', 2);
        $this->assertSame(['ThinkPad X1 Carbon', 'ThinkPad E14'], array_column($response->json('items'), 'title'));
        $this->assertSame(24990, (int) $response->json('items.1.price'));
        $this->assertStringContainsString('/products/thinkpad-e14', $response->json('items.1.url'));

        $this->getJson('/search/suggest?q=t')->assertOk()->assertExactJson(['items' => [], 'total' => 0]);
        $this->getJson('/search/suggest')->assertUnprocessable();
    }

    private function product(string $title, string $category, ?Brand $brand, int $price, string $type,
        string $stock = 'in_stock', bool $active = true, bool $photo = false): Product
    {
        static $minute = 0;
        $slug = str($title)->slug()->toString();
        $product = Product::query()->create([
            'category_id' => Category::query()->where('slug', $category)->value('id'),
            'brand_id' => $brand?->id, 'title' => $title, 'slug' => $slug, 'product_type' => $type,
            'status' => 'published', 'is_active' => $active, 'published_at' => now()->addMinutes(++$minute),
        ]);
        $product->variants()->create(['name' => 'Base', 'price' => $price, 'currency' => 'CZK',
            'stock_status' => $stock, 'is_default' => true, 'is_active' => true]);
        if ($photo) {
            $product->media()->create(['type' => 'image', 'disk' => 'public', 'path' => "products/{$slug}/1.webp",
                'verification_status' => 'verified']);
        }

        return $product;
    }
}
