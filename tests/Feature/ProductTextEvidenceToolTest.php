<?php

namespace Tests\Feature;

use App\Ai\Agents\ProductTextEvidenceAgent;
use App\Ai\Tools\SearchProductTextEvidence;
use App\Models\AiRun;
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
}
