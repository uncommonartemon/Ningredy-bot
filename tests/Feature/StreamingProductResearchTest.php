<?php

namespace Tests\Feature;

use App\Ai\Agents\ProductResearchAgent;
use App\Services\Ai\ActivityAwareOpenAiGateway;
use App\Services\Ai\ProductResearchIdleTimeoutException;
use App\Services\Ai\StreamingProductResearch;
use App\Services\ProductSearch\Research\InvalidResearchResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Gateway\StepResponse;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Streaming\Events\ProviderToolEvent;
use Laravel\Ai\Streaming\Events\StreamStart;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class StreamingProductResearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_real_stream_parser_retains_invalid_output_and_usage_for_recovery(): void
    {
        $this->fakeStream('{"status":"found",', 'completed');
        try {
            $this->realPrompt();
            $this->fail('Invalid JSON must not become a result.');
        } catch (InvalidResearchResponse $error) {
            $this->assertSame('invalid_json', $error->reason);
            $this->assertSame('{"status":"found",', $error->evidence['raw_text']);
            $this->assertSame(120, $error->usage['prompt_tokens']);
            $this->assertSame('resp_research', $error->evidence['response_id']);
            $this->assertTrue($error->recoverable);
        }
    }

    public function test_real_stream_parser_rejects_valid_json_when_stream_never_completed(): void
    {
        $this->fakeStream('{"status":"not_found"}', null);
        try {
            $this->realPrompt();
            $this->fail('EOF alone does not prove completion.');
        } catch (InvalidResearchResponse $error) {
            $this->assertSame('stream_incomplete', $error->reason);
            $this->assertNull($error->usage);
            $this->assertSame('{"status":"not_found"}', $error->evidence['raw_text']);
        }
    }

    public function test_terminal_full_text_recovers_a_missing_delta_without_another_paid_call(): void
    {
        $this->fakeStream('{"status":', 'completed', '{"status":"not_found"}');
        $response = $this->realPrompt();
        $this->assertSame('not_found', $response->toArray()['status']);
        $this->assertSame(120, $response->usage->promptTokens);
        Http::assertSentCount(1);
    }

    public function test_incomplete_token_limited_response_preserves_reason_and_usage(): void
    {
        $this->fakeStream('{"status":', 'incomplete');
        try {
            $this->realPrompt();
            $this->fail('Incomplete output must go to recovery.');
        } catch (InvalidResearchResponse $error) {
            $this->assertSame('stream_incomplete', $error->reason);
            $this->assertSame('max_output_tokens', $error->evidence['incomplete_reason']);
            $this->assertSame(120, $error->usage['prompt_tokens']);
        }
    }

    public function test_provider_refusal_is_not_a_format_error_to_repair(): void
    {
        $this->fakeStream('', 'completed', null, true);
        try {
            $this->realPrompt();
            $this->fail('Refusal must remain explicit.');
        } catch (InvalidResearchResponse $error) {
            $this->assertSame('provider_refusal', $error->reason);
            $this->assertFalse($error->recoverable);
        }
    }

    private function fakeStream(string $delta, ?string $status, ?string $completeText = null, bool $refusal = false): void
    {
        config(['ai.providers.openai.key' => 'test-key']);
        Http::preventStrayRequests();
        $events = [
            ['type' => 'response.created', 'response' => ['id' => 'resp_research', 'model' => 'gpt-5-mini']],
            ['type' => 'response.output_text.delta', 'delta' => $delta],
        ];
        if ($status !== null) {
            $events[] = ['type' => 'response.'.$status, 'response' => [
                'id' => 'resp_research', 'model' => 'gpt-5-mini', 'status' => $status,
                'incomplete_details' => $status === 'incomplete' ? ['reason' => 'max_output_tokens'] : null,
                'usage' => ['input_tokens' => 120, 'output_tokens' => 25],
                'output' => [['type' => 'message', 'status' => $status, 'content' => [
                    $refusal ? ['type' => 'refusal', 'refusal' => 'Cannot comply.'] : ['type' => 'output_text', 'text' => $completeText ?? $delta],
                ]]],
            ]];
        }
        $sse = implode('', array_map(fn ($event) => 'data: '.json_encode($event)."\n\n", $events));
        Http::fake(['api.openai.com/*' => Http::response($sse, 200, ['Content-Type' => 'text/event-stream'])]);
    }

    private function realPrompt(): AgentResponse
    {
        return app(StreamingProductResearch::class)->prompt(app(ProductResearchAgent::class), 'ThinkPad E14', 'openai', 'gpt-5-mini', 900, 90);
    }

    public function test_commentary_and_final_json_are_not_concatenated_into_invalid_json(): void
    {
        config(['ai.providers.openai.key' => 'test-key']);
        Http::preventStrayRequests();
        $events = [['type' => 'response.created', 'response' => ['id' => 'resp_multi', 'model' => 'gpt-5-mini']],
            ['type' => 'response.output_text.delta', 'delta' => 'Looking up this product.'],
            ['type' => 'response.output_text.delta', 'delta' => '{"status":"not_found"}'],
            ['type' => 'response.completed', 'response' => ['id' => 'resp_multi', 'model' => 'gpt-5-mini', 'status' => 'completed',
                'output' => [
                    ['type' => 'message', 'status' => 'completed', 'content' => [['type' => 'output_text', 'text' => 'Looking up this product.']]],
                    ['type' => 'message', 'status' => 'completed', 'content' => [['type' => 'output_text', 'text' => '{"status":"not_found"}']]],
                ], 'usage' => ['input_tokens' => 120, 'output_tokens' => 25]]]];
        Http::fake(['api.openai.com/*' => Http::response(implode('', array_map(fn ($e) => 'data: '.json_encode($e)."\n\n", $events)), 200, ['Content-Type' => 'text/event-stream'])]);
        $this->assertSame('not_found', $this->realPrompt()->toArray()['status']);
        Http::assertSentCount(1);
    }

    public function test_it_keeps_structured_output_while_exposing_web_search_activity(): void
    {
        $json = json_encode([
            'status' => 'not_found',
            'title' => null,
            'sources' => [],
        ], JSON_THROW_ON_ERROR);
        $gateway = Mockery::mock(ActivityAwareOpenAiGateway::class);
        $gateway->shouldReceive('researchEvidence')->andReturn([]);
        $gateway->shouldReceive('generateStreamStep')
            ->once()
            ->andReturn((function () use ($json) {
                yield (new StreamStart('start', 'openai', 'gpt-5-mini', time()))
                    ->withInvocationId('invocation');
                yield (new ProviderToolEvent(
                    'search',
                    'item',
                    'web_search_call',
                    [],
                    'searching',
                    time(),
                ))->withInvocationId('invocation');

                return new StepResponse(
                    text: $json,
                    toolCalls: [],
                    finishReason: FinishReason::Stop,
                    usage: new Usage(12, 8),
                    meta: new Meta('openai', 'gpt-5-mini'),
                );
            })());
        $events = [];
        $service = new StreamingProductResearch(fn (int $idle): ActivityAwareOpenAiGateway => $gateway);

        $response = $service->prompt(
            app(ProductResearchAgent::class),
            'MSI Katana 17 HX B14WGK-059US',
            'openai',
            'gpt-5-mini',
            900,
            90,
            function ($event) use (&$events): void {
                $events[] = $event;
            },
        );

        $this->assertSame('not_found', $response->toArray()['status']);
        $this->assertSame(12, $response->usage->promptTokens);
        $this->assertCount(2, $events);
        $this->assertInstanceOf(ProviderToolEvent::class, $events[1]);
        $this->assertSame('searching', $events[1]->status);
    }

    public function test_it_turns_a_low_speed_transport_failure_into_an_idle_timeout(): void
    {
        $gateway = Mockery::mock(ActivityAwareOpenAiGateway::class);
        $gateway->shouldReceive('researchEvidence')->andReturn([]);
        $gateway->shouldReceive('generateStreamStep')
            ->once()
            ->andReturn((function () {
                yield (new StreamStart('start', 'openai', 'gpt-5-mini', time()))
                    ->withInvocationId('invocation');

                throw new RuntimeException('Operation too slow. Less than 1 bytes/sec transferred the last 90 seconds');
            })());
        $service = new StreamingProductResearch(fn (int $idle): ActivityAwareOpenAiGateway => $gateway);

        $this->expectException(ProductResearchIdleTimeoutException::class);
        $this->expectExceptionMessage('не присылал данных 90 сек.');

        $service->prompt(
            app(ProductResearchAgent::class),
            'MSI Katana 17 HX B14WGK-059US',
            'openai',
            'gpt-5-mini',
            900,
            90,
        );
    }
}
