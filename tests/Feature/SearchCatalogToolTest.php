<?php

namespace Tests\Feature;

use App\Ai\Tools\SearchCatalog;
use App\Models\AttributeDefinition;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Tools\Request as ToolRequest;
use Tests\TestCase;

class SearchCatalogToolTest extends TestCase
{
    use RefreshDatabase;

    public function test_conversational_request_finds_laptop_by_ram(): void
    {
        $matching = $this->product('Laptop with 8 GB', 'laptop', ['ram' => ['8 GB', 8]]);
        $this->product('Laptop with 32 GB', 'laptop', ['ram' => ['32 GB', 32]]);

        $result = json_decode((string) (new SearchCatalog)->handle(new ToolRequest([
            'query' => 'Привет, как дела, сможешь у нас найти ноутбук, у которого 8 GB RAM?',
        ])), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(1, $result['count']);
        $this->assertSame($matching->id, $result['products'][0]['id']);
        $this->assertSame('8 GB', $result['products'][0]['attributes']['ram']);
    }

    public function test_browse_catalog_button_phrasing_does_not_zero_out_real_results(): void
    {
        // Real production bug (2026-08-06): the "📦 Каталог" button expands
        // to "Покажи последние активные товары локального каталога." -
        // "последние"/"активные"/"локального"/"каталога" (Russian noun/
        // adjective forms the stopword list didn't cover) survived as
        // required AND-ed search terms that no real product could ever
        // match, so browsing the catalog always reported zero results
        // regardless of what was actually published.
        $product = $this->product('Acer Nitro V 16 AI', 'laptop', []);

        $result = json_decode((string) (new SearchCatalog)->handle(new ToolRequest([
            'query' => 'Покажи последние активные товары локального каталога.',
            'active_only' => true,
        ])), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(1, $result['count']);
        $this->assertSame($product->id, $result['products'][0]['id']);
    }

    public function test_search_finds_component_by_cpu_attribute_and_includes_hidden_products(): void
    {
        $matching = $this->product('Desktop processor', 'component', [
            'cpu' => ['Intel Core i7-14700', null],
        ], active: false);

        $result = json_decode((string) (new SearchCatalog)->handle(new ToolRequest([
            'query' => 'Найди процессор i7 14700',
            'cpu' => 'i7-14700',
        ])), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(1, $result['count']);
        $this->assertSame($matching->id, $result['products'][0]['id']);
        $this->assertFalse($result['products'][0]['active']);
    }

    public function test_structured_brand_filter_does_not_replace_an_exact_model_with_another_series(): void
    {
        $this->product(
            'MSI Vector 17 HX AI A2XWJG-009FR',
            'laptop',
            [],
            brand: 'MSI',
            model: 'Vector 17 HX AI A2XWJG-009FR',
        );
        $katana = $this->product(
            'MSI Katana 17 HX B14WGK-059US',
            'laptop',
            [],
            brand: 'MSI',
            model: 'Katana 17 HX B14WGK-059US',
        );

        $result = json_decode((string) (new SearchCatalog)->handle(new ToolRequest([
            'query' => 'MSI Katana 17 HX (B14WGK-059US)',
            'product_type' => 'laptop',
            'brand' => 'MSI',
            'limit' => 10,
        ])), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(1, $result['count']);
        $this->assertSame($katana->id, $result['products'][0]['id']);
    }

    public function test_structured_brand_filter_returns_nothing_when_the_exact_series_is_absent(): void
    {
        $this->product(
            'MSI Vector 17 HX AI A2XWJG-009FR',
            'laptop',
            [],
            brand: 'MSI',
            model: 'Vector 17 HX AI A2XWJG-009FR',
        );

        $result = json_decode((string) (new SearchCatalog)->handle(new ToolRequest([
            'query' => 'MSI Katana 17 HX (B14WGK-059US)',
            'product_type' => 'laptop',
            'brand' => 'MSI',
            'limit' => 10,
        ])), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $result['count']);
        $this->assertSame([], $result['products']);
    }

    /** @param array<string, array{string, int|float|null}> $attributes */
    private function product(
        string $title,
        string $type,
        array $attributes,
        bool $active = true,
        string $brand = 'Test',
        ?string $model = null,
    ): Product {
        $categorySlug = $type === 'laptop' ? 'laptops' : 'components';
        $category = Category::query()->where('slug', $categorySlug)->firstOrFail();
        $brandRecord = Brand::query()->firstOrCreate(
            ['slug' => strtolower($brand)],
            ['name' => $brand],
        );
        $product = Product::query()->create([
            'category_id' => $category->id,
            'brand_id' => $brandRecord->id,
            'canonical_key' => fake()->unique()->slug(),
            'product_type' => $type,
            'status' => 'published',
            'slug' => fake()->unique()->slug(),
            'title' => $title,
            'model' => $model,
            'is_active' => $active,
            'published_at' => now(),
        ]);
        $variant = $product->variants()->create([
            'fingerprint' => fake()->unique()->sha1(),
            'condition' => 'new',
            'stock_status' => 'in_stock',
            'is_default' => true,
            'is_active' => true,
        ]);

        foreach ($attributes as $key => [$value, $number]) {
            $definition = AttributeDefinition::query()->where('key', $key)->firstOrFail();
            $variant->attributes()->create([
                'attribute_definition_id' => $definition->id,
                'value' => $value,
                'numeric_value' => $number,
                'unit' => $key === 'ram' ? 'GB' : null,
            ]);
        }

        return $product->refresh();
    }
}
