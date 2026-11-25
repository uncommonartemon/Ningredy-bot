<?php

namespace Tests\Feature;

use App\Models\AttributeDefinition;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CatalogDynamicFacetsTest extends TestCase
{
    use RefreshDatabase;

    private int $sequence = 0;

    public function test_category_facets_use_effective_published_data_instead_of_laptop_defaults(): void
    {
        $laptop = $this->product('laptops');
        $this->attribute($laptop->defaultVariant, 'screen_size', '15.6');
        $component = $this->product('components');
        $this->attribute($component, 'socket', 'AM5');
        $this->attribute($component->defaultVariant, 'private_note', 'Warehouse note', false);
        $this->product('components');

        $hidden = $this->product('components', ['is_active' => false]);
        $this->attribute($hidden->defaultVariant, 'hidden_attribute', 'Hidden');
        $draft = $this->product('components', ['status' => 'draft']);
        $this->attribute($draft->defaultVariant, 'draft_attribute', 'Draft');
        $inactive = $component->variants()->create(['is_active' => false]);
        $this->attribute($inactive, 'inactive_attribute', 'Inactive');

        $page = $this->catalog(['category' => 'components']);

        $this->assertSame(2, $page['products']['total']);
        $this->assertSame(['socket'], array_column($page['facets']['attributes'], 'key'));
        $this->assertSame(1, $this->option($page, 'attributes', 'socket', 'am5')['count']);
        $types = collect($this->catalog()['facets']['columns'])->firstWhere('key', 'types');
        $this->assertSame(['component', 'laptop'], array_column($types['options'], 'value'));
    }

    public function test_category_switch_drops_irrelevant_attribute_keys_and_preserves_shared_ones(): void
    {
        $laptop = $this->product('laptops');
        $this->attribute($laptop->defaultVariant, 'screen_size', '15.6');
        $this->attribute($laptop->defaultVariant, 'storage', '512 GB');
        $component = $this->product('components');
        $this->attribute($component->defaultVariant, 'storage', '512 GB');

        $page = $this->catalog(['category' => 'components', 'attributes' => [
            'screen_size' => ['15.6'], 'storage' => ['512 gb'],
        ]]);

        $this->assertSame(1, $page['products']['total']);
        $this->assertSame($component->id, $page['products']['data'][0]['id']);
        $this->assertArrayNotHasKey('screen_size', $page['filters']['attributes']);
        $this->assertSame(['512 gb'], $page['filters']['attributes']['storage']);
    }

    public function test_normalized_filter_url_drops_old_attributes_without_leaking_into_other_responses(): void
    {
        $laptop = $this->product('laptops');
        $this->attribute($laptop->defaultVariant, 'screen_size', '15.6');
        $component = $this->product('components', variant: ['price' => 100]);
        $this->attribute($component->defaultVariant, 'storage', '512 GB');
        $requested = ['category' => 'components', 'attributes' => [
            'screen_size' => ['15.6'], 'storage' => ['512 gb'],
        ], 'min_price' => 200, 'max_price' => 0];
        $canonical = $requested;
        unset($canonical['attributes']['screen_size']);
        $canonical['min_price'] = 0;
        $canonical['max_price'] = 200;

        $response = $this->get('/catalog?'.http_build_query($requested))->assertOk()->viewData('page');
        $this->assertSame('/catalog', parse_url($response['url'], PHP_URL_PATH));
        parse_str(parse_url($response['url'], PHP_URL_QUERY), $query);
        $this->assertEquals($canonical, $query);
        $this->assertSame(1, $response['props']['products']['total']);
        $this->get('/products/'.$component->slug)->assertOk()
            ->assertInertia(fn (Assert $page) => $page->url('/products/'.$component->slug));
    }

    public function test_counts_predict_or_addition_and_selected_removal_while_other_groups_remain_and(): void
    {
        $this->matrix();

        $page = $this->catalog(['brands' => ['Alpha'], 'colors' => ['Red']]);

        $this->assertSame(1, $page['products']['total']);
        $this->assertSame(2, $this->option($page, 'columns', 'brands', 'Beta')['count']);
        $this->assertSame(2, $this->option($page, 'columns', 'brands', 'Alpha')['count']);
        $this->assertSame(2, $this->option($page, 'columns', 'colors', 'Blue')['count']);
        $this->assertSame(2, $this->option($page, 'columns', 'colors', 'Red')['count']);
        $this->assertSame(1, $this->option($page, 'attributes', 'ram', '16 gb')['count']);
        $this->assertSame(0, $this->option($page, 'attributes', 'ram', '32 gb')['count']);

        $page = $this->catalog(['brands' => ['Alpha', 'Beta'], 'colors' => ['Red']]);
        $this->assertSame(2, $page['products']['total']);
        $this->assertSame(1, $this->option($page, 'columns', 'brands', 'Alpha')['count']);
        $this->assertSame(1, $this->option($page, 'columns', 'brands', 'Beta')['count']);
        $this->assertSame(1, $this->option($page, 'attributes', 'ram', '32 gb')['count']);
    }

    public function test_every_displayed_option_count_matches_the_result_of_its_actual_toggle(): void
    {
        $this->matrix();
        $filters = ['category' => 'laptops', 'brands' => ['Alpha', 'Beta'], 'colors' => ['Red'],
            'attributes' => ['ram' => ['16 gb']], 'max_price' => 350, 'per_page' => 1];
        $page = $this->catalog($filters);

        foreach (['columns', 'attributes'] as $section) {
            foreach ($page['facets'][$section] as $group) {
                foreach ($group['options'] as $option) {
                    $next = $filters;
                    $key = $group['key'];
                    $values = $section === 'attributes' ? ($next['attributes'][$key] ?? []) : ($next[$key] ?? []);
                    $values = in_array($option['value'], $values, true)
                        ? array_values(array_diff($values, [$option['value']]))
                        : [...$values, $option['value']];
                    if ($section === 'attributes') {
                        $next['attributes'][$key] = $values;
                    } else {
                        $next[$key] = $values;
                    }
                    $actual = $this->catalog($next);
                    $this->assertSame($actual['products']['total'], $option['count'],
                        "Wrong predicted count for {$section}.{$key}.{$option['value']}");
                }
            }
        }
    }

    public function test_all_variant_filters_and_counts_require_the_same_active_variant(): void
    {
        $product = $this->product('laptops', variant: ['color' => 'Red', 'price' => 100, 'stock_status' => 'out_of_stock']);
        $this->attribute($product->defaultVariant, 'ram', '16 GB');
        $silver = $product->variants()->create(['color' => 'Silver', 'price' => 200,
            'stock_status' => 'in_stock', 'is_active' => true]);
        $this->attribute($silver, 'ram', '32 GB');
        $inactive = $product->variants()->create(['color' => 'Red', 'price' => 100,
            'stock_status' => 'in_stock', 'is_active' => false]);
        $this->attribute($inactive, 'ram', '32 GB');

        $page = $this->catalog(['colors' => ['Red'], 'stock' => ['in_stock']]);

        $this->assertSame(0, $page['products']['total']);
        $this->assertSame(0, $this->option($page, 'attributes', 'ram', '32 gb')['count']);
        $this->assertSame(1, $this->option($page, 'columns', 'colors', 'Silver')['count']);
        $this->assertSame(1, $this->option($page, 'columns', 'stock', 'out_of_stock')['count']);
        $this->assertNull($page['facets']['price']['min']);
        $this->assertNull($page['facets']['price']['max']);

        $page = $this->catalog(['colors' => ['Red'], 'attributes' => ['ram' => ['32 gb']], 'max_price' => 150]);
        $this->assertSame(0, $page['products']['total']);
        $this->assertSame(0, $this->option($page, 'columns', 'stock', 'in_stock')['count']);
    }

    public function test_inherited_values_and_overrides_count_distinct_products_and_deduplicate_normalized_options(): void
    {
        $product = $this->product('laptops');
        $this->attribute($product, 'ram', '32 GB');
        $this->attribute($product->defaultVariant, 'ram', '16 GB');
        $product->variants()->create(['is_active' => true]);
        $product->variants()->create(['is_active' => true]);
        $second = $this->product('laptops');
        $this->attribute($second->defaultVariant, 'ram', '32 gb');

        $page = $this->catalog();
        $group = collect($page['facets']['attributes'])->firstWhere('key', 'ram');

        $this->assertCount(2, $group['options']);
        $this->assertSame(2, $this->option($page, 'attributes', 'ram', '32 gb')['count']);
        $this->assertSame(1, $this->option($page, 'attributes', 'ram', '16 gb')['count']);

        $page = $this->catalog(['attributes' => ['ram' => ['16 gb']]]);
        $this->assertSame(1, $page['products']['total']);
        $this->assertSame($product->defaultVariant->id, $page['products']['data'][0]['variant_id']);
        $this->assertSame(2, $this->option($page, 'attributes', 'ram', '32 gb')['count']);

        $page = $this->catalog(['attributes' => ['ram' => ['16 gb', '32 gb']]]);
        $this->assertSame(2, $page['products']['total']);
        $this->assertSame(1, $this->option($page, 'attributes', 'ram', '32 gb')['count']);
        $this->assertSame(2, $this->option($page, 'attributes', 'ram', '16 gb')['count']);
    }

    public function test_category_counts_predict_switch_and_clear_only_irrelevant_attributes(): void
    {
        $laptop = $this->product('laptops', variant: ['price' => 100]);
        $this->attribute($laptop->defaultVariant, 'screen_size', '15.6');
        $this->product('components', variant: ['price' => 50]);
        $this->product('components', variant: ['price' => 500]);

        $page = $this->catalog(['category' => 'laptops', 'attributes' => ['screen_size' => ['15.6']], 'max_price' => 150]);
        $component = collect($page['facets']['categories'])->firstWhere('value', 'components');

        $this->assertSame(1, $component['count']);
        $this->assertSame(1, $page['facets']['allCategoriesCount']);
        $switched = $this->catalog(['category' => 'components', 'attributes' => ['screen_size' => ['15.6']], 'max_price' => 150]);
        $this->assertSame($component['count'], $switched['products']['total']);
    }

    public function test_price_bounds_follow_other_filters_but_do_not_shrink_to_the_selected_range(): void
    {
        $free = $this->product('laptops', ['brand' => 'Alpha'], ['price' => 0]);
        $this->product('laptops', ['brand' => 'Alpha'], ['price' => 150]);
        $this->product('laptops', ['brand' => 'Alpha'], ['price' => null]);
        $this->product('laptops', ['brand' => 'Beta'], ['price' => 500]);
        $this->product('components', ['brand' => 'Alpha'], ['price' => 5]);

        $page = $this->catalog(['category' => 'laptops', 'brands' => ['Alpha'], 'min_price' => 100, 'max_price' => 150]);
        $this->assertSame(1, $page['products']['total']);
        $this->assertNotNull($page['facets']['price']['min']);
        $this->assertEquals(0, $page['facets']['price']['min']);
        $this->assertEquals(150, $page['facets']['price']['max']);

        $page = $this->catalog(['category' => 'laptops', 'brands' => ['Alpha'], 'max_price' => 0]);
        $this->assertSame(1, $page['products']['total']);
        $this->assertSame($free->id, $page['products']['data'][0]['id']);
        $this->assertSame(0.0, $page['products']['data'][0]['price']);

        $page = $this->catalog(['category' => 'laptops', 'min_price' => 200, 'max_price' => 100]);
        $this->assertSame(1, $page['products']['total']);
        $this->assertEquals(100, $page['filters']['min_price']);
        $this->assertEquals(200, $page['filters']['max_price']);
    }

    public function test_exact_identifier_limits_price_bounds_and_other_facet_counts_to_its_variant(): void
    {
        $product = $this->product('laptops', ['title' => 'TARGET-SKU laptop'], ['price' => 100, 'color' => 'Red']);
        $target = $product->variants()->create(['sku' => 'TARGET-SKU', 'mpn' => 'TARGET-MPN', 'gtin' => '1234567890123',
            'price' => 1800, 'color' => 'Silver', 'is_active' => true]);

        foreach (['target-sku', 'TARGET-MPN', '1234567890123'] as $search) {
            $page = $this->catalog(['search' => $search]);
            $this->assertSame($target->id, $page['products']['data'][0]['variant_id']);
            $this->assertEquals(1800, $page['facets']['price']['min']);
            $this->assertEquals(1800, $page['facets']['price']['max']);
            $this->assertSame(0, $this->option($page, 'columns', 'colors', 'Red')['count']);
            $this->assertSame(1, $this->option($page, 'columns', 'colors', 'Silver')['count']);
        }
    }

    public function test_empty_category_and_unknown_prices_have_no_fabricated_price_bounds(): void
    {
        $category = Category::create(['slug' => 'empty-category', 'name' => 'Empty category', 'is_active' => true]);
        $laptop = $this->product('laptops');
        $this->attribute($laptop->defaultVariant, 'screen_size', '15.6');
        $this->product('components', variant: ['price' => null]);

        $page = $this->catalog(['category' => $category->slug, 'attributes' => ['screen_size' => ['15.6']]]);
        $this->assertSame(0, $page['products']['total']);
        $this->assertSame([], $page['facets']['attributes']);
        $this->assertEmpty($page['filters']['attributes']);
        $this->assertNull($page['facets']['price']['min']);
        $this->assertNull($page['facets']['price']['max']);
        $this->assertSame(0, collect($page['facets']['categories'])->firstWhere('value', $category->slug)['count']);

        $page = $this->catalog(['category' => 'components']);
        $this->assertSame(1, $page['products']['total']);
        $this->assertNull($page['facets']['price']['min']);
        $this->assertNull($page['facets']['price']['max']);
    }

    public function test_visible_products_without_active_offers_are_counted_until_a_variant_filter_is_used(): void
    {
        $this->product('laptops');
        $noOffer = $this->product('laptops', variant: ['is_active' => false, 'price' => 999999]);
        $this->product('laptops', ['brand' => 'Other brand'], ['color' => 'Silver']);

        $page = $this->catalog(['category' => 'laptops']);
        $this->assertSame(3, $page['products']['total']);
        $this->assertSame(2, $this->option($page, 'columns', 'brands', 'Test brand')['count']);
        $this->assertSame(1, $this->option($page, 'columns', 'colors', 'Black')['count']);
        $this->assertEquals(100, $page['facets']['price']['max']);

        $filtered = $this->catalog(['category' => 'laptops', 'colors' => ['Black']]);
        $this->assertSame(1, $filtered['products']['total']);
        $this->assertNotContains($noOffer->id, array_column($filtered['products']['data'], 'id'));
    }

    public function test_selected_values_remain_available_to_remove_when_their_predicted_count_is_zero(): void
    {
        $product = $this->product('laptops', ['brand' => 'Alpha'], ['color' => 'Red']);
        $this->attribute($product->defaultVariant, 'ram', '16 GB');

        $page = $this->catalog(['brands' => ['Alpha'], 'colors' => ['Silver'], 'attributes' => ['ram' => ['64 gb']]]);
        $this->assertSame(0, $page['products']['total']);
        $this->assertSame(['Silver'], $page['filters']['colors']);
        $this->assertSame(['64 gb'], $page['filters']['attributes']['ram']);
        $this->assertSame(0, $this->option($page, 'columns', 'colors', 'Silver')['count']);
        $this->assertSame(0, $this->option($page, 'attributes', 'ram', '64 gb')['count']);
    }

    public function test_filters_carry_the_translations_of_their_names_and_values(): void
    {
        $product = $this->product('laptops');
        $definition = AttributeDefinition::create(['key' => 'wireless_lan_standard', 'label' => 'Wi-Fi standard', 'is_filterable' => true,
            'translations' => ['en' => 'Wi-Fi standard', 'cs' => 'Standard Wi-Fi']]);
        $product->defaultVariant->attributes()->create(['attribute_definition_id' => $definition->id, 'value' => 'Yes',
            'translations' => ['cs' => 'Ano']]);
        $this->product('laptops');

        $facet = collect($this->catalog()['facets']['attributes'])->firstWhere('key', 'wireless_lan_standard');

        $this->assertSame(['en' => 'Wi-Fi standard', 'cs' => 'Standard Wi-Fi'], $facet['label_translations']);
        $this->assertSame(['cs' => 'Ano'], $facet['options'][0]['translations']);
    }

    public function test_a_filter_where_every_product_has_its_own_value_is_left_out(): void
    {
        for ($i = 1; $i <= 12; $i++) {
            $product = $this->product('laptops');
            $this->attribute($product->defaultVariant, 'weight', (1 + $i / 100).' kg');
            $this->attribute($product->defaultVariant, 'ram', ($i % 3 === 0 ? 32 : 16).' GB');
        }

        $keys = array_column($this->catalog()['facets']['attributes'], 'key');

        $this->assertNotContains('weight', $keys);
        $this->assertContains('ram', $keys);
    }

    public function test_a_filter_carried_by_too_few_products_of_the_category_is_left_out(): void
    {
        for ($i = 1; $i <= 20; $i++) {
            $product = $this->product('laptops');
            $this->attribute($product->defaultVariant, 'ram', ($i % 2 === 0 ? 32 : 16).' GB');
            if ($i === 1) {
                $this->attribute($product->defaultVariant, 'cpu_architecture', 'Raptor Lake');
            }
        }

        $keys = array_column($this->catalog(['category' => 'laptops'])['facets']['attributes'], 'key');

        $this->assertSame(['ram'], $keys);
    }

    public function test_a_filter_whose_only_value_every_product_of_the_category_shares_is_left_out(): void
    {
        // Three memory modules: all of one type and in stock, all SO-DIMM. Ticking any of that
        // leaves the list as it was, so only what tells them apart is offered.
        foreach ([['Alpha', '8 GB'], ['Beta', '16 GB'], ['Gamma', '16 GB']] as [$brand, $capacity]) {
            $module = $this->product('components', ['brand' => $brand], ['color' => null]);
            $this->attribute($module->defaultVariant, 'capacity', $capacity);
            $this->attribute($module, 'module_type', 'SO-DIMM');
        }
        $this->product('laptops');

        $page = $this->catalog(['category' => 'components']);

        $this->assertSame(['brands'], array_column($page['facets']['columns'], 'key'));
        $this->assertSame(['capacity'], array_column($page['facets']['attributes'], 'key'));

        // The same filters tell products apart once the whole catalog is shown.
        $all = $this->catalog();
        $this->assertSame(['brands', 'colors', 'types'], array_column($all['facets']['columns'], 'key'));
        $this->assertContains('module_type', array_column($all['facets']['attributes'], 'key'));

        // A module that is sold out: availability now narrows the category.
        $this->product('components', ['brand' => 'Alpha'], ['color' => null, 'stock_status' => 'out_of_stock']);
        $this->assertSame(['brands', 'stock'], array_column($this->catalog(['category' => 'components'])['facets']['columns'], 'key'));
    }

    public function test_a_colour_too_few_products_of_the_category_carry_is_left_out_like_a_characteristic(): void
    {
        // The board colour written for one memory module in twenty is no filter of memory.
        for ($i = 1; $i <= 20; $i++) {
            $this->product('components', ['brand' => $i % 2 === 0 ? 'Alpha' : 'Beta'], ['color' => $i === 1 ? 'Green' : null]);
        }

        $this->assertSame(['brands'], array_column($this->catalog(['category' => 'components'])['facets']['columns'], 'key'));

        $this->product('components', ['brand' => 'Alpha'], ['color' => 'Green']);
        $this->product('components', ['brand' => 'Beta'], ['color' => 'Black']);
        $this->assertSame(['brands', 'colors'], array_column($this->catalog(['category' => 'components'])['facets']['columns'], 'key'));
    }

    public function test_a_ticked_filter_stays_to_be_unticked_in_a_category_where_it_narrows_nothing(): void
    {
        foreach (['Alpha', 'Beta'] as $brand) {
            $module = $this->product('components', ['brand' => $brand]);
            $this->attribute($module, 'module_type', 'SO-DIMM');
        }
        $this->product('laptops');

        // Ticked while the whole catalog was shown, then the category was chosen.
        $page = $this->catalog(['category' => 'components', 'types' => ['component'], 'attributes' => ['module_type' => ['so-dimm']]]);

        $this->assertSame(2, $page['products']['total']);
        $this->assertSame(['brands', 'types'], array_column($page['facets']['columns'], 'key'));
        $this->assertSame(2, $this->option($page, 'columns', 'types', 'component')['count']);
        $this->assertSame(2, $this->option($page, 'attributes', 'module_type', 'so-dimm')['count']);
    }

    public function test_the_brand_and_availability_lead_and_the_colour_type_and_country_follow_the_characteristics(): void
    {
        // What a category's products differ in is chosen before their colour: the page puts the
        // leading sections above the characteristics and the others below them.
        $this->matrix();
        $component = $this->product('components', ['brand' => 'Alpha'], ['stock_status' => 'out_of_stock']);
        $this->attribute($component->defaultVariant, 'ram', '16 GB');

        $columns = $this->catalog()['facets']['columns'];

        $this->assertSame(['brands', 'stock', 'colors', 'types', 'countries'], array_column($columns, 'key'));
        $this->assertSame([true, true, false, false, false], array_column($columns, 'leading'));
    }

    public function test_many_distinct_filter_values_do_not_add_one_database_query_per_option(): void
    {
        // Two products with different values: the sections are offered before the options multiply.
        foreach (['0', 'zero'] as $name) {
            $product = $this->product('laptops', variant: ['color' => 'Color '.$name]);
            $this->attribute($product->defaultVariant, 'ram', 'Value '.$name);
        }
        $this->catalog();
        $before = $this->catalogQueryCount();

        for ($i = 1; $i <= 40; $i++) {
            $product = $this->product('laptops', variant: ['color' => 'Color '.$i]);
            $this->attribute($product->defaultVariant, 'ram', 'Value '.$i);
        }

        $after = $this->catalogQueryCount();
        $this->assertLessThanOrEqual($before + 12, $after,
            'Adding 80 filter choices must not create one count query per choice.');
    }

    private function matrix(): void
    {
        foreach ([
            ['Alpha', 'CZ', 'Red', '16 GB', 100],
            ['Alpha', 'CZ', 'Blue', '32 GB', 150],
            ['Beta', 'US', 'Red', '32 GB', 200],
            ['Beta', 'US', 'Blue', '16 GB', 300],
            ['Gamma', 'TW', 'Green', '64 GB', 400],
        ] as [$brand, $country, $color, $ram, $price]) {
            $product = $this->product('laptops', ['brand' => $brand, 'country' => $country], compact('color', 'price'));
            $this->attribute($product->defaultVariant, 'ram', $ram);
        }
    }

    public function test_a_shop_with_a_brand_per_product_still_offers_the_brand_but_not_a_colour_per_product(): void
    {
        // The brands are a list the shop keeps; a colour is written by hand and may name single products.
        for ($i = 1; $i <= 13; $i++) {
            $this->product('components', ['brand' => 'Maker '.$i], ['color' => 'Shade '.$i]);
        }

        $this->assertSame(['brands'], array_column($this->catalog(['category' => 'components'])['facets']['columns'], 'key'));
    }

    private function product(string $category, array $attributes = [], array $variant = []): Product
    {
        $number = ++$this->sequence;
        $brand = Brand::firstOrCreate(['name' => $attributes['brand'] ?? 'Test brand'], [
            'slug' => str($attributes['brand'] ?? 'Test brand')->slug()->toString(),
            'country' => $attributes['country'] ?? 'CZ', 'is_active' => true,
        ]);
        unset($attributes['brand'], $attributes['country']);
        $product = Product::create([
            'category_id' => Category::where('slug', $category)->value('id'), 'brand_id' => $brand->id,
            'product_type' => $category === 'laptops' ? 'laptop' : 'component',
            'status' => 'published', 'is_active' => true, 'title' => 'Facet product '.$number,
            'slug' => 'facet-product-'.$number, 'canonical_key' => 'facet-product-'.$number,
            ...$attributes,
        ]);
        $product->variants()->create([
            'sku' => 'FACET-SKU-'.$number, 'price' => 100, 'is_default' => true, 'is_active' => true,
            'color' => 'Black', 'stock_status' => 'in_stock', ...$variant,
        ]);

        return $product;
    }

    private function attribute(Product|ProductVariant $owner, string $key, string $value, bool $filterable = true): void
    {
        $definition = AttributeDefinition::firstOrCreate(['key' => $key], ['label' => $key, 'is_filterable' => $filterable]);
        $owner->attributes()->create(['attribute_definition_id' => $definition->id, 'value' => $value]);
    }

    private function catalog(array $filters = []): array
    {
        return $this->get('/catalog?'.http_build_query($filters))->assertOk()->viewData('page')['props'];
    }

    private function option(array $page, string $section, string $key, string $value): array
    {
        $group = collect($page['facets'][$section])->firstWhere('key', $key);
        $this->assertNotNull($group, "Missing facet {$section}.{$key}");
        $option = collect($group['options'])->firstWhere('value', $value);
        $this->assertNotNull($option, "Missing option {$section}.{$key}.{$value}");

        return $option;
    }

    private function catalogQueryCount(): int
    {
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $this->catalog();

            return count(DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
    }
}
