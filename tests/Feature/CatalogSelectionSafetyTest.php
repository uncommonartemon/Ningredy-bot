<?php

namespace Tests\Feature;

use App\Models\AttributeDefinition;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CatalogSelectionSafetyTest extends TestCase
{
    use RefreshDatabase;

    private function product(): Product
    {
        $product = Product::create([
            'category_id' => Category::where('slug', 'laptops')->value('id'),
            'product_type' => 'laptop', 'status' => 'published', 'is_active' => true,
            'title' => 'Audit laptop', 'slug' => 'audit-laptop', 'canonical_key' => 'audit-laptop',
        ]);
        $product->variants()->create(['sku' => 'DEFAULT-SKU', 'price' => 1000, 'is_default' => true, 'is_active' => true]);

        return $product;
    }

    public function test_inactive_variant_media_is_absent_but_global_media_remains(): void
    {
        $product = $this->product();
        $hidden = $product->variants()->create(['sku' => 'HIDDEN-SKU', 'is_active' => false]);
        $product->media()->create(['type' => 'image', 'product_variant_id' => $hidden->id,
            'disk' => 'public', 'path' => 'audit/hidden.jpg', 'verification_status' => 'manual']);
        $global = $product->media()->create(['type' => 'image',
            'disk' => 'public', 'path' => 'audit/global.jpg', 'verification_status' => 'manual']);
        $this->get('/products/audit-laptop')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('product.variants', 1)->has('product.media', 1)
            ->where('product.media.0.id', $global->id));
    }

    public function test_exact_identifier_selects_matching_offer_even_when_title_matches_too(): void
    {
        $product = $this->product();
        $product->update(['title' => 'TARGET-32GB-SKU laptop']);
        $selected = $product->variants()->create(['sku' => 'TARGET-32GB-SKU', 'mpn' => 'MPN-32GB',
            'gtin' => '1234567890123', 'price' => 2000, 'color' => 'Silver', 'is_active' => true]);
        $product->media()->create(['type' => 'image', 'product_variant_id' => $selected->id,
            'disk' => 'public', 'path' => 'audit/selected.jpg', 'verification_status' => 'manual']);
        foreach (['target-32gb-sku', 'MPN-32GB', '1234567890123'] as $search) {
            $this->get('/catalog?search='.$search.'&sort=price_asc')->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->where('products.total', 1)->where('products.data.0.variant_id', $selected->id)
                    ->where('products.data.0.price', 2000)
                    ->where('products.data.0.image', '/storage/audit/selected.jpg')
                    ->where('products.data.0.url', '/products/audit-laptop?variant='.$selected->id));
        }
        $this->get('/catalog?search=TARGET-32GB-SKU&max_price=1500')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('products.total', 0));
    }

    public function test_zero_identifier_filters_products_and_selects_the_matching_variant(): void
    {
        $product = $this->product();
        $selected = $product->variants()->create(['sku' => '0', 'price' => 2000, 'is_active' => true]);
        $unrelated = Product::create([
            'category_id' => $product->category_id, 'product_type' => 'laptop',
            'status' => 'published', 'is_active' => true,
            'title' => 'Unrelated laptop', 'slug' => 'unrelated-laptop', 'canonical_key' => 'unrelated-laptop',
        ]);
        $unrelated->variants()->create(['sku' => 'UNRELATED', 'price' => 500, 'is_default' => true, 'is_active' => true]);

        foreach (['0', '%200%20'] as $search) {
            $this->get('/catalog?search='.$search)->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->where('products.total', 1)
                    ->where('products.data.0.variant_id', $selected->id)
                    ->where('products.data.0.price', 2000));
        }
    }

    public function test_partial_sku_and_price_filter_cannot_match_different_variants(): void
    {
        $product = $this->product();
        $selected = $product->variants()->create(['sku' => 'TARGET-32GB-SKU', 'price' => 2000, 'is_active' => true]);
        $this->get('/catalog?search=32GB')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('products.data.0.variant_id', $selected->id));
        $this->get('/catalog?search=32GB&max_price=1500')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('products.total', 0));
    }

    public function test_inactive_identifiers_do_not_make_product_searchable(): void
    {
        $product = $this->product();
        $product->variants()->create(['sku' => 'HIDDEN-SKU', 'is_active' => false]);
        $this->get('/catalog?search=HIDDEN-SKU')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('products.total', 0));
    }

    public function test_global_attribute_is_filterable_and_counted_once_per_product(): void
    {
        $product = $this->product();
        $product->variants()->create(['sku' => 'SECOND', 'is_active' => true]);
        $ram = AttributeDefinition::where('key', 'ram')->firstOrFail();
        $product->attributes()->create(['attribute_definition_id' => $ram->id, 'value' => '32 GB']);
        $this->get('/catalog?attributes[ram][]=32%20gb')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('products.total', 1)->where('products.data.0.attributes.0.value', '32 GB')
            ->where('facets.attributes.0.key', 'ram')->where('facets.attributes.0.options.0.count', 1));
    }

    public function test_variant_override_wins_for_filter_and_facets(): void
    {
        $product = $this->product();
        $ram = AttributeDefinition::where('key', 'ram')->firstOrFail();
        $product->attributes()->create(['attribute_definition_id' => $ram->id, 'value' => '32 GB']);
        $product->variants()->first()->attributes()->create(['attribute_definition_id' => $ram->id, 'value' => '16 GB']);
        $this->get('/catalog?attributes[ram][]=32%20gb')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('products.total', 0)->has('facets.attributes.0.options', 2)
            ->where('facets.attributes.0.options.0.value', '16 gb')
            ->where('facets.attributes.0.options.0.count', 1)
            // A selected unavailable value stays removable, but matches no product.
            ->where('facets.attributes.0.options.1.value', '32 gb')
            ->where('facets.attributes.0.options.1.count', 1));
        $this->get('/catalog?attributes[ram][]=16%20gb')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('products.total', 1)->where('products.data.0.attributes.0.value', '16 GB'));
    }
}
