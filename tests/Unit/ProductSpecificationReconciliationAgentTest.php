<?php

namespace Tests\Unit;

use App\Ai\Agents\ProductSpecificationReconciliationAgent;
use App\Ai\Tools\FetchProductSourcePageText;
use Tests\TestCase;

class ProductSpecificationReconciliationAgentTest extends TestCase
{
    public function test_it_offers_no_tools_when_constructed_without_a_source_host(): void
    {
        $agent = new ProductSpecificationReconciliationAgent;

        $this->assertSame([], iterator_to_array($agent->tools()));
    }

    public function test_it_offers_the_fetch_tool_scoped_to_the_given_source_host_only(): void
    {
        $agent = new ProductSpecificationReconciliationAgent('shop.example');

        $tools = iterator_to_array($agent->tools());

        $this->assertCount(1, $tools);
        $this->assertInstanceOf(FetchProductSourcePageText::class, $tools[0]);
    }

    public function test_it_threads_the_telegram_update_id_into_the_fetch_tool(): void
    {
        // The tool's own fetch can launch a real browser session, which is
        // not free - it must answer to the same shared search budget as the
        // reconciliation call that spawned it, not run unmetered.
        $agent = new ProductSpecificationReconciliationAgent('shop.example', 4242);

        $tools = iterator_to_array($agent->tools());
        $property = new \ReflectionProperty(FetchProductSourcePageText::class, 'telegramUpdateId');
        $property->setAccessible(true);

        $this->assertSame(4242, $property->getValue($tools[0]));
    }
}
