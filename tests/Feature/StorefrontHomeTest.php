<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Services\Catalog\StorefrontShowcase;
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
        $this->product('Sleeping laptop', 'laptops', $lenovo, 990, 'laptop', active: false, photo: true);

        $this->get('/')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Home')
            // With nothing chosen in the admin, a laptop with a photo leads the plates even when a component is newer.
            ->where('plates.0.id', $laptop->id)
            ->has('plates', 2)
            ->has('arrivals', 3)
            ->where('stats.products', 3)
            ->where('stats.in_stock', 2)
            // The lowest price the visitor can open: the sleeping one is not on the site.
            ->where('stats.lowest', ['price' => 3490, 'currency' => 'CZK'])
            ->missing('stats.brands')
            ->where('categories.0.slug', 'laptops')
            ->where('categories.0.count', 2)
            ->where('categories.0.lowest', ['price' => 24990, 'currency' => 'CZK'])
            // A category names its icon: its own, then its address.
            ->where('categories.0.icons', ['laptop', 'laptops'])
            ->where('categories.1.slug', 'components')
            ->where('categories.1.lowest', ['price' => 3490, 'currency' => 'CZK'])
            ->where('brands', [['name' => 'ASUS', 'count' => 1], ['name' => 'Lenovo', 'count' => 1]])
            // Fewer than six priced products give no price ranges rather than thin ones.
            ->where('budgets', null)
            ->etc());
    }

    public function test_the_ways_into_the_catalog_count_what_their_links_open(): void
    {
        $this->product('ThinkPad T480 restored', 'laptops', null, 6990, 'laptop', condition: 'refurbished');
        $this->product('Latitude 7490 used', 'laptops', null, 5490, 'laptop', condition: 'used');
        $this->product('ThinkPad E14 Gen 5', 'laptops', null, 18990, 'laptop');
        $this->product('Samsung PM9A1 used', 'components', null, 1290, 'component', condition: 'used');
        $this->product('Kingston Fury 16GB', 'components', null, 890, 'component');
        // A part without a price is still a part the catalog lists.
        $this->product('Unpriced part', 'components', null, null, 'component');
        $this->product('Sleeping restored', 'laptops', null, 990, 'laptop', active: false, condition: 'refurbished');

        $ways = $this->get('/')->assertOk()->viewData('page')['props']['ways'];

        $this->assertSame(['restored', 'new', 'parts'], array_column($ways, 'key'));
        $this->assertSame([3, 3, 3], array_column($ways, 'count'));
        $this->assertSame([['price' => 1290.0, 'currency' => 'CZK'], ['price' => 890.0, 'currency' => 'CZK'], ['price' => 890.0, 'currency' => 'CZK']],
            array_column($ways, 'lowest'));
        // Restored is every condition but new that the catalog actually holds.
        parse_str(parse_url($ways[0]['href'], PHP_URL_QUERY), $restored);
        $this->assertSame(['condition' => ['used', 'refurbished']], $restored);
        // Each link opens exactly the products its way counted.
        foreach ($ways as $way) {
            $this->get($way['href'])->assertOk()->assertInertia(fn (Assert $page) => $page
                ->component('Catalog/Index')->where('products.total', $way['count'])->etc());
        }
    }

    public function test_a_way_with_nothing_in_it_is_left_out_and_without_prices_no_price_is_shown(): void
    {
        $this->product('ThinkPad E14 Gen 5', 'laptops', null, null, 'laptop', photo: true);
        $this->product('ThinkPad X1', 'laptops', null, null, 'laptop');

        $this->get('/')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('ways', [['key' => 'new', 'count' => 2, 'lowest' => null, 'href' => '/catalog?condition%5B0%5D=new']])
            ->where('stats.lowest', null)
            ->where('categories.0.lowest', null)
            ->where('plates.0.price', null)
            ->etc());
    }

    public function test_prices_in_more_than_one_currency_give_no_lowest_price(): void
    {
        $this->product('ThinkPad E14 Gen 5', 'laptops', null, 18990, 'laptop');
        $this->product('ThinkPad X1', 'laptops', null, 900, 'laptop', currency: 'EUR');
        $this->product('Kingston Fury 16GB', 'components', null, 890, 'component');

        $this->get('/')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('stats.lowest', null)
            ->where('categories.0.lowest', null)
            // A slice in one currency keeps its price.
            ->where('categories.1.lowest', ['price' => 890, 'currency' => 'CZK'])
            ->etc());
    }

    public function test_the_showcase_leads_the_plates_and_the_row_in_the_shops_order(): void
    {
        $newest = [];
        foreach (range(1, 4) as $number) {
            $newest[] = $this->product("Laptop {$number}", 'laptops', null, 20000 + $number, 'laptop', photo: true);
        }
        $part = $this->product('Chosen part', 'components', null, 1290, 'component', photo: true);
        $first = $newest[0];
        $asleep = $this->product('Chosen asleep', 'laptops', null, 9990, 'laptop', active: false, photo: true);
        $photoless = $this->product('Chosen without a photo', 'laptops', null, 9990, 'laptop');
        $deleted = $this->product('Chosen and deleted', 'laptops', null, 9990, 'laptop', photo: true);
        StorefrontShowcase::save([$part->id, $asleep->id, $photoless->id, $deleted->id, $first->id]);
        $deleted->delete();

        // A deleted product drops out of the list by itself; the sleeping and photo-less ones stay chosen.
        $this->assertSame([$part->id, $asleep->id, $photoless->id, $first->id], StorefrontShowcase::ids());

        $this->get('/')->assertOk()->assertInertia(fn (Assert $page) => $page
            // Chosen and shown first, in order; then the newest laptops with photos; nothing twice.
            ->where('plates', fn ($plates) => collect($plates)->pluck('id')->all() === [$part->id, $first->id, $newest[3]->id])
            ->where('arrivals', fn ($arrivals) => collect($arrivals)->pluck('id')->all()
                === [$part->id, $first->id, $photoless->id, $newest[3]->id, $newest[2]->id, $newest[1]->id])
            ->etc());
    }

    public function test_the_showcase_takes_at_most_twelve_existing_products(): void
    {
        $products = collect(range(1, 13))->map(fn (int $number): Product => $this->product("Laptop {$number}", 'laptops', null, 1000, 'laptop'));

        try {
            StorefrontShowcase::save($products->pluck('id')->all());
            $this->fail('Thirteen products were accepted.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertStringContainsString('12', $exception->getMessage());
        }
        try {
            StorefrontShowcase::save([$products[0]->id, 999999]);
            $this->fail('A missing product was accepted.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertStringContainsString('999999', $exception->getMessage());
        }
        $this->assertNull(AppSetting::valueFor(StorefrontShowcase::SETTING));

        $this->assertSame([$products[1]->id, $products[0]->id], StorefrontShowcase::save([(string) $products[1]->id, $products[0]->id, $products[1]->id]));
        $this->assertSame(json_encode([$products[1]->id, $products[0]->id]), AppSetting::valueFor(StorefrontShowcase::SETTING));
        // Clearing the list gives the page back its own choice.
        $this->assertSame([], StorefrontShowcase::save([]));
        $this->assertNull(AppSetting::valueFor(StorefrontShowcase::SETTING));
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
            ->where('stats.lowest', ['price' => 9990, 'currency' => 'CZK'])
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
        // the category stays and its kinds follow it, and its lowest price counts them too.
        $this->product('Odd part', 'components', null, 500, 'component');

        $this->get('/')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('categories', 3)->where('categories.0.slug', 'components')->where('categories.0.count', 3)
            ->where('categories.0.lowest', ['price' => 500, 'currency' => 'CZK'])
            ->where('categories.1.slug', 'ssd')->where('categories.1.lowest', ['price' => 3490, 'currency' => 'CZK'])
            ->where('categories.2.slug', 'ram')->etc());
    }

    public function test_an_empty_catalog_gives_an_empty_start_page_not_an_error(): void
    {
        $this->get('/')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Home')->where('plates', [])->where('arrivals', [])->where('ways', [])->where('categories', [])
            ->where('stats.products', 0)->where('stats.lowest', null)->where('stats.updated_at', null)->etc());
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

    private function product(string $title, string $category, ?Brand $brand, ?int $price, string $type,
        string $stock = 'in_stock', bool $active = true, bool $photo = false, string $condition = 'new', string $currency = 'CZK'): Product
    {
        static $minute = 0;
        $slug = str($title)->slug()->toString();
        $product = Product::query()->create([
            'category_id' => Category::query()->where('slug', $category)->value('id'),
            'brand_id' => $brand?->id, 'title' => $title, 'slug' => $slug, 'product_type' => $type,
            'status' => 'published', 'is_active' => $active, 'published_at' => now()->addMinutes(++$minute),
        ]);
        $product->variants()->create(['name' => 'Base', 'price' => $price, 'currency' => $currency, 'condition' => $condition,
            'stock_status' => $stock, 'is_default' => true, 'is_active' => true]);
        if ($photo) {
            $product->media()->create(['type' => 'image', 'disk' => 'public', 'path' => "products/{$slug}/1.webp",
                'verification_status' => 'verified']);
        }

        return $product;
    }
}
