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

        $this->assertStringContainsString('search only for a new, factory-sealed', $instructions);
        $this->assertStringContainsString('Never select used, refurbished, renewed, recertified, or open-box', $instructions);
        $this->assertStringContainsString('When RAM, storage, color, or another configuration detail is omitted, do not ask', $instructions);
        $this->assertStringContainsString('return not_found instead of asking about refurbished', $instructions);
    }
}
