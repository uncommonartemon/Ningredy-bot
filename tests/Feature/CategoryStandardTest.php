<?php

namespace Tests\Feature;

use App\Filament\Resources\Categories\Pages\EditCategory;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Models\AttributeDefinition;
use App\Models\AttributeValue;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\Catalog\CategoryStandard;
use App\Services\Catalog\TitleComposer;
use App\Services\Products\ManualProductAttributes;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Decision of 2026-11-13: the standard of a category - its form of characteristics with an
 * example, an importance and a section, set by the operator, and the product title built from
 * its parts by the server.
 */
class CategoryStandardTest extends TestCase
{
    use RefreshDatabase;

    private Category $ram;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        config(['services.admin.name' => 'ningredy']);
        $this->actingAs(User::factory()->create(['name' => 'ningredy', 'is_admin' => true]));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::bootCurrentPanel();
        $this->ram = Category::query()->where('slug', 'ram')->firstOrFail();
    }

    public function test_the_form_keeps_its_order_example_importance_and_section_and_a_product_starts_with_it(): void
    {
        [$capacity, $type, $voltage] = [$this->definition('ram'), $this->definition('memory_type'), $this->definition('voltage', 'Operating voltage')];

        Livewire::test(EditCategory::class, ['record' => $this->ram->id])
            ->set('data.standard_form', [
                'a' => ['attribute_definition_id' => $type->id, 'example' => 'DDR4', 'is_required' => '1', 'section' => 'main'],
                'b' => ['attribute_definition_id' => $capacity->id, 'example' => '16 GB', 'is_required' => '1', 'section' => 'main'],
                'c' => ['attribute_definition_id' => $voltage->id, 'example' => '1.2 V', 'is_required' => '0', 'section' => 'extra'],
            ])->call('save')->assertHasNoFormErrors();

        $rows = app(CategoryStandard::class)->formRows($this->ram->fresh());
        $this->assertSame([$type->id, $capacity->id, $voltage->id], array_column($rows, 'attribute_definition_id'));
        $this->assertSame(['DDR4', '16 GB', '1.2 V'], array_column($rows, 'example'));
        $this->assertSame([true, true, false], array_column($rows, 'is_required'));
        $this->assertSame(['main', 'main', 'extra'], array_column($rows, 'section'));
        $this->assertSame([$type->id, $capacity->id, $voltage->id],
            array_column(array_values(app(ManualProductAttributes::class)->defaults($this->ram->id)), 'attribute_definition_id'));

        // Taken out of the form, a characteristic keeps its link to the category.
        Livewire::test(EditCategory::class, ['record' => $this->ram->id])
            ->set('data.standard_form', ['a' => ['attribute_definition_id' => $capacity->id, 'example' => '16 GB', 'is_required' => '1', 'section' => 'main']])
            ->call('save')->assertHasNoFormErrors();
        $this->assertSame([$capacity->id], array_column(app(CategoryStandard::class)->formRows($this->ram->fresh()), 'attribute_definition_id'));
        $this->assertTrue($this->ram->attributeDefinitions()->whereKey($voltage->id)->exists());
        $this->assertSame([], $this->ram->requiredAttributeDefinitions()->whereKey($type->id)->pluck('attribute_definitions.id')->all());
    }

    public function test_a_kind_without_its_own_form_uses_its_parents(): void
    {
        $components = Category::query()->where('slug', 'components')->firstOrFail();
        $this->ram->attributeDefinitions()->newPivotQuery()->update(['is_default' => false, 'is_required' => false]);
        app(CategoryStandard::class)->syncForm($components, [['attribute_definition_id' => $this->definition('ram')->id, 'example' => '16 GB']]);

        $this->assertSame(['ram'], app(CategoryStandard::class)->form($this->ram->fresh())->pluck('key')->all());
    }

    public function test_the_form_is_collected_from_the_products_the_category_has(): void
    {
        $type = $this->definition('memory_type');
        $voltage = $this->definition('voltage', 'Operating voltage');
        foreach (['DDR4', 'DDR4', 'DDR5'] as $index => $value) {
            $product = $this->product("Module {$index}");
            $this->value($product, $type, $value);
            if ($index === 0) {
                $this->value($product, $voltage, '1.2', 'V');
            }
        }
        $this->ram->attributeDefinitions()->newPivotQuery()->update(['is_default' => false, 'is_required' => false]);

        $page = Livewire::test(EditCategory::class, ['record' => $this->ram->id])
            ->callAction(TestAction::make('collectStandardForm')->schemaComponent('standard_form'))->assertHasNoActionErrors();

        $rows = array_values($page->get('data.standard_form'));
        $this->assertSame([$type->id], array_map('intval', array_column($rows, 'attribute_definition_id')));
        $this->assertSame('DDR4', $rows[0]['example']);
        $this->assertSame('1', $rows[0]['is_required']);
        $this->assertSame(['memory_type' => 3], collect(app(CategoryStandard::class)->coverage($this->ram)['filled'])
            ->only([$type->id])->mapWithKeys(fn (int $count, int $id): array => [AttributeDefinition::find($id)->key => $count])->all());
    }

    public function test_the_operators_table_is_pasted_into_the_form(): void
    {
        $capacity = $this->definition('ram');
        $capacity->update(['translations' => ['en' => 'Memory', 'ru' => 'Объём']]);
        $type = $this->definition('memory_type');
        $type->update(['translations' => ['ru' => 'Тип памяти']]);
        $this->ram->attributeDefinitions()->newPivotQuery()->update(['is_default' => false, 'is_required' => false]);
        $table = "1. Основные характеристики оперативной памяти\nЭто информация, которую покупатель должен видеть в первую очередь.\n"
            ."Параметр\tПример\tВажность\nПроизводитель\tSamsung\tОбязательно\nМодель / Part Number\tM393A2K40DB3-CWE\tОбязательно\n"
            ."Объём\t16 GB\tОбязательно\nТип памяти\tDDR4\tОбязательно\nТайминги\tCL22-22-22\tЖелательно\n"
            ."2. Дополнительные технические характеристики\nНапряжение\t1.2 V\tЖелательно";

        $page = Livewire::test(EditCategory::class, ['record' => $this->ram->id])
            ->callAction(TestAction::make('pasteStandardForm')->schemaComponent('standard_form'), data: ['table' => $table])
            ->assertHasNoActionErrors();

        $rows = array_values($page->get('data.standard_form'));
        $keys = AttributeDefinition::query()->whereIn('id', array_column($rows, 'attribute_definition_id'))->pluck('key', 'id');
        // Known names reuse their characteristic; unknown ones, with no answer from the keeper here, get a key of their own name.
        $this->assertSame(['ram', 'memory_type', 'taimingi', 'napriazenie'], array_map(fn (array $row): string => (string) ($keys[(int) $row['attribute_definition_id']] ?? '?'), $rows));
        $this->assertSame(['16 GB', 'DDR4', 'CL22-22-22', '1.2 V'], array_column($rows, 'example'));
        $this->assertSame(['1', '1', '0', '0'], array_column($rows, 'is_required'));
        $this->assertSame(['main', 'main', 'main', 'extra'], array_column($rows, 'section'));
        $this->assertDatabaseHas('attribute_definitions', ['label' => 'Тайминги']);
    }

    public function test_a_form_is_copied_from_another_category(): void
    {
        $ssd = Category::query()->where('slug', 'ssd')->firstOrFail();
        app(CategoryStandard::class)->syncForm($ssd, [['attribute_definition_id' => $this->definition('interface')->id, 'example' => 'PCIe 4.0 x4', 'is_required' => true]]);

        $page = Livewire::test(EditCategory::class, ['record' => $this->ram->id])
            ->callAction(TestAction::make('copyStandardForm')->schemaComponent('standard_form'), data: ['category_id' => $ssd->id])
            ->assertHasNoActionErrors();

        $this->assertSame(['PCIe 4.0 x4'], array_column(array_values($page->get('data.standard_form')), 'example'));
    }

    public function test_the_title_is_built_from_the_parts_and_an_empty_part_drops_with_its_marks(): void
    {
        $parts = [['part' => 'brand'], ['part' => 'mpn'], ['part' => 'ram'], ['part' => 'memory_type', 'after' => '-', 'glue' => true],
            ['part' => 'data_rate'], ['part' => 'module_type'], ['part' => 'condition', 'before' => '(', 'after' => ')']];
        $composer = app(TitleComposer::class);

        $this->assertSame('Samsung M471A4G43MB1-CTD 32 GB DDR4-2666 MT/s SO-DIMM', $composer->build($parts,
            ['brand' => 'Samsung', 'mpn' => 'M471A4G43MB1-CTD', 'ram' => '32 GB', 'memory_type' => 'DDR4', 'data_rate' => '2666 MT/s', 'module_type' => 'SO-DIMM']));
        $this->assertSame('Kingston KVR24N17S8/8 8 GB (Used)', $composer->build($parts,
            ['brand' => 'Kingston', 'mpn' => 'KVR24N17S8/8', 'ram' => '8 GB', 'condition' => 'Used']));
        $this->assertNull($composer->build($parts, ['ram' => '8 GB', 'memory_type' => 'DDR4']));
        $this->assertSame('{brand} {mpn} {ram} {memory_type}-{data_rate} {module_type} ({condition})', TitleComposer::template($parts));
    }

    public function test_the_category_renames_its_products_by_the_template_and_the_address_stays(): void
    {
        $this->ram->update(['title_parts' => [['part' => 'brand'], ['part' => 'mpn'], ['part' => 'ram']]]);
        $product = $this->product('Micron MTA8ATF1G64AZ-2G3E1 8GB DDR4-2400 UDIMM Memory Module (Refurbished)', 'MTA8ATF1G64AZ-2G3E1', 'Micron');
        $product->forceFill(['slug' => 'micron-old', 'translations' => ['cs' => ['title' => 'Starý název', 'description' => 'Popis']]])->save();
        $this->value($product, $this->definition('ram'), '8', 'GB');
        $other = $this->product('Kingston module', 'KVR24N17S8/8', 'Kingston');

        Livewire::test(EditCategory::class, ['record' => $this->ram->id])
            ->mountAction('renameByTemplate')
            ->setActionData(['products' => [$product->id]])
            ->callMountedAction()->assertHasNoActionErrors();

        $product->refresh();
        $this->assertSame('Micron MTA8ATF1G64AZ-2G3E1 8 GB', $product->title);
        $this->assertSame('micron-old', $product->slug);
        $this->assertArrayNotHasKey('title', $product->translations['cs']);
        $this->assertSame('Popis', $product->translations['cs']['description']);
        $this->assertSame('Kingston module', $other->fresh()->title);
    }

    public function test_a_value_its_configurations_differ_in_does_not_go_into_the_title(): void
    {
        $this->ram->update(['title_parts' => [['part' => 'brand'], ['part' => 'model'], ['part' => 'ram']]]);
        $product = $this->product('Laptop', null, 'Lenovo', 'T14');
        ProductVariant::query()->create(['product_id' => $product->id, 'fingerprint' => 'second', 'name' => 'Second', 'is_default' => false, 'is_active' => true]);
        foreach ($product->variants()->get() as $index => $variant) {
            AttributeValue::query()->create(['attribute_definition_id' => $this->definition('ram')->id, 'product_variant_id' => $variant->id,
                'value' => $index === 0 ? '16' : '32', 'unit' => 'GB']);
        }

        $this->assertSame('Lenovo T14', app(TitleComposer::class)->forProduct($product->fresh()));
    }

    public function test_the_product_form_builds_the_title_from_the_template(): void
    {
        $this->ram->update(['title_parts' => [['part' => 'brand'], ['part' => 'mpn']]]);
        $product = $this->product('Old name', 'M378A1K43CB2-CTD', 'Samsung');

        Livewire::test(EditProduct::class, ['record' => $product->id])
            ->callAction(TestAction::make('composeTitle')->schemaComponent('title'))
            ->assertSet('data.title', 'Samsung M378A1K43CB2-CTD');
    }

    private function definition(string $key, ?string $label = null): AttributeDefinition
    {
        return AttributeDefinition::query()->firstOrCreate(['key' => $key], ['label' => $label ?? ucfirst(str_replace('_', ' ', $key)), 'data_type' => 'text']);
    }

    private function product(string $title, ?string $mpn = null, ?string $brand = null, ?string $model = null): Product
    {
        $product = Product::query()->create(['title' => $title, 'model' => $model, 'category_id' => $this->ram->id, 'product_type' => 'component',
            'status' => 'published', 'is_active' => true, 'brand_id' => $brand === null ? null : Brand::query()->firstOrCreate(['name' => $brand], ['is_active' => true])->id]);
        ProductVariant::query()->create(['product_id' => $product->id, 'fingerprint' => md5($title), 'name' => 'Default', 'mpn' => $mpn,
            'is_default' => true, 'is_active' => true]);

        return $product;
    }

    private function value(Product $product, AttributeDefinition $definition, string $value, ?string $unit = null): void
    {
        AttributeValue::query()->create(['attribute_definition_id' => $definition->id, 'product_id' => $product->id, 'value' => $value, 'unit' => $unit]);
    }
}
