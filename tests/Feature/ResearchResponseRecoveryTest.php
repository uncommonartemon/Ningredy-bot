<?php

namespace Tests\Feature;

use App\Ai\Tools\ResearchProduct;
use App\Exceptions\ProductSearchCancelled;
use App\Models\AiRun;
use App\Models\AppSetting;
use App\Models\TelegramUpdate;
use App\Services\Ai\ProductSearchCostBudget;
use App\Services\Ai\ProductSearchTimeBudget;
use App\Services\Ai\StreamingProductResearch;
use App\Services\Products\ProductImageResolver;
use App\Services\ProductSearch\Research\InvalidResearchResponse;
use App\Services\ProductSearch\Research\RecoverableProductResearch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Laravel\Ai\Tools\Request;
use Tests\TestCase;

class ResearchResponseRecoveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ai.providers.openai.key' => 'test-key', 'services.telegram.bot_token' => 'test-token']);
        AppSetting::put('ai.specifications_reconciliation_enabled', '1');
        Http::preventStrayRequests();
    }

    public function test_agent_repairs_invalid_json_with_retained_evidence_and_both_paid_runs_are_recorded(): void
    {
        $partial = '{"status":"found","source":"https://example.com/e14",';
        $this->responses([$partial, '{"status":"not_found"}']);
        $run = $this->runRecord();
        $result = $this->execute($run);
        $this->assertSame('not_found', $result['data']['status']);
        $runs = AiRun::orderBy('id')->get();
        $this->assertCount(2, $runs);
        $this->assertSame($partial, $runs[0]->response['recovery_snapshot']['raw_text']);
        $this->assertSame(120, $runs[0]->usage['prompt_tokens']);
        $this->assertSame(120, $runs[1]->usage['prompt_tokens']);
        Http::assertSent(fn ($r) => str_contains(json_encode($r->data()), 'repair_structured_result')
            && str_contains(json_encode($r->data()), 'example.com')
            && str_contains(json_encode($r->data()), 'ThinkPad E14'));
        $this->assertDatabaseHas('product_source_attempts', ['phase' => 'research_recovery', 'decision' => 'agent_repair']);
    }

    public function test_schema_errors_are_returned_to_agent_without_changing_validation_rules(): void
    {
        $this->responses(['{"status":"wrong"}', '{"status":"not_found"}']);
        $run = $this->runRecord();
        $result = $this->execute($run);
        $this->assertSame('not_found', $result['data']['status']);
        $first = AiRun::oldest('id')->first();
        $this->assertArrayHasKey('status', $first->response['recovery_snapshot']['validation_errors']);
        $this->assertSame('invalid_fields', $first->response['failure_kind']);
    }

    public function test_identical_failure_stops_without_restarting_whole_search(): void
    {
        $this->responses(['broken', 'broken']);
        $run = $this->runRecord();
        try {
            $this->execute($run);
            $this->fail('Repeated unchanged failure must stop.');
        } catch (InvalidResearchResponse $error) {
            $this->assertSame('repeated_failure', $error->reason);
            Http::assertSentCount(2);
            $this->assertDatabaseCount('ai_runs', 2);
            $this->assertDatabaseCount('product_drafts', 0);
        }
    }

    public function test_cost_budget_prevents_repair_but_preserves_already_paid_usage(): void
    {
        $this->responses(['broken']);
        $this->mock(ProductSearchCostBudget::class)->shouldReceive('exceeded')->once()->andReturn(true);
        $run = $this->runRecord();
        try {
            $this->execute($run);
            $this->fail('No extra call after budget exhaustion.');
        } catch (InvalidResearchResponse $error) {
            $this->assertSame('cost_budget', $error->reason);
            $this->assertSame(120, $run->fresh()->usage['prompt_tokens']);
            Http::assertSentCount(1);
        }
    }

    public function test_time_budget_prevents_repair_without_resetting_clock(): void
    {
        $this->responses(['broken']);
        $time = $this->mock(ProductSearchTimeBudget::class);
        $time->shouldReceive('timeoutFor')->once()->andReturn(40);
        $time->shouldReceive('canStart')->once()->andReturn(false);
        $run = $this->runRecord();
        try {
            $this->execute($run);
            $this->fail('No extra call after deadline.');
        } catch (InvalidResearchResponse $error) {
            $this->assertSame('time_budget', $error->reason);
            Http::assertSentCount(1);
        }
    }

    public function test_cancel_during_response_keeps_usage_and_does_not_call_repair(): void
    {
        $run = $this->runRecord();
        Http::fake(['api.openai.com/*' => function () use ($run) {
            TelegramUpdate::find($run->telegram_update_id)->update(['cancel_requested_at' => now()]);

            return Http::response($this->sse('broken'), 200, ['Content-Type' => 'text/event-stream']);
        }]);
        try {
            $this->execute($run);
            $this->fail('Cancellation must propagate.');
        } catch (ProductSearchCancelled) {
            $this->assertSame(120, $run->fresh()->usage['prompt_tokens']);
            Http::assertSentCount(1);
        }
    }

    public function test_real_research_tool_recovers_before_returning_to_telegram_agent(): void
    {
        $valid = json_encode(['status' => 'not_found', 'title' => null, 'specifications' => [], 'sources' => [],
            'image_urls' => [], 'confidence' => 0, 'research_notes' => 'No matching product after inspection.']);
        $this->responses(['broken', $valid], telegram: true);
        $update = $this->update();
        $result = json_decode((string) (new ResearchProduct($update, app(ProductImageResolver::class)))->handle(new Request(['query' => $update->text])), true);
        $this->assertSame('not_found', $result['status']);
        $this->assertSame(['failed', 'completed'], AiRun::orderBy('id')->pluck('status')->all());
        $this->assertDatabaseCount('product_drafts', 0);
    }

    private function execute(AiRun &$run): array
    {
        return app(RecoverableProductResearch::class)->run($run, app(StreamingProductResearch::class),
            'Find ThinkPad E14 16 GB black', 'openai', 'gpt-5-mini', 900, 90,
            fn (array $r) => Validator::make($r, ['status' => 'required|in:found,not_found'])->validate(),
            fn ($event) => null, fn ($seconds) => null, fn () => null);
    }

    private function update(): TelegramUpdate
    {
        return TelegramUpdate::create(['update_id' => random_int(100000, 999999), 'chat_id' => '12345', 'telegram_user_id' => '12345',
            'text' => 'Find ThinkPad E14 16 GB black', 'status' => 'processing', 'payload' => []]);
    }

    private function runRecord(): AiRun
    {
        return AiRun::create(['telegram_update_id' => $this->update()->id, 'provider' => 'openai', 'model' => 'gpt-5-mini',
            'status' => 'running', 'prompt' => 'Find ThinkPad E14', 'started_at' => now()]);
    }

    private function responses(array $texts, bool $telegram = false): void
    {
        $sequence = Http::sequence();
        foreach ($texts as $text) {
            $sequence->push($this->sse($text), 200, ['Content-Type' => 'text/event-stream']);
        }
        Http::fake(['api.openai.com/*' => $sequence, ...($telegram ? ['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 987]])] : [])]);
    }

    private function sse(string $text): string
    {
        $events = [['type' => 'response.created', 'response' => ['id' => 'resp_test', 'model' => 'gpt-5-mini']],
            ['type' => 'response.output_text.delta', 'delta' => $text],
            ['type' => 'response.completed', 'response' => ['id' => 'resp_test', 'model' => 'gpt-5-mini', 'status' => 'completed',
                'output' => [['type' => 'message', 'status' => 'completed', 'content' => [['type' => 'output_text', 'text' => $text]]]],
                'usage' => ['input_tokens' => 120, 'output_tokens' => 30]]]];

        return implode('',array_map(fn ($e) => 'data: '.json_encode($e)."\n\n",$events));
    }
}
