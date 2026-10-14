<?php

namespace Tests\Unit;

use App\Ai\Agents\ProductResearchAgent;
use App\Ai\Agents\ProductTextEvidenceAgent;
use App\Services\Products\ProductResearchResponseNormalizer;
use App\Services\Products\ProductTextEvidenceContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class ProductTextEvidenceContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_research_and_targeted_search_use_the_same_observation_schema(): void
    {
        $schema = new JsonSchemaTypeFactory;
        $research = (new ProductResearchAgent)->schema($schema)['specification_evidence']->toArray();
        $targeted = (new ProductTextEvidenceAgent)->schema($schema)['facts']->toArray();
        $this->assertArrayNotHasKey('group', $research['items']['properties']);
        $this->assertArrayHasKey('group', $targeted['items']['properties']);
        unset($targeted['items']['properties']['group']);
        $targeted['items']['required'] = array_values(array_diff($targeted['items']['required'], ['group']));
        $this->assertEquals($research['items'], $targeted['items']);
        $observation = ProductTextEvidenceContract::observationSchema($schema)->toArray();
        $this->assertArrayHasKey('scope', $observation['properties']);
        $this->assertArrayHasKey('source_type', $observation['properties']);
        $this->assertStringContainsString('exact applicable official specifications', $observation['properties']['applicability']['description']);
    }

    public function test_normalization_and_validation_do_not_collapse_conflicting_values_into_one_key(): void
    {
        $one = ['key' => 'ram', 'name' => 'RAM', 'value' => 'up to 96 GB', 'source_url' => 'https://maker.example/family',
            'source_excerpt' => 'Up to 96 GB', 'scope' => 'product_family', 'source_type' => 'manufacturer', 'applicability' => 'Family capability, not installed memory.'];
        $two = [...$one, 'value' => '64 GB', 'source_url' => 'https://shop.example/exact',
            'source_excerpt' => 'Installed RAM: 64 GB', 'scope' => 'exact_configuration', 'source_type' => 'retailer', 'applicability' => 'Exact selected offer.'];
        $normalized = app(ProductResearchResponseNormalizer::class)->normalize(['specification_evidence' => [$one, $two]]);
        $data = Validator::make($normalized, ProductTextEvidenceContract::rules('specification_evidence'))->validate();
        $this->assertEquals([$one, $two], $data['specification_evidence']);
    }

    public function test_old_observations_do_not_get_an_invented_official_role_or_scope(): void
    {
        $old = ['key' => 'ram', 'name' => 'RAM', 'value' => '64 GB', 'source_url' => 'https://example.com/data.pdf',
            'source_excerpt' => 'RAM 64 GB', 'applicability' => 'Same product'];
        $data = Validator::make(['facts' => [$old]], ProductTextEvidenceContract::rules('facts'))->validate();
        $this->assertSame([$old], $data['facts']);
    }

    public function test_targeted_research_normalizes_only_group_metadata_and_keeps_citations_unchanged(): void
    {
        $fact = ['key' => 'gpu_memory_capacity', 'name' => 'GPU memory capacity', 'value' => '8 GB',
            'source_url' => 'https://maker.example/specs', 'source_excerpt' => 'Graphics memory: 8 GB',
            'scope' => 'exact_configuration', 'applicability' => 'Exact selected graphics card'];
        $normalized = ['facts' => ProductTextEvidenceContract::normalizeGroups([
            [...$fact, 'group' => ['id' => null, 'name' => ' Graphics ', 'name_cs' => ' Grafika ', 'extra' => true]],
            [...$fact, 'group' => false],
        ])];
        $data = Validator::make($normalized, ProductTextEvidenceContract::rules('facts', includeGroup: true))->validate();

        $this->assertEquals([...$fact, 'group' => ['id' => null, 'name' => 'Graphics', 'name_cs' => 'Grafika']], $data['facts'][0]);
        $this->assertEquals([...$fact, 'group' => null], $data['facts'][1]);
    }
}
