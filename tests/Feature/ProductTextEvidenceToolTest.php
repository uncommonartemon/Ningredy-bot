<?php

namespace Tests\Feature;

use App\Ai\Agents\ProductTextEvidenceAgent;
use App\Ai\Tools\SearchProductTextEvidence;
use App\Models\AiRun;
use App\Models\AttributeDefinition;
use App\Models\AttributeGroup;
use App\Models\ProductDraft;
use App\Models\TelegramUpdate;
use App\Services\Ai\ProductSearchTimeBudget;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Tools\Request;
use Tests\TestCase;

class ProductTextEvidenceToolTest extends TestCase
{
    use RefreshDatabase;

    public function test_cancellation_during_research_does_not_write_facts_back(): void
    {
        $draft = $this->draft();
        ProductTextEvidenceAgent::fake(function () use ($draft): array {
            $draft->update(['status' => 'rejected']);

            return ['facts' => [['key' => 'usb_ports', 'name' => 'USB', 'value' => '3',
                'source_url' => 'https://maker.example/specs', 'source_excerpt' => 'Three ports', 'applicability' => 'Exact model']],
                'summary' => 'Found data'];
        })->preventStrayPrompts();
        $result = json_decode((string) (new SearchProductTextEvidence($draft->id, $draft->telegram_update_id))->handle(new Request(['question' => 'ports'])), true);
        $this->assertFalse($result['found']);
        $this->assertNull($draft->fresh()->specification_evidence);
        $this->assertDatabaseHas('ai_runs', ['status' => 'completed']);
    }

    private function draft(): ProductDraft
    {
        $update = TelegramUpdate::create(['update_id' => 881, 'payload' => [], 'text' => 'Laptop X open box']);
        $run = AiRun::create(['telegram_update_id' => $update->id, 'provider' => 'fake', 'model' => 'fake', 'prompt' => 'test', 'started_at' => now()]);

        return ProductDraft::create([
            'telegram_update_id' => $update->id, 'ai_run_id' => $run->id, 'requested_by_telegram_user_id' => '1',
            'title' => 'Laptop X', 'condition' => 'open_box', 'status' => 'pending_review',
            'image_urls' => ['https://shop.example/photo.jpg'], 'sources' => [], 'specifications' => [],
        ]);
    }

    public function test_research_persists_cited_facts_without_touching_photos(): void
    {
        $draft = $this->draft();
        $fact = ['key' => 'usb_ports', 'name' => 'USB', 'value' => '3', 'source_url' => 'https://maker.example/specs',
            'source_excerpt' => 'Three USB ports', 'applicability' => 'Laptop X exact model'];
        ProductTextEvidenceAgent::fake(function (string $prompt) use ($fact): array {
            $this->assertSame('open_box', json_decode($prompt, true)['selected_product']['condition']);

            return ['facts' => [$fact], 'summary' => 'Confirmed port count'];
        })->preventStrayPrompts();
        $tool = new SearchProductTextEvidence($draft->id, $draft->telegram_update_id);
        $result = json_decode((string) $tool->handle(new Request(['question' => 'USB port count?'])), true);
        $this->assertTrue($result['found'], $result['reason'] ?? '');
        $this->assertSame([$fact], $draft->fresh()->specification_evidence);
        $this->assertSame(['https://shop.example/photo.jpg'], $draft->fresh()->image_urls);
        $tool->handle(new Request(['question' => 'USB port count?']));
        $this->assertCount(1, $draft->fresh()->specification_evidence);
        $this->assertSame(2, AiRun::where('status', 'completed')->count());
    }

    public function test_cancelled_draft_or_no_time_left_in_the_job_never_starts_a_model(): void
    {
        // Spent money does not stop this card assembly tool; the job's clock does.
        $draft = $this->draft();
        ProductTextEvidenceAgent::fake()->preventStrayPrompts();
        $this->mock(ProductSearchTimeBudget::class)->shouldReceive('assemblyCanStart')->once()->andReturnFalse();
        $tool = new SearchProductTextEvidence($draft->id, $draft->telegram_update_id);
        $result = json_decode((string) $tool->handle(new Request(['question' => 'ports'])), true);
        $this->assertFalse($result['found']);
        $draft->update(['status' => 'rejected']);
        $result = json_decode((string) $tool->handle(new Request(['question' => 'ports'])), true);
        $this->assertFalse($result['found']);
        $this->assertDatabaseCount('ai_runs', 1);
    }

    public function test_group_proposals_survive_targeted_research_without_creating_catalog_records(): void
    {
        $draft = $this->draft();
        $groupsBefore = AttributeGroup::count();
        $definitionsBefore = AttributeDefinition::count();
        $group = ['id' => null, 'name' => 'Graphics', 'name_cs' => 'Grafika'];
        $fact = ['key' => 'gpu_memory_capacity', 'name' => 'GPU memory capacity', 'value' => '8 GB',
            'source_url' => 'https://maker.example/specs', 'source_excerpt' => 'Graphics memory: 8 GB',
            'scope' => 'exact_configuration', 'source_type' => 'manufacturer', 'applicability' => 'Exact selected configuration'];
        ProductTextEvidenceAgent::fake(fn () => ['facts' => [
            [...$fact, 'group' => [...$group, 'name' => ' Graphics ', 'unexpected' => 'discard']],
            [...$fact, 'key' => 'gpu_memory_bus', 'name' => 'GPU memory bus', 'value' => '128 bit',
                'source_excerpt' => 'Memory bus: 128 bit', 'group' => ['id' => 'invalid', 'name' => 'Graphics']],
        ], 'summary' => 'Confirmed graphics specifications'])->preventStrayPrompts();

        $result = json_decode((string) (new SearchProductTextEvidence($draft->id, $draft->telegram_update_id))
            ->handle(new Request(['question' => 'Graphics memory details?'])), true);

        $this->assertTrue($result['found'], $result['reason'] ?? '');
        $this->assertEquals([...$fact, 'group' => $group], $result['facts'][0]);
        $this->assertNull($result['facts'][1]['group']);
        $this->assertSame('128 bit', $result['facts'][1]['value']);
        $this->assertSame($result['facts'], $draft->fresh()->specification_evidence);
        $this->assertSame([], $draft->fresh()->specifications);
        $this->assertSame($groupsBefore, AttributeGroup::count());
        $this->assertSame($definitionsBefore, AttributeDefinition::count());
    }

    public function test_group_metadata_does_not_replace_required_source_evidence(): void
    {
        $draft = $this->draft();
        ProductTextEvidenceAgent::fake(fn () => ['facts' => [[
            'key' => 'gpu_memory_capacity', 'name' => 'GPU memory capacity', 'value' => '8 GB',
            'group' => ['id' => null, 'name' => 'Graphics', 'name_cs' => null],
            'source_url' => 'https://maker.example/specs', 'source_excerpt' => '',
            'scope' => 'exact_configuration', 'applicability' => 'Exact selected configuration',
        ]], 'summary' => 'Unsupported claim'])->preventStrayPrompts();

        $result = json_decode((string) (new SearchProductTextEvidence($draft->id, $draft->telegram_update_id))
            ->handle(new Request(['question' => 'Graphics memory?'])), true);

        $this->assertFalse($result['found']);
        $this->assertNull($draft->fresh()->specification_evidence);
        $this->assertDatabaseHas('ai_runs', ['status' => 'failed']);
    }
}
