<?php

namespace Tests\Feature;

use App\Jobs\StoreProductImages;
use App\Models\AiRun;
use App\Models\AttributeDefinition;
use App\Models\AttributeGroup;
use App\Models\Product;
use App\Models\ProductDraft;
use App\Models\ProductSource;
use App\Models\TelegramUpdate;
use App\Services\Catalog\AttributePresentation;
use App\Services\Products\ProductDraftWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AiAttributeGroupsTest extends TestCase
{
    use RefreshDatabase;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Queue::fake([StoreProductImages::class]);
    }

    public function test_a_new_attribute_receives_its_proposed_group_only_when_the_draft_is_approved(): void
    {
        $draft = $this->draft([
            $this->specification('gpu_bus_width', ['id' => null, 'name' => '  Graphics  ', 'name_cs' => '  Grafika  ']),
        ]);
        $original = $draft->getRawOriginal('specifications');

        $preview = AttributePresentation::specifications($draft->specifications)->sole();
        $this->assertSame('Graphics', $preview['group']['name']);
        $this->assertSame('Grafika', $preview['group']['translations']['cs']);
        $this->assertDatabaseCount('attribute_groups', 0);
        $this->assertDatabaseMissing('attribute_definitions', ['key' => 'gpu_bus_width']);

        $product = app(ProductDraftWorkflow::class)->approve($draft);

        $group = AttributeGroup::query()->sole();
        $definition = AttributeDefinition::where('key', 'gpu_bus_width')->sole();
        $this->assertSame('Graphics', $group->name);
        $this->assertSame(['cs' => 'Grafika'], $group->translations);
        $this->assertTrue($group->highlight_in_filters);
        $this->assertSame(0, $group->sort_order);
        $this->assertSame($group->id, $definition->attribute_group_id);
        $this->assertFalse($definition->is_variant);
        $catalog = $this->get('/catalog')->assertOk()->viewData('page')['props'];
        $this->assertContains('gpu_bus_width', array_column($catalog['facets']['attributes'], 'key'));
        $this->assertDatabaseHas('attribute_values', ['product_id' => $product->id,
            'attribute_definition_id' => $definition->id, 'value' => '128 bit']);
        $this->assertSame('approved', $draft->fresh()->status);
        $this->assertSame($original, $draft->fresh()->getRawOriginal('specifications'));
    }

    public function test_approval_reuses_existing_ids_and_normalized_names_without_editing_admin_group_settings(): void
    {
        $group = AttributeGroup::create(['name' => 'Graphics details', 'translations' => ['cs' => 'Grafické detaily'],
            'sort_order' => 27, 'highlight_in_filters' => false]);
        $original = $group->fresh()->getAttributes();
        $draft = $this->draft([
            $this->specification('gpu_bus_width', ['id' => $group->id, 'name' => 'GPU', 'name_cs' => 'Replacement']),
            $this->specification('gpu_memory_type', ['id' => null, 'name' => "  GRAPHICS\t  details  ", 'name_cs' => null]),
        ]);

        app(ProductDraftWorkflow::class)->approve($draft);

        $this->assertDatabaseCount('attribute_groups', 1);
        $this->assertSame($original, $group->fresh()->getAttributes());
        foreach (['gpu_bus_width', 'gpu_memory_type'] as $key) {
            $this->assertDatabaseHas('attribute_definitions', ['key' => $key, 'attribute_group_id' => $group->id]);
        }
    }

    public function test_equivalent_new_group_proposals_share_one_group_in_preview_and_after_approval(): void
    {
        $draft = $this->draft([
            $this->specification('gpu_bus_width', ['id' => null, 'name' => 'Graphics details', 'name_cs' => 'Grafika']),
            $this->specification('gpu_memory_type', ['id' => null, 'name' => ' GRAPHICS  details ', 'name_cs' => null]),
        ]);
        $preview = AttributePresentation::specifications($draft->specifications)->keyBy('key');
        $this->assertNotNull($preview['gpu_bus_width']['group']['id']);
        $this->assertSame($preview['gpu_bus_width']['group']['id'], $preview['gpu_memory_type']['group']['id']);
        $this->assertSame($preview['gpu_bus_width']['group']['id'],
            AttributePresentation::specifications($draft->specifications)->keyBy('key')['gpu_bus_width']['group']['id']);
        $this->assertDatabaseCount('attribute_groups', 0);

        app(ProductDraftWorkflow::class)->approve($draft);

        $this->assertDatabaseCount('attribute_groups', 1);
        $group = AttributeGroup::query()->sole();
        $this->assertSame(2, AttributeDefinition::where('attribute_group_id', $group->id)->count());
    }

    public function test_existing_grouped_and_explicitly_ungrouped_definitions_keep_their_admin_assignments(): void
    {
        $manualGroup = AttributeGroup::create(['name' => 'Admin graphics']);
        $grouped = AttributeDefinition::create(['key' => 'gpu_bus_width', 'label' => 'GPU bus width',
            'attribute_group_id' => $manualGroup->id]);
        $ungrouped = AttributeDefinition::create(['key' => 'gpu_memory_type', 'label' => 'GPU memory type', 'is_filterable' => false]);
        $proposal = ['id' => null, 'name' => 'AI graphics', 'name_cs' => null];
        $draft = $this->draft([
            $this->specification($grouped->key, $proposal),
            $this->specification($ungrouped->key, $proposal),
        ]);

        $preview = AttributePresentation::specifications($draft->specifications)->keyBy('key');
        $this->assertSame($manualGroup->id, $preview[$grouped->key]['group']['id']);
        $this->assertNull($preview[$ungrouped->key]['group']);
        app(ProductDraftWorkflow::class)->approve($draft);

        $this->assertSame($manualGroup->id, $grouped->fresh()->attribute_group_id);
        $this->assertNull($ungrouped->fresh()->attribute_group_id);
        $this->assertFalse($ungrouped->fresh()->is_filterable);
        $this->assertDatabaseCount('attribute_groups', 1);
        $this->assertDatabaseMissing('attribute_groups', ['name' => 'AI graphics']);
    }

    public function test_malformed_or_deleted_group_references_do_not_block_publication_or_create_replacement_groups(): void
    {
        $deleted = AttributeGroup::create(['name' => 'Deleted graphics']);
        $deletedId = $deleted->id;
        $deleted->delete();
        $proposals = [
            'Graphics',
            [],
            ['id' => null, 'name' => '   ', 'name_cs' => null],
            ['id' => null, 'name' => ['Graphics'], 'name_cs' => null],
            ['id' => 0, 'name' => 'Invalid ID graphics', 'name_cs' => null],
            ['id' => $deletedId, 'name' => 'Deleted graphics', 'name_cs' => null],
            ['id' => 9999999, 'name' => 'Unknown ID graphics', 'name_cs' => null],
        ];
        $specifications = [];
        foreach ($proposals as $index => $proposal) {
            $specifications[] = $this->specification('malformed_group_'.$index, $proposal);
        }
        $draft = $this->draft($specifications);

        foreach (AttributePresentation::specifications($draft->specifications) as $specification) {
            $this->assertNull($specification['group']);
        }
        app(ProductDraftWorkflow::class)->approve($draft);

        $this->assertDatabaseCount('attribute_groups', 0);
        foreach ($specifications as $specification) {
            $definition = AttributeDefinition::where('key', $specification['key'])->sole();
            $this->assertNull($definition->attribute_group_id);
            $this->assertDatabaseHas('attribute_values', ['attribute_definition_id' => $definition->id, 'value' => '128 bit']);
        }
    }

    public function test_legacy_draft_without_group_metadata_is_still_publishable(): void
    {
        $specification = $this->specification('legacy_bus_width', null);
        unset($specification['group']);
        $draft = $this->draft([$specification]);

        $this->assertNull(AttributePresentation::specifications($draft->specifications)->sole()['group']);
        app(ProductDraftWorkflow::class)->approve($draft);

        $this->assertDatabaseCount('attribute_groups', 0);
        $this->assertDatabaseHas('attribute_definitions', ['key' => 'legacy_bus_width', 'attribute_group_id' => null]);
        $this->assertSame('approved', $draft->fresh()->status);
    }

    public function test_draft_preview_preserves_evidence_and_rejection_does_not_write_proposals_into_the_catalog(): void
    {
        $group = AttributeGroup::create(['name' => 'Graphics', 'translations' => ['cs' => 'Grafika']]);
        $specifications = [
            $this->specification('gpu_bus_width', ['id' => $group->id, 'name' => 'Alternate', 'name_cs' => null]),
            $this->specification('cooling_fans', ['id' => null, 'name' => 'Cooling', 'name_cs' => 'Chlazení']),
        ];
        $draft = $this->draft($specifications);
        $original = $draft->getRawOriginal('specifications');
        $definitionsBefore = AttributeDefinition::count();
        $preview = AttributePresentation::specifications($draft->specifications)->keyBy('key');

        $this->assertSame($group->id, $preview['gpu_bus_width']['group']['id']);
        $this->assertSame('Graphics', $preview['gpu_bus_width']['group']['name']);
        foreach ($specifications as $specification) {
            foreach (['key', 'name', 'value', 'source_url', 'source_excerpt'] as $field) {
                $this->assertSame($specification[$field], $preview[$specification['key']][$field]);
            }
        }
        app(ProductDraftWorkflow::class)->reject($draft, reason: 'Operator rejected the product.');

        $this->assertSame($original, $draft->fresh()->getRawOriginal('specifications'));
        $this->assertSame($definitionsBefore, AttributeDefinition::count());
        $this->assertDatabaseCount('attribute_groups', 1);
        $this->assertSame('rejected', $draft->fresh()->status);
    }

    public function test_a_failure_after_attribute_creation_rolls_back_the_group_and_definition_together(): void
    {
        $draft = $this->draft([
            $this->specification('gpu_bus_width', ['id' => null, 'name' => 'Rollback graphics', 'name_cs' => null]),
        ]);
        $productsBefore = Product::count();
        $event = 'eloquent.creating: '.ProductSource::class;
        Event::listen($event, function (): void {
            $this->assertDatabaseHas('attribute_groups', ['name' => 'Rollback graphics']);
            throw new \RuntimeException('Deliberate source persistence failure.');
        });

        try {
            app(ProductDraftWorkflow::class)->approve($draft);
            $this->fail('The controlled persistence failure must abort approval.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Deliberate source persistence failure.', $exception->getMessage());
        } finally {
            Event::forget($event);
        }

        $this->assertDatabaseCount('attribute_groups', 0);
        $this->assertDatabaseMissing('attribute_definitions', ['key' => 'gpu_bus_width']);
        $this->assertSame($productsBefore, Product::count());
        $this->assertSame('pending_review', $draft->fresh()->status);
        Queue::assertNotPushed(StoreProductImages::class);
    }

    private function specification(string $key, mixed $group): array
    {
        return ['key' => $key, 'name' => 'Graphics bus width', 'value' => '128 bit', 'group' => $group,
            'source_url' => 'https://source.example/product', 'source_excerpt' => 'Graphics bus width: 128 bit.'];
    }

    private function draft(array $specifications): ProductDraft
    {
        $number = ++$this->sequence;
        $update = TelegramUpdate::create(['update_id' => 770000 + $number,
            'telegram_user_id' => '42', 'chat_id' => '900', 'message_id' => $number,
            'text' => 'AI grouping fixture '.$number, 'payload' => [], 'status' => 'completed']);
        $run = AiRun::create(['telegram_update_id' => $update->id, 'provider' => 'openai',
            'model' => 'test-fixture', 'status' => 'completed', 'prompt' => 'AI grouping fixture',
            'started_at' => now(), 'completed_at' => now()]);
        $draft = ProductDraft::create([
            'telegram_update_id' => $update->id, 'ai_run_id' => $run->id,
            'requested_by_telegram_user_id' => '42', 'status' => 'pending_review',
            'title' => 'Fixture graphics '.$number, 'brand' => 'Fixture', 'model' => 'GPU-'.$number,
            'description' => 'Graphics component with a 128-bit memory bus.',
            'product_type' => 'component', 'category' => 'components', 'specifications' => $specifications,
            'sources' => [['title' => 'Fixture source', 'url' => 'https://source.example/product']],
            'image_urls' => [], 'confidence' => 0.95,
            'primary_source_url' => 'https://source.example/product',
            'specifications_reconciled_source_url' => 'https://source.example/product',
        ]);
        // Publication group tests begin after media verification; the queued
        // image-copy job is faked and does not read an actual image fixture.
        $draft->media()->create(['disk' => 'public', 'path' => 'drafts/'.$draft->id.'/verified.webp',
            'source_url' => 'https://source.example/product.webp', 'role' => 'primary',
            'mime_type' => 'image/webp', 'width' => 4096, 'height' => 4096, 'file_size' => 1,
            'checksum' => hash('sha256', 'fixture-'.$number), 'verification_status' => 'verified',
            'sort_order' => 0, 'is_primary' => true]);

        return $draft;
    }
}
