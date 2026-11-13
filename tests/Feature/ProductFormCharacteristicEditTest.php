<?php

namespace Tests\Feature;

use App\Filament\Resources\Products\Pages\CreateProduct;
use App\Models\AttributeDefinition;
use App\Models\AttributeGroup;
use App\Models\Category;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Decision of 2026-11-13: a characteristic's group is changed right from the product form. The
 * pencil beside the characteristic opens the characteristic itself; its group shows in the row.
 */
class ProductFormCharacteristicEditTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.admin.name' => 'ningredy']);
        $this->actingAs(User::factory()->create(['name' => 'ningredy', 'is_admin' => true]));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::bootCurrentPanel();
    }

    public function test_the_pencil_opens_the_characteristic_and_its_group_changes_for_every_product(): void
    {
        $graphics = AttributeGroup::query()->create(['name' => 'Fixture graphics family']);
        $page = Livewire::test(CreateProduct::class)->set('data.category_id', Category::query()->where('slug', 'laptops')->value('id'));
        $variantKey = array_key_first($page->get('data.variants'));
        $rows = $page->get("data.variants.$variantKey.attributes");
        $rowKey = array_key_first($rows);
        $definition = AttributeDefinition::query()->findOrFail($rows[$rowKey]['attribute_definition_id']);
        $label = $definition->label;
        $pencil = TestAction::make('editOption')->schemaComponent("variants.$variantKey.attributes.$rowKey.attribute_definition_id");

        $page->mountAction($pencil)->assertSchemaStateSet(['label' => $label, 'attribute_group_id' => null])
            ->setActionData(['attribute_group_id' => $graphics->id, 'aliases' => ['graphics chip']])
            ->callMountedAction()->assertHasNoActionErrors();

        $definition->refresh();
        $this->assertSame($graphics->id, $definition->attribute_group_id);
        $this->assertSame($label, $definition->label);
        $this->assertSame(['graphics chip'], $definition->aliases);
        // The row keeps its characteristic; nothing of the product was saved by the pencil.
        $this->assertSame($definition->id, (int) $page->get("data.variants.$variantKey.attributes.$rowKey.attribute_definition_id"));
        $this->assertDatabaseCount('products', 0);
    }

    public function test_another_characteristics_name_is_refused_as_another_name(): void
    {
        $page = Livewire::test(CreateProduct::class)->set('data.category_id', Category::query()->where('slug', 'laptops')->value('id'));
        $variantKey = array_key_first($page->get('data.variants'));
        $rowKey = array_key_first($page->get("data.variants.$variantKey.attributes"));
        $definition = AttributeDefinition::query()->findOrFail($page->get("data.variants.$variantKey.attributes.$rowKey.attribute_definition_id"));

        $page->callAction(TestAction::make('editOption')->schemaComponent("variants.$variantKey.attributes.$rowKey.attribute_definition_id"),
            data: ['aliases' => [$definition->key === 'ram' ? 'storage' : 'ram']])
            ->assertHasActionErrors(['aliases']);
        $this->assertNull($definition->fresh()->aliases);
    }
}
