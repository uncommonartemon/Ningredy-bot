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

    public function test_condition_and_memory_must_match_one_variant(): void
    {
        $product = $this->product('Laptop X', 'laptop', ['ram' => ['32 GB', 32]]);
        $variant = $product->variants()->create(['condition' => 'open_box', 'sku' => 'OPEN-123',
            'currency' => 'CZK', 'stock_status' => 'in_stock', 'is_active' => true, 'is_default' => false]);
        $variant->attributes()->create(['attribute_definition_id' => AttributeDefinition::where('key', 'ram')->value('id'), 'value' => '8 GB', 'numeric_value' => 8]);
        $result = json_decode((string) (new SearchCatalog)->handle(new ToolRequest(['query' => 'Laptop X', 'condition' => 'open_box', 'ram_min' => 32])), true);
        $this->assertSame(0, $result['count']);
    }

    public function test_requested_condition_returns_that_variant_not_the_default(): void
    {
        $product = $this->product('Laptop X', 'laptop', []);
        $variant = $product->variants()->create(['condition' => 'open_box', 'sku' => 'OPEN-123', 'price' => 700,
            'currency' => 'CZK', 'stock_status' => 'in_stock', 'is_active' => true, 'is_default' => false]);
        $result = json_decode((string) (new SearchCatalog)->handle(new ToolRequest(['query' => 'Laptop X', 'condition' => 'open_box'])), true);
        $this->assertSame(1, $result['count']);
        $this->assertSame($variant->id, $result['products'][0]['variant_id']);
        $this->assertSame('open_box', $result['products'][0]['condition']);
    }

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

    public function test_a_model_code_stays_a_required_term_beside_a_brand_filter(): void
    {
        // The identity check lost its backslashes on 2026-08-10: "\d" became a
        // literal "d", so codes without that letter were silently dropped.
        $this->product('ASUS Zenbook 14 OLED', 'laptop', [], brand: 'ASUS', model: 'UX3405MA-PH77');
        $wanted = $this->product('ASUS Zenbook 14 OLED', 'laptop', [], brand: 'ASUS', model: 'UX3405MA-PH99T');

        $result = $this->search(['query' => 'ASUS Zenbook 14 UX3405MA-PH99T', 'brand' => 'ASUS', 'limit' => 10]);

        $this->assertSame([$wanted->id], array_column($result['products'], 'id'));
    }

    public function test_capacities_in_the_request_are_filters_not_required_words(): void
    {
        $product = $this->product('ASUS Zenbook 14 OLED', 'laptop', ['ram' => ['32 GB', 32]], brand: 'ASUS', model: 'UX3405MA');

        $result = $this->search(['query' => 'ASUS Zenbook 14 32GB 1TB', 'brand' => 'ASUS']);

        $this->assertSame([$product->id], array_column($result['products'], 'id'));
    }

    public function test_an_exact_code_lists_its_product_first_even_when_the_words_miss_it(): void
    {
        // Live #567: the same card was researched again under its other code.
        $gpu = $this->product('ASUS Dual GeForce RTX 4060 OC Edition 8GB GDDR6', 'component', [], brand: 'ASUS', model: 'DUAL-RTX4060-O8G');
        $gpu->variants()->first()->update(['mpn' => '90YV0JC0-M0NA00']);
        $mac = $this->product('Apple MacBook Pro 14-inch M3 Pro', 'laptop', [], brand: 'Apple', model: 'MacBook Pro 14-inch (M3 Pro, 2023)');
        $mac->variants()->first()->update(['mpn' => 'MRX33CZ/A', 'gtin' => '195949076534']);
        $this->product('Other laptop', 'laptop', []);

        $byPart = $this->search(['query' => 'найди 90YV0JC0-M0NA00']);
        $byModel = $this->search(['query' => 'ASUS Dual RTX 4060 (DUAL-RTX4060-O8G) белая', 'brand' => 'ASUS', 'color' => 'white']);
        $byPaddedEan = $this->search(['query' => '0195949076534']);
        $byFamily = $this->search(['query' => 'MRX33']);

        $this->assertSame([$gpu->id, true, '90yv0jc0-m0na00'], [$byPart['products'][0]['id'], $byPart['products'][0]['exact_code_match'], $byPart['products'][0]['matched_code']]);
        $this->assertSame($gpu->id, $byModel['products'][0]['id']);
        $this->assertTrue($byModel['products'][0]['exact_code_match']);
        $this->assertSame([$mac->id, true], [$byPaddedEan['products'][0]['id'], $byPaddedEan['products'][0]['exact_code_match']]);
        $this->assertArrayNotHasKey('exact_code_match', $byFamily['products'][0] ?? []);
    }

    private function search(array $input): array
    {
        return json_decode((string) (new SearchCatalog)->handle(new ToolRequest($input)), true, flags: JSON_THROW_ON_ERROR);
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
