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

    /** @param array<string, array{string, int|float|null}> $attributes */
    private function product(string $title, string $type, array $attributes, bool $active = true): Product
    {
        $categorySlug = $type === 'laptop' ? 'laptops' : 'components';
        $category = Category::query()->where('slug', $categorySlug)->firstOrFail();
        $brand = Brand::query()->firstOrCreate(['slug' => 'test'], ['name' => 'Test']);
        $product = Product::query()->create([
            'category_id' => $category->id,
            'brand_id' => $brand->id,
            'canonical_key' => fake()->unique()->slug(),
            'product_type' => $type,
            'status' => 'published',
            'slug' => fake()->unique()->slug(),
            'title' => $title,
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
