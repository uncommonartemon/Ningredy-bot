<?php

namespace Tests\Feature;

use App\Models\AttributeDefinition;
use App\Models\AttributeValue;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Catalog\AttributeDictionary;
use App\Services\Products\RequiredSpecifications;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * Decision of 2026-11-13: one characteristic per meaning. The dictionary finds a characteristic
 * by its key, its key written another way, another name it was given or its name in any
 * language, and merges a duplicate into the one that stays.
 */
class AttributeDictionaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_characteristic_is_found_by_its_key_another_name_or_its_name_in_any_language(): void
    {
        $fans = AttributeDefinition::query()->create(['key' => 'cooling_fan_count', 'label' => 'Вентиляторы',
            'translations' => ['en' => 'Cooling fan count', 'cs' => 'Počet ventilátorů'], 'aliases' => ['Fans', 'fan_count']]);
        $dictionary = app(AttributeDictionary::class);

        $this->assertTrue($fans->is($dictionary->known('cooling_fan_count')));
        $this->assertTrue($fans->is($dictionary->known('cooling-fan-count')));
        $this->assertTrue($fans->is($dictionary->known('fan_count')));
        $this->assertTrue($fans->is($dictionary->known('unheard_of', 'FANS')));
        $this->assertNull($dictionary->known('number_of_fans', 'Cooling fan count'));
        $this->assertTrue($fans->is($dictionary->find('number_of_fans', 'Cooling fan count')));
        $this->assertTrue($fans->is($dictionary->find(null, 'počet ventilátorů')));
        // Other names are kept lowercased, with spaces, once each, never the characteristic's own key.
        $this->assertSame(['fans', 'fan count'], $fans->fresh()->aliases);
        // A name two characteristics answer to means neither.
        AttributeDefinition::query()->create(['key' => 'case_fans', 'label' => 'Case fans', 'translations' => ['en' => 'Cooling fan count']]);
        $this->assertNull($dictionary->find(null, 'Cooling fan count'));
    }

    public function test_a_required_characteristic_named_another_way_is_not_reported_missing(): void
    {
        $category = Category::query()->where('slug', 'components')->firstOrFail();
        $ram = AttributeDefinition::query()->where('key', 'ram')->firstOrFail();
        $ram->update(['aliases' => ['memory size']]);
        $category->syncRequiredAttributes([$ram->id]);

        $this->assertSame([], RequiredSpecifications::missing($category, [['key' => 'memory_size', 'name' => 'Memory size', 'value' => '16 GB']]));
        $this->assertSame('ram', RequiredSpecifications::missing($category, [['key' => 'cache', 'name' => 'Cache', 'value' => '30 MB']])[0]['key']);
    }

    public function test_a_merge_moves_the_values_keeps_a_products_own_and_remembers_the_names(): void
    {
        $category = Category::query()->where('slug', 'components')->firstOrFail();
        $into = AttributeDefinition::query()->create(['key' => 'cooling_fan_count', 'label' => 'Cooling fan count']);
        $from = AttributeDefinition::query()->create(['key' => 'fans', 'label' => 'Fans', 'translations' => ['cs' => 'Ventilátory'],
            'attribute_group_id' => null, 'default_unit' => 'pcs']);
        DB::table('attribute_definition_category')->insert([
            ['attribute_definition_id' => $from->id, 'category_id' => $category->id, 'is_required' => true, 'sort_order' => 3],
        ]);
        [$first, $second] = [$this->product('First cooler'), $this->product('Second cooler')];
        AttributeValue::query()->create(['attribute_definition_id' => $from->id, 'product_id' => $first->id, 'value' => '2']);
        AttributeValue::query()->create(['attribute_definition_id' => $from->id, 'product_id' => $second->id, 'value' => '3']);
        AttributeValue::query()->create(['attribute_definition_id' => $into->id, 'product_id' => $second->id, 'value' => '4']);
        $first->forceFill(['card_attribute_keys' => ['fans', 'tdp']])->saveQuietly();

        $result = app(AttributeDictionary::class)->merge($from, $into);

        $this->assertSame(['from' => 'fans', 'into' => 'cooling_fan_count', 'moved' => 1, 'dropped' => 1, 'swapped' => false], $result);
        $this->assertModelMissing($from);
        $this->assertSame('2', $first->attributes()->sole()->value);
        // The product's own value under the characteristic that stays is kept.
        $this->assertSame('4', $second->attributes()->sole()->value);
        $this->assertSame(['cooling_fan_count', 'tdp'], $first->fresh()->card_attribute_keys);
        $this->assertTrue((bool) DB::table('attribute_definition_category')->where('attribute_definition_id', $into->id)->value('is_required'));
        $into->refresh();
        // Its key and name become other names; its Czech name became the Czech name of the one that stays.
        $this->assertSame(['fans'], $into->aliases);
        $this->assertSame('Ventilátory', $into->translations['cs']);
        $this->assertSame('pcs', $into->default_unit);
        // The next card that calls it «Fans» lands in the characteristic that stayed.
        $this->assertTrue($into->is(app(AttributeDictionary::class)->known('fans')));
    }

    public function test_a_built_in_key_always_stays_and_two_built_in_keys_are_never_merged(): void
    {
        $ram = AttributeDefinition::query()->where('key', 'ram')->firstOrFail();
        $memory = AttributeDefinition::query()->create(['key' => 'system_memory', 'label' => 'System memory']);

        $result = app(AttributeDictionary::class)->merge($ram, $memory);

        $this->assertTrue($result['swapped']);
        $this->assertSame('ram', $result['into']);
        $this->assertModelMissing($memory);
        $this->assertContains('system memory', $ram->fresh()->aliases);

        $this->expectException(RuntimeException::class);
        app(AttributeDictionary::class)->merge($ram->fresh(), AttributeDefinition::query()->where('key', 'storage')->firstOrFail());
    }

    public function test_the_research_vocabulary_holds_every_characteristic_with_its_other_names(): void
    {
        AttributeDefinition::query()->create(['key' => 'cooling_fan_count', 'label' => 'Вентиляторы',
            'translations' => ['en' => 'Cooling fan count'], 'aliases' => ['fans']]);

        $vocabulary = app(AttributeDictionary::class)->vocabulary();

        $this->assertStringContainsString('cooling_fan_count (Cooling fan count; also: fans)', $vocabulary);
        // Characteristics without a single value yet are named too: research reuses their keys.
        $this->assertStringContainsString('refresh_rate (', $vocabulary);
    }

    private function product(string $title): Product
    {
        $product = Product::query()->create(['title' => $title, 'category_id' => Category::query()->where('slug', 'components')->value('id'),
            'product_type' => 'component', 'status' => 'published', 'is_active' => true]);
        ProductVariant::query()->create(['product_id' => $product->id, 'fingerprint' => md5($title), 'name' => 'Default', 'is_default' => true, 'is_active' => true]);

        return $product;
    }
}
