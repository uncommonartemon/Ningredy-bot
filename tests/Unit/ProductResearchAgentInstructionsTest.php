<?php

namespace Tests\Unit;

use App\Ai\Agents\ProductResearchAgent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductResearchAgentInstructionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_unspecified_condition_and_configuration_do_not_trigger_clarification(): void
    {
        $instructions = (string) app(ProductResearchAgent::class)->instructions();

        $this->assertStringContainsString('Always search only for a new, factory-sealed product', $instructions);
        $this->assertStringContainsString('Never select used, refurbished, renewed, recertified, second-hand, or open-box', $instructions);
        $this->assertStringContainsString('even when the request mentions such a condition', $instructions);
        $this->assertStringContainsString('professional manufacturer or new-retail page', $instructions);
        $this->assertStringContainsString('Never use user-generated', $instructions);
        $this->assertStringContainsString('omitted, do not', $instructions);
        $this->assertStringContainsString('all source types start equal', $instructions);
        $this->assertStringContainsString('exact image_url and exact source_website_url', $instructions);
        $this->assertStringContainsString('Never use thumbnail_url', $instructions);

        $webSearch = collect(app(ProductResearchAgent::class)->tools())->first();
        $this->assertSame(4, $webSearch->maxSearches);
    }
}
