<?php

namespace Tests\Unit;

use App\Models\Category;
use App\Services\Products\ProductCategoryResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductCategoryResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_repairs_a_category_that_conflicts_with_product_type(): void
    {
        Category::query()->where('slug', 'laptops')->update(['product_type_affinity' => 'laptop']);
        Category::query()->where('slug', 'computers')->update(['product_type_affinity' => 'desktop']);

        $resolved = app(ProductCategoryResolver::class)->resolve('laptop', 'computers');

        $this->assertSame('laptops', $resolved?->slug);
    }

    public function test_it_does_not_guess_when_multiple_categories_accept_the_same_type(): void
    {
        Category::query()->where('slug', 'laptops')->update(['product_type_affinity' => 'laptop']);
        Category::query()->create([
            'name' => 'Gaming laptops',
            'slug' => 'gaming-laptops',
            'product_type_affinity' => 'laptop',
            'is_active' => true,
            'sort_order' => 50,
        ]);

        $this->assertNull(app(ProductCategoryResolver::class)->resolve('laptop', 'computers'));
    }
}
