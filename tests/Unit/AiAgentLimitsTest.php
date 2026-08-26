<?php

namespace Tests\Unit;

use App\Ai\Agents\ProductGalleryPreflightAgent;
use App\Ai\Agents\ProductGalleryRecipeTrainerAgent;
use App\Ai\Agents\ProductImageDiscoveryAgent;
use App\Ai\Agents\ProductImageVisionAgent;
use App\Ai\Agents\ProductSourceIdentityAgent;
use App\Ai\Agents\ServerAssistantAgent;
use App\Models\TelegramUpdate;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Tests\TestCase;

class AiAgentLimitsTest extends TestCase
{
    public function test_every_heavy_agent_has_a_provider_enforced_output_limit(): void
    {
        $agents = [
            [new ServerAssistantAgent(new TelegramUpdate), ServerAssistantAgent::MAX_OUTPUT_TOKENS],
            [new ProductImageDiscoveryAgent, ProductImageDiscoveryAgent::MAX_OUTPUT_TOKENS],
            [new ProductImageVisionAgent, ProductImageVisionAgent::MAX_OUTPUT_TOKENS],
            [new ProductGalleryPreflightAgent, ProductGalleryPreflightAgent::MAX_OUTPUT_TOKENS],
            [new ProductGalleryRecipeTrainerAgent, ProductGalleryRecipeTrainerAgent::MAX_OUTPUT_TOKENS],
            [new ProductSourceIdentityAgent, ProductSourceIdentityAgent::MAX_OUTPUT_TOKENS],
        ];

        foreach ($agents as [$agent, $expected]) {
            $this->assertSame($expected, TextGenerationOptions::forAgent($agent)->maxTokens);
        }
    }

    public function test_image_discovery_sends_its_web_search_limit_to_openai(): void
    {
        $agent = new ProductImageDiscoveryAgent;
        $webSearch = collect($agent->tools())->first();
        $options = TextGenerationOptions::forAgent($agent);

        $this->assertNull($webSearch->maxSearches);
        $this->assertSame(
            ['max_tool_calls' => ProductImageDiscoveryAgent::MAX_WEB_SEARCH_CALLS],
            $options->providerOptions(Lab::OpenAI),
        );
    }
}
