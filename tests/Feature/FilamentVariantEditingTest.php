<?php

namespace Tests\Feature;

use App\Filament\Resources\Products\Pages\CreateProduct;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Support\PastedProductAttributes;
use App\Filament\Support\ProductVariantFormState;
use App\Models\AttributeDefinition;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class FilamentVariantEditingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['services.admin.name' => 'ningredy']);
        $this->actingAs(User::factory()->create(['name' => 'ningredy', 'is_admin' => true]));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::bootCurrentPanel();
    }

    public function test_first_attribute_selection_and_inline_creation_preserve_values_entered_before_the_name(): void
    {
        $page = Livewire::test(CreateProduct::class)->set('data.category_id', $this->category()->id);
        $variantKey = array_key_first($page->get('data.variants'));
        $page->callAction(TestAction::make('add')->schemaComponent("variants.$variantKey.attributes"));
        $row = array_key_last($page->get("data.variants.$variantKey.attributes"));
        $base = "data.variants.$variantKey.attributes.$row";
        $page->set("$base.value", '4')->set("$base.unit", 'ports')
            ->callAction(TestAction::make('createOption')->schemaComponent("variants.$variantKey.attributes.$row.attribute_definition_id"), data: [
                'label' => 'Expansion connectors', 'data_type' => 'number', 'default_unit' => 'connectors',
            ])->assertHasNoActionErrors();
        $this->assertSame('4', $page->get("$base.value"));
        $this->assertSame('ports', $page->get("$base.unit"));
        $page->fillForm(['title' => 'Typed before attribute'])->call('create')->assertHasNoFormErrors();
        $attribute = Product::where('title', 'Typed before attribute')->sole()->variants()->sole()->attributes()->sole();
        $this->assertSame('4', $attribute->value);
        $this->assertSame('ports', $attribute->unit);
    }

    public function test_changing_an_existing_attribute_clears_its_previous_value_and_uses_the_new_unit(): void
    {
        $page = Livewire::test(CreateProduct::class)->set('data.category_id', $this->category()->id);
        $variantKey = array_key_first($page->get('data.variants'));
        $row = array_key_first($page->get("data.variants.$variantKey.attributes"));
        $new = AttributeDefinition::create(['label' => 'Package mass', 'data_type' => 'number', 'default_unit' => 'kg']);
        $base = "data.variants.$variantKey.attributes.$row";
        $page->set("$base.value", 'Original GPU')->set("$base.attribute_definition_id", $new->id);
        $this->assertNull($page->get("$base.value"));
        $this->assertSame('kg', $page->get("$base.unit"));
    }

    public function test_category_change_keeps_a_manually_edited_unit_even_before_the_value_is_filled(): void
    {
        $page = Livewire::test(CreateProduct::class)->set('data.category_id', $this->category()->id);
        $variantKey = array_key_first($page->get('data.variants'));
        $row = array_key_first($page->get("data.variants.$variantKey.attributes"));
        $base = "data.variants.$variantKey.attributes.$row";
        $definitionId = $page->get("$base.attribute_definition_id");
        $newCategory = Category::create(['name' => 'Docking stations', 'product_type_affinity' => 'component']);
        $page->set("$base.unit", 'custom unit')->set('data.category_id', $newCategory->id);
        $this->assertEquals($definitionId, $page->get("$base.attribute_definition_id"));
        $this->assertSame('custom unit', $page->get("$base.unit"));
        $this->assertNull($page->get("$base.value"));
        $this->assertSame('component', $page->get('data.product_type'));
    }

    public function test_copying_a_saved_variant_creates_independent_attributes_and_does_not_copy_inventory_or_identifiers(): void
    {
        [$product, $variant, $attribute] = $this->savedProduct();
        $page = Livewire::test(EditProduct::class, ['record' => $product->id]);
        $sourceKey = array_key_first($page->get('data.variants'));
        $page->callAction(TestAction::make('clone')->schemaComponent('variants'), arguments: ['item' => $sourceKey])
            ->assertHasNoActionErrors();
        $variants = $page->get('data.variants');
        $this->assertCount(2, $variants);
        $copyKey = array_key_last($variants);
        $copy = $variants[$copyKey];
        foreach (['id', 'product_id', 'fingerprint'] as $field) {
            $this->assertArrayNotHasKey($field, $copy);
        }
        foreach (['sku', 'mpn', 'gtin', 'price', 'compare_at_price', 'warranty_months'] as $field) {
            $this->assertNull($copy[$field]);
        }
        $this->assertSame(0, $copy['quantity']);
        $this->assertSame('unknown', $copy['stock_status']);
        $this->assertFalse($copy['is_default']);
        $this->assertFalse(str_starts_with(array_key_first($copy['attributes']), 'record-'));
        $this->assertSame('16', array_values($copy['attributes'])[0]['value']);
        $this->assertSame('GB', array_values($copy['attributes'])[0]['unit']);
        $page->call('save')->assertHasNoFormErrors();
        $newVariant = $product->variants()->whereKeyNot($variant->id)->sole();
        $newAttribute = $newVariant->attributes()->sole();
        $this->assertNotSame($attribute->id, $newAttribute->id);
        $this->assertEquals($newVariant->id, $newAttribute->product_variant_id);
        $this->assertNotSame($variant->fingerprint, $newVariant->fingerprint);
        $this->assertSame('990.00', $variant->fresh()->price);
        $this->assertSame(5, $variant->fresh()->quantity);
        $this->assertSame('ORIGINAL-SKU', $variant->fresh()->sku);
        $this->assertSame(['cs' => 'Šestnáct'], $attribute->fresh()->translations);
        $this->assertSame(1, $product->variants()->where('is_default', true)->count());
    }

    public function test_primary_variant_can_be_switched_in_one_click_and_survives_deleting_the_old_primary(): void
    {
        [$product] = $this->savedProduct();
        $page = Livewire::test(EditProduct::class, ['record' => $product->id]);
        $first = array_key_first($page->get('data.variants'));
        $page->callAction(TestAction::make('clone')->schemaComponent('variants'), arguments: ['item' => $first]);
        $second = array_key_last($page->get('data.variants'));
        $page->set("data.variants.$second.is_default", true);
        $this->assertFalse($page->get("data.variants.$first.is_default"));
        $this->assertTrue($page->get("data.variants.$second.is_default"));
        $page->set("data.variants.$first.is_default", true);
        $this->assertFalse($page->get("data.variants.$second.is_default"));
        $page->callAction(TestAction::make('delete')->schemaComponent('variants'), arguments: ['item' => $first])
            ->assertHasNoActionErrors();
        $this->assertTrue($page->get("data.variants.$second.is_default"));
        $page->call('save')->assertHasNoFormErrors();
        $this->assertSame(1, $product->variants()->count());
        $this->assertNotNull($product->fresh()->defaultVariant);
        $this->assertSame(1, $product->variants()->sole()->attributes()->count());
    }

    public function test_new_products_need_one_variant_but_legacy_empty_products_can_be_edited_without_inventing_an_offer(): void
    {
        Livewire::test(CreateProduct::class)->fillForm([
            'title' => 'Empty new product', 'category_id' => $this->category()->id,
            'product_type' => 'laptop', 'status' => 'draft', 'variants' => [],
        ])->call('create')->assertHasFormErrors(['variants' => 'min']);
        $this->assertDatabaseMissing('products', ['title' => 'Empty new product']);
        $product = Product::create(['title' => 'Legacy empty', 'category_id' => $this->category()->id, 'product_type' => 'laptop', 'status' => 'draft']);
        Livewire::test(EditProduct::class, ['record' => $product->id])->fillForm(['title' => 'Updated legacy empty'])
            ->call('save')->assertHasNoFormErrors();
        $this->assertSame('Updated legacy empty', $product->fresh()->title);
        $this->assertSame(0, $product->variants()->count());
    }

    public function test_two_column_paste_keeps_units_but_an_explicit_empty_third_column_clears_them_and_stale_translations(): void
    {
        [$product, $variant, $attribute] = $this->savedProduct();
        $page = Livewire::test(EditProduct::class, ['record' => $product->id]);
        $variantKey = array_key_first($page->get('data.variants'));
        $action = TestAction::make('pasteAttributes')->schemaComponent("variants.$variantKey.attributes");
        $page->callAction($action, data: ['pasted_attributes' => "ram\t16", 'replace_filled' => true])
            ->assertHasNoActionErrors()->call('save')->assertHasNoFormErrors();
        $this->assertSame('GB', $attribute->fresh()->unit);
        $this->assertSame(['cs' => 'Šestnáct'], $attribute->fresh()->translations);
        $page->callAction($action, data: ['pasted_attributes' => "ram\t32\t", 'replace_filled' => true])
            ->assertHasNoActionErrors()->call('save')->assertHasNoFormErrors();
        $this->assertNull($attribute->fresh()->unit);
        $this->assertNull($attribute->fresh()->translations);
        $this->assertSame('32.0000', $attribute->fresh()->numeric_value);
        $this->assertSame(1, $variant->attributes()->count());
    }

    public function test_malformed_quoted_clipboards_are_rejected_before_creating_any_dictionary_records(): void
    {
        $before = AttributeDefinition::count();
        foreach (["New attribute\t\"unfinished\nram\t16", "New attribute\t\"finished\"garbage"] as $text) {
            try {
                app(PastedProductAttributes::class)->merge($text, [], createMissing: true);
                $this->fail('Malformed quoted clipboard was accepted.');
            } catch (ValidationException $exception) {
                $this->assertStringContainsString('кавычки', $exception->errors()['pasted_attributes'][0]);
                $this->assertSame($before, AttributeDefinition::count());
            }
        }
    }

    public function test_excel_bom_blank_lines_and_escaped_quotes_are_accepted_without_guessing_attributes(): void
    {
        $result = app(PastedProductAttributes::class)->merge("\xEF\xBB\xBF\n \nAttribute\tValue\nCPU\t\"Intel \"\"Demo\"\" processor\"", []);
        $this->assertSame('Intel "Demo" processor', array_values($result['rows'])[0]['value']);
        $this->assertSame(0, $result['created']);
        $this->assertSame(0, app(PastedProductAttributes::class)->merge(str_repeat("\n", 5).'ram: 0', [])['created']);
    }

    public function test_fresh_form_state_has_one_primary_variant_and_empty_category_defaults_only(): void
    {
        $fresh = ProductVariantFormState::fresh($this->category()->id);
        $this->assertCount(1, $fresh);
        $variant = array_values($fresh)[0];
        $this->assertTrue($variant['is_default']);
        $this->assertSame(0, $variant['quantity']);
        $this->assertCount(3, $variant['attributes']);
        foreach ($variant['attributes'] as $attribute) {
            $this->assertNull($attribute['value']);
        }
    }

    private function category(): Category
    {
        return Category::where('slug', 'laptops')->sole();
    }

    private function savedProduct(): array
    {
        $product = Product::create(['title' => 'Configurable product', 'category_id' => $this->category()->id, 'product_type' => 'laptop', 'status' => 'draft']);
        $variant = $product->variants()->create([
            'name' => '16 GB configuration', 'sku' => 'ORIGINAL-SKU', 'mpn' => 'ORIGINAL-MPN', 'gtin' => 'ORIGINAL-GTIN',
            'condition' => 'used', 'color' => 'Blue', 'price' => 990, 'compare_at_price' => 1200, 'warranty_months' => 12,
            'stock_status' => 'in_stock', 'quantity' => 5, 'currency' => 'CZK', 'is_default' => true, 'is_active' => true,
            'translations' => ['cs' => ['name' => 'Konfigurace 16 GB']],
        ]);
        $attribute = $variant->attributes()->create([
            'attribute_definition_id' => AttributeDefinition::where('key', 'ram')->value('id'),
            'value' => '16', 'unit' => 'GB', 'translations' => ['cs' => 'Šestnáct'],
        ]);

        return [$product, $variant, $attribute];
    }
}
