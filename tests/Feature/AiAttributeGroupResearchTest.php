<?php

namespace Tests\Feature;

use App\Ai\Agents\ProductResearchAgent;
use App\Ai\Agents\ProductSpecificationReconciliationAgent;
use App\Ai\Tools\ResearchProduct;
use App\Models\AiRun;
use App\Models\AttributeDefinition;
use App\Models\AttributeGroup;
use App\Models\ProductDraft;
use App\Models\ProductSourcePageEvidence;
use App\Models\TelegramUpdate;
use App\Services\Products\ProductImageResolver;
use App\Services\Products\ProductImageStorage;
use App\Services\Products\ProductResearchResponseNormalizer;
use App\Services\Products\ProductSpecificationReconciler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Tools\Request;
use Tests\TestCase;

class AiAttributeGroupResearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_research_and_reconciliation_share_catalog_groups_only_on_main_specifications(): void
    {
        $group = AttributeGroup::create(['name' => 'Graphics', 'translations' => ['cs' => 'Grafika']]);
        AttributeDefinition::create(['key' => 'gpu_memory', 'label' => 'GPU memory', 'attribute_group_id' => $group->id]);
        AttributeDefinition::create(['key' => 'weight', 'label' => 'Weight']);

        foreach ([new ProductResearchAgent, new ProductSpecificationReconciliationAgent] as $agent) {
            $instructions = (string) $agent->instructions();
            $this->assertStringContainsString('Graphics', $instructions);
            $this->assertStringContainsString('gpu_memory', $instructions);
            $this->assertStringContainsString('"key":"weight","label":"Weight","attribute_group_id":null', $instructions);
            $schema = $agent->schema(new JsonSchemaTypeFactory);
            $properties = $schema['specifications']->toArray()['items']['properties'];
            $this->assertArrayHasKey('group', $properties);
            $this->assertFalse($properties['group']['additionalProperties']);
            if (isset($schema['translations'])) {
                $translated = $schema['translations']->toArray()['properties']['cs']['properties']['specifications']['items']['properties'];
                $this->assertArrayNotHasKey('group', $translated);
            }
        }
    }

    public function test_research_saves_normalized_group_proposals_without_creating_catalog_groups(): void
    {
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);
        $update = $this->update();
        $group = ['id' => null, 'name' => 'Graphics', 'name_cs' => 'Grafika'];
        $source = 'https://shop.example/exact-laptop';
        $specification = ['key' => 'gpu_memory', 'name' => 'GPU memory', 'value' => '8 GB',
            'source_url' => $source, 'source_excerpt' => 'GPU memory: 8 GB',
            'group' => [...$group, 'name' => '  Graphics  ', 'unexpected' => 'discard']];
        ProductResearchAgent::fake([[
            'status' => 'found', 'title' => 'Exact Laptop A123', 'brand' => 'Test', 'model' => 'A123',
            'product_type' => 'laptop', 'category' => 'laptops', 'color' => 'Black', 'condition' => 'new',
            'description' => 'Laptop with 8 GB of graphics memory.', 'research_notes' => null,
            'specifications' => [$specification], 'specification_evidence' => [],
            'sources' => [['title' => 'Exact Laptop', 'url' => $source, 'type' => 'retailer', 'image_urls' => []]],
            'primary_source_url' => $source, 'official_source_url' => null, 'image_urls' => [], 'confidence' => 0.95,
            'selected_identity' => $this->observedIdentity('A123', $source),
        ]])->preventStrayPrompts();
        $resolver = $this->mock(ProductImageResolver::class);
        $resolver->shouldNotReceive('resolve');
        $storage = $this->mock(ProductImageStorage::class);
        $storage->shouldReceive('stage')->once()->andReturnUsing(function (ProductDraft $draft) use ($group, $source): int {
            $this->assertSame($group, $draft->specifications[0]['group']);
            $this->assertSame($source, $draft->specifications[0]['source_url']);
            $this->assertSame(0, AttributeGroup::count());
            $draft->update(['specifications_reconciled_source_url' => $source]);

            return 3;
        });

        $result = json_decode((string) (new ResearchProduct($update, $resolver, imageStorage: $storage))
            ->handle(new Request(['query' => $update->text])), true);

        $this->assertSame('found', $result['status']);
        $this->assertSame($group, ProductDraft::firstOrFail()->specifications[0]['group']);
        $this->assertSame(0, AttributeGroup::count());
    }

    public function test_reconciliation_preserves_previous_proposals_accepts_new_groups_and_honors_explicit_null(): void
    {
        $update = $this->update();
        $run = AiRun::create(['telegram_update_id' => $update->id, 'provider' => 'fake', 'model' => 'fake',
            'status' => 'completed', 'prompt' => 'test', 'started_at' => now(), 'completed_at' => now()]);
        $graphics = AttributeGroup::create(['name' => 'Graphics']);
        $existing = ['id' => $graphics->id, 'name' => 'Graphics', 'name_cs' => null];
        $new = ['id' => null, 'name' => 'Dimensions', 'name_cs' => 'Rozměry'];
        $source = 'https://shop.example/exact-laptop';
        $citation = ['source_url' => $source, 'source_excerpt' => 'VRAM: 8 GB; Memory bus: 128 bit; Weight: 2 kg'];
        $specs = [
            ['key' => 'gpu_memory', 'name' => 'GPU memory', 'value' => '8 GB', ...$citation, 'group' => $existing],
            ['key' => 'gpu_bus', 'name' => 'Memory bus', 'value' => '128 bit', ...$citation, 'group' => $existing],
        ];
        $draft = ProductDraft::create(['telegram_update_id' => $update->id, 'ai_run_id' => $run->id,
            'requested_by_telegram_user_id' => '12345', 'title' => 'Exact Laptop', 'model' => 'Exact Laptop',
            'description' => 'Laptop.', 'specifications' => $specs, 'sources' => [['url' => $source]], 'image_urls' => []]);
        ProductSourcePageEvidence::create(['product_draft_id' => $draft->id, 'url_hash' => hash('sha256', $source),
            'url' => $source, 'specification_text' => $citation['source_excerpt'], 'captured_via' => 'http']);
        ProductSpecificationReconciliationAgent::fake(function (string $prompt) use ($existing, $new, $citation): array {
            $input = json_decode($prompt, true);
            $this->assertSame($existing, $input['current_specifications'][0]['group']);

            return ['overall_status' => 'reconciled', 'summary' => 'Verified.', 'title' => 'Exact Laptop',
                'model' => 'Exact Laptop', 'color' => null, 'description' => 'Laptop.', 'specifications' => [
                    ['key' => 'gpu_memory', 'name' => 'GPU memory', 'value' => '8 GB', 'evidence' => 'confirmed', ...$citation],
                    ['key' => 'gpu_bus', 'name' => 'Memory bus', 'value' => '128 bit', 'evidence' => 'confirmed', ...$citation, 'group' => null],
                    ['key' => 'weight', 'name' => 'Weight', 'value' => '2 kg', 'evidence' => 'confirmed', ...$citation, 'group' => $new],
                ]];
        })->preventStrayPrompts();

        $result = app(ProductSpecificationReconciler::class)->reconcile($draft, ['url' => $source], null);

        $this->assertTrue($result->isReconciled(), $result->summary ?? '');
        $this->assertSame($existing, $result->specifications[0]['group']);
        $this->assertNull($result->specifications[1]['group']);
        $this->assertSame($new, $result->specifications[2]['group']);
        $this->assertSame($citation['source_excerpt'], $result->specifications[2]['source_excerpt']);
        $this->assertSame($specs, $draft->fresh()->specifications);
        $this->assertSame(1, AttributeGroup::count());
    }

    public function test_legacy_research_and_invalid_group_metadata_do_not_drop_valid_facts(): void
    {
        $legacy = ['key' => 'legacy', 'name' => 'Legacy fact', 'value' => 'Known'];
        $data = app(ProductResearchResponseNormalizer::class)->normalize(['specifications' => [
            $legacy,
            ['key' => 'bad_group', 'name' => 'Other fact', 'value' => 'Known', 'group' => ['id' => 'wrong', 'name' => 'Graphics']],
        ]]);

        $this->assertSame($legacy, $data['specifications'][0]);
        $this->assertNull($data['specifications'][1]['group']);
        $this->assertSame('Known', $data['specifications'][1]['value']);
    }

    private function update(): TelegramUpdate
    {
        return TelegramUpdate::create(['update_id' => 923117, 'telegram_user_id' => '12345', 'chat_id' => '98765',
            'text' => 'Exact Laptop', 'payload' => [], 'status' => 'received']);
    }
}
