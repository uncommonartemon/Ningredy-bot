<?php

namespace Tests\Feature;

use App\Filament\Resources\Categories\Pages\EditCategory;
use App\Filament\Resources\Products\Pages\CreateProduct;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Support\PastedProductAttributes;
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

class FilamentCatalogConvenienceTest extends TestCase
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

    public function test_inline_category_selects_it_and_applies_its_type_and_starting_attributes(): void
    {
        $ram = AttributeDefinition::query()->where('key', 'ram')->sole();
        $page = Livewire::test(CreateProduct::class)->fillForm(['title' => 'Unsaved product'])
            ->callAction(TestAction::make('createOption')->schemaComponent('category_id'), data: [
                'name' => 'Inline devices', 'product_type_affinity' => 'component',
                'default_attribute_ids' => [$ram->id], 'is_active' => true,
            ])->assertHasNoActionErrors();
        $category = Category::query()->where('name', 'Inline devices')->sole();
        $this->assertEquals($category->id, $page->get('data.category_id'));
        $this->assertSame('component', $page->get('data.product_type'));
        $this->assertSame('Unsaved product', $page->get('data.title'));
        $this->assertSame('inline-devices', $category->slug);
        $variant = array_values($page->get('data.variants'))[0];
        $this->assertSame([$ram->id], array_column($variant['attributes'], 'attribute_definition_id'));
        $this->assertDatabaseMissing('products', ['title' => 'Unsaved product']);
    }

    public function test_category_can_create_and_select_a_default_attribute_without_navigation(): void
    {
        $category = Category::query()->where('slug', 'laptops')->sole();
        $page = Livewire::test(EditCategory::class, ['record' => $category->id])
            ->callAction(TestAction::make('createOption')->schemaComponent('default_attribute_ids'), data: [
                'label' => 'Keyboard backlight zones', 'data_type' => 'number', 'default_unit' => 'zones',
            ])->assertHasNoActionErrors();
        $definition = AttributeDefinition::query()->where('label', 'Keyboard backlight zones')->sole();
        $this->assertContains((string) $definition->id, array_map('strval', $page->get('data.default_attribute_ids')));
        $page->call('save')->assertHasNoFormErrors();
        $this->assertTrue($category->attributeDefinitions()->wherePivot('is_default', true)->whereKey($definition->id)->exists());
    }

    public function test_category_can_create_a_parent_without_losing_unsaved_category_fields(): void
    {
        $category = Category::query()->where('slug', 'laptops')->sole();
        $page = Livewire::test(EditCategory::class, ['record' => $category->id])
            ->fillForm(['name' => 'Renamed child'])
            ->callAction(TestAction::make('createOption')->schemaComponent('parent_id'), data: [
                'name' => 'Computing', 'product_type_affinity' => 'other',
            ])->assertHasNoActionErrors();
        $parent = Category::query()->where('name', 'Computing')->sole();
        $this->assertEquals($parent->id, $page->get('data.parent_id'));
        $this->assertSame('Renamed child', $page->get('data.name'));
        $page->call('save')->assertHasNoFormErrors();
        $this->assertEquals($parent->id, $category->fresh()->parent_id);
    }

    public function test_paste_into_variant_updates_empty_presets_and_saves_on_product_confirmation(): void
    {
        $page = Livewire::test(CreateProduct::class)
            ->set('data.category_id', Category::query()->where('slug', 'laptops')->value('id'));
        $variantKey = array_key_first($page->get('data.variants'));
        $action = TestAction::make('pasteAttributes')->schemaComponent("variants.$variantKey.attributes");
        $page->callAction($action, data: [
            'pasted_attributes' => "Attribute\tValue\tUnit\ncpu\tIntel Core i7\nram\t32\tGB",
        ])->assertHasNoActionErrors()->assertNotified('Характеристики вставлены');
        $this->assertDatabaseCount('attribute_values', 0);
        $rows = $page->get("data.variants.$variantKey.attributes");
        $this->assertCount(4, $rows);
        $page->fillForm(['title' => 'Pasted laptop', 'status' => 'draft'])->call('create')->assertHasNoFormErrors();
        $product = Product::query()->where('title', 'Pasted laptop')->sole();
        $attributes = $product->variants()->sole()->attributes()->with('definition')->get()->keyBy('definition.key');
        $this->assertCount(2, $attributes);
        $this->assertSame('Intel Core i7', $attributes['cpu']->value);
        $this->assertSame('32.0000', $attributes['ram']->numeric_value);
        $this->assertSame('GB', $attributes['ram']->unit);
    }

    public function test_paste_preserves_saved_rows_by_default_and_replaces_only_when_requested(): void
    {
        $product = Product::query()->create([
            'title' => 'Saved laptop', 'category_id' => Category::query()->where('slug', 'laptops')->value('id'),
            'product_type' => 'laptop', 'status' => 'draft',
        ]);
        $variant = $product->variants()->create(['condition' => 'new', 'currency' => 'CZK', 'stock_status' => 'unknown', 'is_default' => true]);
        $ram = AttributeDefinition::query()->where('key', 'ram')->sole();
        $attribute = $variant->attributes()->create(['attribute_definition_id' => $ram->id, 'value' => '16', 'unit' => 'GB']);
        $page = Livewire::test(EditProduct::class, ['record' => $product->id]);
        $variantKey = array_key_first($page->get('data.variants'));
        $action = TestAction::make('pasteAttributes')->schemaComponent("variants.$variantKey.attributes");
        $page->callAction($action, data: ['pasted_attributes' => "ram\t32\tGB"])->assertHasNoActionErrors();
        $rows = $page->get("data.variants.$variantKey.attributes");
        $rowKey = array_key_first($rows);
        $this->assertSame('16', $rows[$rowKey]['value']);
        $page->callAction($action, data: ['pasted_attributes' => "ram\t32\tGB", 'replace_filled' => true])
            ->assertHasNoActionErrors()->call('save')->assertHasNoFormErrors();
        $this->assertSame('32', $attribute->fresh()->value);
        $this->assertSame(1, $variant->attributes()->count());
    }

    public function test_clipboard_action_renders_in_each_admin_language(): void
    {
        $initial = app()->getLocale();
        foreach (['ru' => 'Вставить из Excel', 'en' => 'Paste from Excel', 'cs' => 'Vložit z Excelu', 'uk' => 'Вставити з Excel'] as $locale => $label) {
            $this->post('/admin/locale/'.$locale)->assertRedirect('/admin');
            $this->get('/admin/products/create')->assertOk()->assertSee($label);
            $this->assertSame($initial, app()->getLocale());
        }
    }

    public function test_invalid_clipboard_stays_in_the_modal_with_a_visible_field_error_and_preserves_form_state(): void
    {
        $page = Livewire::test(CreateProduct::class)
            ->set('data.category_id', Category::query()->where('slug', 'laptops')->value('id'));
        $variantKey = array_key_first($page->get('data.variants'));
        $before = $page->get("data.variants.$variantKey.attributes");
        $action = TestAction::make('pasteAttributes')->schemaComponent("variants.$variantKey.attributes");
        $page->callAction($action, data: ['pasted_attributes' => "unknown attribute\t42"])
            ->assertHasActionErrors(['pasted_attributes'])->assertActionMounted($action);
        $this->assertSame($before, $page->get("data.variants.$variantKey.attributes"));
        $this->assertDatabaseMissing('attribute_definitions', ['label' => 'unknown attribute']);
    }

    public function test_unknown_and_duplicate_attributes_reject_the_whole_clipboard_before_writes(): void
    {
        $before = AttributeDefinition::query()->count();
        $service = app(PastedProductAttributes::class);
        foreach (["Unseen attribute\t42", "Unseen attribute\t42\ncpu\tA\ncpu\tB", "Unseen attribute\t42\n\tBroken value"] as $text) {
            try {
                $service->merge($text, [], createMissing: $text !== "Unseen attribute\t42");
                $this->fail('Invalid clipboard was accepted.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('pasted_attributes', $exception->errors());
                $this->assertSame($before, AttributeDefinition::query()->count());
            }
        }
    }

    public function test_explicit_creation_uses_generated_keys_and_supports_quoted_excel_cells(): void
    {
        $result = app(PastedProductAttributes::class)->merge("Name\tValue\nBattery warranty\t\"One year\nSeller provided\"", [], createMissing: true);
        $definition = AttributeDefinition::query()->where('label', 'Battery warranty')->sole();
        $this->assertSame('battery-warranty', $definition->key);
        $this->assertSame(1, $result['created']);
        $this->assertSame("One year\nSeller provided", array_values($result['rows'])[0]['value']);
        $this->assertDatabaseCount('attribute_values', 0);
    }
}
