<?php

namespace Tests\Feature;

use App\Filament\Resources\ProductDrafts\Pages\EditProductDraft;
use App\Filament\Resources\ProductDrafts\Pages\ViewProductDraft;
use App\Models\AiRun;
use App\Models\AttributeDefinition;
use App\Models\AttributeGroup;
use App\Models\ProductDraft;
use App\Models\TelegramUpdate;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class AiAttributeGroupFilamentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Storage::fake('public');
        config(['services.admin.name' => 'group-admin']);
        $this->actingAs(User::factory()->create(['name' => 'group-admin', 'is_admin' => true]));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::bootCurrentPanel();
    }

    public function test_editing_a_note_keeps_attribute_keys_group_proposals_and_evidence_without_creating_groups(): void
    {
        $draft = $this->draft();
        $original = $draft->specifications;

        Livewire::test(EditProductDraft::class, ['record' => $draft->id])
            ->fillForm(['research_notes' => 'Reviewed by the operator.'])
            ->call('save')->assertHasNoFormErrors();

        $this->assertSame($original, $draft->fresh()->specifications);
        $this->assertDatabaseCount('attribute_groups', 0);
        $this->assertDatabaseMissing('attribute_definitions', ['key' => 'gpu_bus_width']);
    }

    public function test_group_preview_shows_ai_proposals_but_existing_definitions_remain_authoritative(): void
    {
        $draft = $this->draft();
        $page = Livewire::test(EditProductDraft::class, ['record' => $draft->id])
            ->assertSee('Graphics details: Graphics bus width')
            ->assertSee('Cooling details: Cooling fans');
        $this->assertDatabaseCount('attribute_groups', 0);
        $manual = AttributeGroup::create(['name' => 'Admin graphics']);
        AttributeDefinition::create(['key' => 'gpu_bus_width', 'label' => 'Bus width', 'attribute_group_id' => $manual->id]);
        AttributeDefinition::create(['key' => 'cooling_fans', 'label' => 'Fan count']);

        $page->call('$refresh')
            ->assertSee('Admin graphics: Graphics bus width')
            ->assertDontSee('Graphics details: Graphics bus width')
            ->assertDontSee('Cooling details: Cooling fans');
        $this->assertDatabaseCount('attribute_groups', 1);
    }

    public function test_editing_a_value_keeps_metadata_and_revokes_the_previous_source_confirmation(): void
    {
        $draft = $this->draft();
        $original = $draft->specifications;
        $page = Livewire::test(EditProductDraft::class, ['record' => $draft->id]);
        $rows = $page->get('data.specifications');
        $firstKey = array_key_first($rows);
        $page->set('data.specifications.'.$firstKey.'.value', '256 bit')->call('save')->assertHasNoFormErrors();

        $expected = $original;
        $expected[0]['value'] = '256 bit';
        $this->assertSame($expected, $draft->fresh()->specifications);
        $this->assertNull($draft->fresh()->specifications_reconciled_source_url);
        $this->assertDatabaseCount('attribute_groups', 0);
    }

    public function test_review_page_groups_specifications_using_proposals_without_creating_catalog_rows(): void
    {
        $draft = $this->draft();
        $original = $draft->specifications;
        Livewire::test(ViewProductDraft::class, ['record' => $draft->id])
            ->assertSee('Graphics details')->assertSee('Graphics bus width')->assertSee('128 bit')
            ->assertSee('Cooling details')->assertSee('Cooling fans');
        $this->assertSame($original, $draft->fresh()->specifications);
        $this->assertDatabaseCount('attribute_groups', 0);

        $manual = AttributeGroup::create(['name' => 'Admin graphics']);
        AttributeDefinition::create(['key' => 'gpu_bus_width', 'label' => 'Bus width', 'attribute_group_id' => $manual->id]);
        AttributeDefinition::create(['key' => 'cooling_fans', 'label' => 'Fan count']);
        Livewire::test(ViewProductDraft::class, ['record' => $draft->id])
            ->assertSee('Admin graphics')->assertDontSee('Graphics details')->assertDontSee('Cooling details')
            ->assertSee('Graphics bus width')->assertSee('Cooling fans');
        $this->assertDatabaseCount('attribute_groups', 1);
    }

    public function test_reordering_and_removing_repeater_rows_keeps_the_remaining_group_with_its_attribute(): void
    {
        $draft = $this->draft();
        $original = $draft->specifications;
        $page = Livewire::test(EditProductDraft::class, ['record' => $draft->id]);
        $rows = array_reverse($page->get('data.specifications'), true);
        $page->set('data.specifications', $rows)->call('save')->assertHasNoFormErrors();
        $this->assertSame(array_reverse($original), $draft->fresh()->specifications);

        $rows = $page->get('data.specifications');
        unset($rows[array_key_last($rows)]);
        $page->set('data.specifications', $rows)->call('save')->assertHasNoFormErrors();
        $this->assertSame([$original[1]], $draft->fresh()->specifications);
        $this->assertDatabaseCount('attribute_groups', 0);
    }

    private function draft(): ProductDraft
    {
        $update = TelegramUpdate::create(['update_id' => 881001, 'telegram_user_id' => '42', 'chat_id' => '900',
            'message_id' => 1, 'text' => 'Group review fixture', 'payload' => [], 'status' => 'completed']);
        $run = AiRun::create(['telegram_update_id' => $update->id, 'provider' => 'openai',
            'model' => 'test-fixture', 'status' => 'completed', 'prompt' => 'Group review fixture',
            'started_at' => now(), 'completed_at' => now()]);

        return ProductDraft::create([
            'telegram_update_id' => $update->id, 'ai_run_id' => $run->id,
            'requested_by_telegram_user_id' => '42', 'status' => 'pending_review',
            'title' => 'Graphics fixture', 'brand' => 'Fixture', 'model' => 'GPU-1',
            'description' => 'Graphics with a 128-bit memory bus.', 'condition' => 'new',
            'product_type' => 'component', 'category' => 'components',
            'specifications' => [
                ['key' => 'gpu_bus_width', 'name' => 'Graphics bus width', 'value' => '128 bit',
                    'unit' => 'bit', 'group' => ['id' => null, 'name' => 'Graphics details', 'name_cs' => 'Grafické detaily'],
                    'source_url' => 'https://source.example/product', 'source_excerpt' => 'Graphics bus width: 128 bit.'],
                ['key' => 'cooling_fans', 'name' => 'Cooling fans', 'value' => '2',
                    'group' => ['id' => null, 'name' => 'Cooling details', 'name_cs' => null],
                    'source_url' => 'https://source.example/product', 'source_excerpt' => 'Two fans.'],
            ],
            'sources' => [], 'image_urls' => [], 'confidence' => 0.95,
            'primary_source_url' => 'https://source.example/product',
            'specifications_reconciled_source_url' => 'https://source.example/product',
        ]);
    }
}
