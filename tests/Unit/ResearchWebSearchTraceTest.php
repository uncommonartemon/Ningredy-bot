<?php

namespace Tests\Unit;

use App\Services\ProductSearch\Research\ResearchWebSearchTrace;
use Laravel\Ai\Streaming\Events\ProviderToolEvent;
use PHPUnit\Framework\TestCase;

class ResearchWebSearchTraceTest extends TestCase
{
    public function test_trace_is_bounded_and_a_later_status_event_keeps_the_observed_action(): void
    {
        $items = [];
        for ($index = 0; $index < 51; $index++) {
            $items = ResearchWebSearchTrace::record($items, new ProviderToolEvent('event', 'ws_'.$index, 'web_search_call',
                ['action' => ['type' => 'search', 'queries' => array_fill(0, 21, str_repeat('x', 3000))]], 'searching', time()));
        }
        $items = ResearchWebSearchTrace::record($items, new ProviderToolEvent('event', 'ws_0', 'web_search_call', [], 'completed', time()));

        $this->assertCount(50, $items);
        $this->assertCount(20, $items['ws_0']['action']['queries']);
        $this->assertSame(2048, strlen($items['ws_0']['action']['queries'][0]));
        $this->assertSame('completed', $items['ws_0']['status']);
    }
}
