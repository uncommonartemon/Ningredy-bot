<?php

namespace Tests\Feature;

use App\Ai\Agents\ProductResearchAgent;
use App\Services\Ai\ActivityAwareOpenAiGateway;
use App\Services\Ai\ProductResearchIdleTimeoutException;
use App\Services\Ai\StreamingProductResearch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Gateway\StepResponse;
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

    public function test_it_keeps_structured_output_while_exposing_web_search_activity(): void
    {
        $json = json_encode([
            'status' => 'not_found',
            'title' => null,
            'sources' => [],
        ], JSON_THROW_ON_ERROR);
        $gateway = Mockery::mock(ActivityAwareOpenAiGateway::class);
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
