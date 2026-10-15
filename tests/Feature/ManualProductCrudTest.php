<?php

namespace Tests\Feature;

use App\Filament\Resources\Categories\Pages\EditCategory;
use App\Filament\Resources\Products\Pages\CreateProduct;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\Products\Pages\ViewProduct;
use App\Models\AttributeDefinition;
use App\Models\AttributeValue;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Services\Products\ManualProductAttributes;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class ManualProductCrudTest extends TestCase
{
    use RefreshDatabase;

    public function test_duplicate_sku_for_same_condition_is_a_form_error_not_a_database_crash(): void
    {
        Livewire::test(CreateProduct::class)->fillForm([
            'title' => 'Duplicate offers', 'status' => 'draft',
            'category_id' => Category::where('slug', 'laptops')->value('id'),
            'variants' => [
                'one' => ['sku' => 'SAME-123', 'condition' => 'new', 'currency' => 'CZK', 'stock_status' => 'unknown', 'quantity' => 0, 'is_default' => true, 'is_active' => true],
                'two' => ['sku' => 'SAME-123', 'condition' => 'new', 'currency' => 'CZK', 'stock_status' => 'unknown', 'quantity' => 0, 'is_default' => false, 'is_active' => true],
            ],
        ])->call('create')->assertHasFormErrors(['variants.one.sku', 'variants.two.sku']);
        $this->assertDatabaseMissing('products', ['title' => 'Duplicate offers']);
    }

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['services.admin.name' => 'ningredy']);
        $this->actingAs(User::factory()->create(['name' => 'ningredy', 'is_admin' => true]));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::bootCurrentPanel();
    }

    public function test_product_forms_use_static_actions_and_unsaved_changes_alerts(): void
    {
        $this->assertTrue(Filament::getPanel('admin')->hasUnsavedChangesAlerts());
        $this->assertFalse((new CreateProduct)->areFormActionsSticky());
        $this->assertFalse((new EditProduct)->areFormActionsSticky());
    }

    public function test_manual_open_box_product_can_be_created_and_saved_unchanged(): void
    {
        $page = Livewire::test(CreateProduct::class)
            ->set('data.category_id', Category::where('slug', 'laptops')->value('id'));
        $variantKey = array_key_first($page->get('data.variants'));
        $page->set("data.variants.$variantKey.condition", 'open_box')
            ->fillForm(['title' => 'Manual open box laptop'])
            ->call('create')->assertHasNoFormErrors();
        $product = Product::where('title', 'Manual open box laptop')->sole();
        $this->assertSame('open_box', $product->variants()->sole()->condition);
        $this->assertTrue(Product::visibleInCatalog()->whereKey($product->id)->exists());
        $this->assertSame(0, $product->media()->count());

        Livewire::test(EditProduct::class, ['record' => $product->id])
            ->call('save')->assertHasNoFormErrors();
        $this->assertSame('open_box', $product->variants()->sole()->condition);
    }

    public function test_product_view_uses_translated_type_and_availability_labels(): void
    {
        $product = Product::create([
            'title' => 'Readable product labels',
            'category_id' => Category::where('slug', 'laptops')->value('id'),
            'product_type' => 'laptop', 'status' => 'draft',
        ]);
        $product->variants()->create([
            'condition' => 'open_box', 'stock_status' => 'unknown',
            'currency' => 'CZK', 'is_default' => true,
        ]);
        foreach (['ru', 'cs', 'uk', 'en'] as $locale) {
            app()->setLocale($locale);
            Livewire::test(ViewProduct::class, ['record' => $product->id])
                ->assertSee(__('Ноутбук'))->assertSee(__('Уточнить'))
                ->assertSee(__('Распакованный'));
        }
    }

    public function test_generated_codes_survive_duplicates_long_names_and_renaming(): void
    {
        $name = str_repeat('a', 255);
        $first = Category::create(['name' => $name]);
        $second = Category::create(['name' => $name]);
        $this->assertNotSame($first->slug, $second->slug);
        $this->assertSame(255, strlen($second->slug));
        $this->assertStringEndsWith('-2', $second->slug);
        $first->update(['name' => 'Renamed category']);
        $this->assertSame($name, $first->fresh()->slug);
    }

    public function test_category_seeds_three_empty_rows_and_saves_only_filled_values(): void
    {
        $page = Livewire::test(CreateProduct::class)
            ->set('data.category_id', Category::where('slug', 'laptops')->value('id'));
        $variants = $page->get('data.variants');
        $variantKey = array_key_first($variants);
        $rows = $variants[$variantKey]['attributes'];
        $this->assertSame(['gpu', 'cpu', 'display'], AttributeDefinition::find(array_column($rows, 'attribute_definition_id'))
            ->keyBy('id')->pipe(fn ($definitions) => array_map(fn ($row) => $definitions[$row['attribute_definition_id']]->key, array_values($rows))));
        $gpuKey = array_key_first($rows);
        $page->set("data.variants.$variantKey.attributes.$gpuKey.value", 'GeForce Test 5500')
            ->fillForm(['title' => 'Manual configurable laptop', 'status' => 'draft'])
            ->call('create')->assertHasNoFormErrors();
        $product = Product::where('title', 'Manual configurable laptop')->sole();
        $this->assertNotEmpty($product->slug);
        $this->assertNotEmpty($product->canonical_key);
        $variant = $product->variants()->sole();
        $this->assertTrue($variant->is_default);
        $this->assertSame('GeForce Test 5500', $variant->attributes()->sole()->value);
        $gpu = AttributeDefinition::where('key', 'gpu')->sole();
        $this->assertContains('GeForce Test 5500', app(ManualProductAttributes::class)->suggestions($gpu->id));
        $this->assertNotContains('GeForce Test 5500', app(ManualProductAttributes::class)->suggestions(AttributeDefinition::where('key', 'cpu')->value('id')));
    }

    public function test_category_defaults_are_editable_and_do_not_delete_other_category_links(): void
    {
        $category = Category::where('slug', 'laptops')->sole();
        $ramId = AttributeDefinition::where('key', 'ram')->value('id');
        $cpuId = AttributeDefinition::where('key', 'cpu')->value('id');
        $count = $category->attributeDefinitions()->count();
        Livewire::test(EditCategory::class, ['record' => $category->id])
            ->fillForm(['default_attribute_ids' => [$ramId, $cpuId]])->call('save')->assertHasNoFormErrors();
        $this->assertSame($count, $category->attributeDefinitions()->count());
        $this->assertSame([$ramId, $cpuId], $category->attributeDefinitions()->wherePivot('is_default', true)->pluck('attribute_definitions.id')->all());
        Livewire::test(EditCategory::class, ['record' => $category->id])
            ->fillForm(['default_attribute_ids' => []])->call('save')->assertHasNoFormErrors();
        $this->assertSame([], app(ManualProductAttributes::class)->defaults($category->id));
    }

    public function test_changing_category_keeps_entered_specs_and_removes_only_empty_presets(): void
    {
        $laptops = Category::where('slug', 'laptops')->sole();
        $other = Category::create(['name' => 'New hardware', 'product_type_affinity' => 'other']);
        $ramId = AttributeDefinition::where('key', 'ram')->value('id');
        $other->syncDefaultAttributes([$ramId]);
        $page = Livewire::test(CreateProduct::class)->set('data.category_id', $laptops->id);
        $variants = $page->get('data.variants');
        $key = array_key_first($variants);
        $gpuKey = array_key_first($variants[$key]['attributes']);
        $page->set("data.variants.$key.attributes.$gpuKey.value", 'Existing GPU')
            ->set('data.category_id', $other->id);
        $rows = $page->get("data.variants.$key.attributes");
        $this->assertCount(2, $rows);
        $this->assertSame('Existing GPU', $rows[$gpuKey]['value']);
        $this->assertContains($ramId, array_column($rows, 'attribute_definition_id'));
        $page->set('data.category_id', $laptops->id)->set('data.category_id', $laptops->id);
        $rows = $page->get("data.variants.$key.attributes");
        $this->assertCount(3, $rows);
        $this->assertCount(3, array_unique(array_column($rows, 'attribute_definition_id')));
    }

    public function test_new_configuration_gets_category_defaults_without_a_second_default_variant(): void
    {
        $page = Livewire::test(CreateProduct::class)
            ->set('data.category_id', Category::where('slug', 'laptops')->value('id'));
        $page->callAction(TestAction::make('add')->schemaComponent('variants'))->assertHasNoActionErrors();
        $variants = array_values($page->get('data.variants'));
        $this->assertCount(2, $variants);
        $this->assertCount(3, $variants[1]['attributes']);
        $this->assertTrue($variants[0]['is_default']);
        $this->assertFalse($variants[1]['is_default']);
        $page->fillForm(['title' => 'Two configurations', 'status' => 'draft'])->call('create')->assertHasNoFormErrors();
        $this->assertSame(2, Product::where('title', 'Two configurations')->sole()->variants()->count());
    }

    public function test_inline_brand_and_attribute_creation_need_only_human_fields(): void
    {
        $page = Livewire::test(CreateProduct::class)
            ->callAction(TestAction::make('createOption')->schemaComponent('brand_id'), data: ['name' => 'Manual Brand'])
            ->assertHasNoActionErrors();
        $brand = Brand::where('name', 'Manual Brand')->sole();
        $this->assertSame('manual-brand', $brand->slug);
        $this->assertEquals($brand->id, $page->get('data.brand_id'));
        $page->set('data.category_id', Category::where('slug', 'laptops')->value('id'));
        $variants = $page->get('data.variants');
        $key = array_key_first($variants);
        $row = array_key_first($variants[$key]['attributes']);
        $page->callAction(TestAction::make('createOption')->schemaComponent("variants.$key.attributes.$row.attribute_definition_id"), data: [
            'label' => 'Keyboard lighting', 'data_type' => 'text',
        ])->assertHasNoActionErrors();
        $definition = AttributeDefinition::where('label', 'Keyboard lighting')->sole();
        $this->assertSame('keyboard-lighting', $definition->key);
        $this->assertEquals($definition->id, $page->get("data.variants.$key.attributes.$row.attribute_definition_id"));
    }

    public function test_editing_a_spec_refreshes_normalized_and_typed_values_without_renaming_keys(): void
    {
        $product = Product::create(['title' => 'Editable laptop', 'category_id' => Category::where('slug', 'laptops')->value('id'), 'product_type' => 'laptop', 'status' => 'draft']);
        $variant = $product->variants()->create(['condition' => 'new', 'stock_status' => 'unknown', 'currency' => 'CZK', 'is_default' => true]);
        $attribute = $variant->attributes()->create(['attribute_definition_id' => AttributeDefinition::where('key', 'ram')->value('id'), 'value' => '16 GB', 'numeric_value' => 16, 'unit' => 'GB']);
        $slug = $product->slug;
        $page = Livewire::test(EditProduct::class, ['record' => $product->id]);
        $variants = $page->get('data.variants');
        $key = array_key_first($variants);
        $row = array_key_first($variants[$key]['attributes']);
        $page->set("data.variants.$key.attributes.$row.value", '32 GB')->call('save')->assertHasNoFormErrors();
        $this->assertSame('32 gb', $attribute->fresh()->normalized_value);
        $this->assertSame('32.0000', $attribute->fresh()->numeric_value);
        $this->assertSame($slug, $product->fresh()->slug);
        $this->assertSame(1, AttributeValue::count());
    }
}
