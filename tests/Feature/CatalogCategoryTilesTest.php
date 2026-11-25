<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogCategoryTilesTest extends TestCase
{
    use RefreshDatabase;

    private int $sequence = 0;

    public function test_the_whole_catalog_offers_the_top_categories_that_hold_products_as_tiles(): void
    {
        $this->kinds();
        $this->product('laptops');
        $this->product('laptops');
        $this->product('ssd');
        // Asleep: a product off sale is not counted, and a category with only such products is no way in.
        $this->product('computers', ['is_active' => false]);

        $page = $this->catalog();

        $this->assertSame([], $page['facets']['trail']);
        $this->assertSame(['laptops', 'components'], array_column($page['facets']['tiles'], 'slug'));
        $this->assertSame([2, 1], array_column($page['facets']['tiles'], 'count'));
        $laptops = $page['facets']['tiles'][0];
        $this->assertSame('Notebooky', $laptops['translations']['cs']);
        // The same chain of icon names the start page sends: its own icon and address, then the parents'.
        $this->assertSame(['laptop', 'laptops'], $laptops['icons']);
    }

    public function test_a_parent_category_offers_its_kinds_and_the_path_to_it(): void
    {
        $this->kinds();
        $this->product('ssd');
        $this->product('ssd');
        $this->product('ram');
        $this->product('laptops');

        $page = $this->catalog(['category' => 'components']);

        $this->assertSame(['components'], array_column($page['facets']['trail'], 'slug'));
        $this->assertSame(['ssd', 'ram'], array_column($page['facets']['tiles'], 'slug'));
        $this->assertSame([2, 1], array_column($page['facets']['tiles'], 'count'));
        $this->assertSame(['ssd', 'component', 'components'], array_slice($page['facets']['tiles'][0]['icons'], -3));
        $this->assertSame(3, $page['products']['total']);
    }

    public function test_a_tile_counts_what_its_kind_holds_since_following_it_starts_without_filters(): void
    {
        // A tile is a plain link: the kind's page opens without the filters ticked here, so its
        // number is what that page will show, not what is left under the current filters.
        $this->kinds();
        $this->product('ssd', variant: ['price' => 100]);
        $this->product('ssd', variant: ['price' => 900]);
        $this->product('ram', variant: ['price' => 900]);

        $page = $this->catalog(['category' => 'components', 'max_price' => 500]);

        $this->assertSame(1, $page['products']['total']);
        $this->assertSame([2, 1], array_column($page['facets']['tiles'], 'count'));
    }

    public function test_a_leaf_has_its_path_and_no_tiles(): void
    {
        $this->kinds();
        $this->product('ssd');
        $this->product('ram');

        $page = $this->catalog(['category' => 'ssd']);

        $this->assertSame(['components', 'ssd'], array_column($page['facets']['trail'], 'slug'));
        $this->assertSame('SSD disky', $page['facets']['trail'][1]['translations']['cs']);
        $this->assertSame([], $page['facets']['tiles']);
    }

    public function test_one_way_on_is_no_choice_and_gives_no_tiles(): void
    {
        $this->kinds();
        $this->product('ssd');
        // A kind without visible products is not offered.
        $this->product('ram', ['status' => 'draft']);

        $this->assertSame([], $this->catalog(['category' => 'components'])['facets']['tiles']);
    }

    public function test_a_shop_with_one_top_category_starts_with_the_kinds_under_it_like_the_start_page(): void
    {
        $this->kinds();
        $this->product('ssd');
        $this->product('ram');

        $this->assertSame(['ssd', 'ram'], array_column($this->catalog()['facets']['tiles'], 'slug'));
    }

    private function kinds(): void
    {
        $components = Category::query()->where('slug', 'components')->value('id');
        foreach (['ssd' => ['SSD drives', 'SSD disky', 31], 'ram' => ['Memory', 'Operační paměti', 32]] as $slug => [$name, $czech, $order]) {
            $kind = Category::query()->updateOrCreate(['slug' => $slug], ['name' => $name, 'parent_id' => $components, 'sort_order' => $order, 'is_active' => true]);
            $kind->translations()->updateOrCreate(['locale' => 'cs'], ['name' => $czech]);
        }
    }

    private function product(string $category, array $attributes = [], array $variant = []): Product
    {
        $number = ++$this->sequence;
        $brand = Brand::query()->firstOrCreate(['name' => 'Tile brand'], ['slug' => 'tile-brand', 'is_active' => true]);
        $product = Product::query()->create([
            'category_id' => Category::query()->where('slug', $category)->value('id'), 'brand_id' => $brand->id,
            'product_type' => $category === 'laptops' ? 'laptop' : 'component', 'status' => 'published', 'is_active' => true,
            'title' => 'Tile product '.$number, 'slug' => 'tile-product-'.$number, 'canonical_key' => 'tile-product-'.$number,
            ...$attributes,
        ]);
        $product->variants()->create(['sku' => 'TILE-'.$number, 'price' => 100, 'is_default' => true, 'is_active' => true,
            'stock_status' => 'in_stock', ...$variant]);

        return $product;
    }

    private function catalog(array $filters = []): array
    {
        return $this->get('/catalog?'.http_build_query($filters))->assertOk()->viewData('page')['props'];
    }
}
