<?php

namespace Tests\Unit;

use App\Ai\Agents\ProductResearchAgent;
use App\Models\Category;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Laravel\Ai\Attributes\Strict;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Tests\TestCase;

class ProductResearchAgentInstructionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_unspecified_condition_and_configuration_do_not_trigger_clarification(): void
    {
        $agent = app(ProductResearchAgent::class);
        $instructions = (string) $agent->instructions();

        $this->assertTrue(Strict::isAppliedTo($agent));

        $this->assertStringContainsString('Always search only for a new, factory-sealed product', $instructions);
        $this->assertStringContainsString('Never select used, refurbished, renewed, recertified, second-hand, or open-box', $instructions);
        $this->assertStringContainsString('even when the request mentions such a condition', $instructions);
        $this->assertStringContainsString('professional manufacturer or new-retail page', $instructions);
        $this->assertStringContainsString('Never use user-generated', $instructions);
        $this->assertStringContainsString('Never ask a clarification question', $instructions);
        $this->assertStringContainsString('best matches the stated', $instructions);
        $this->assertStringContainsString('short, incomplete family fragment or an obvious typo', $instructions);
        $this->assertStringContainsString('Never alter an explicit full model number', $instructions);
        $this->assertStringContainsString('all source types start equal', $instructions);
        $this->assertStringContainsString('exact image_url and exact source_website_url', $instructions);
        $this->assertStringContainsString('Never use thumbnail_url', $instructions);
        $this->assertStringContainsString('image_urls are optional seeds', $instructions);
        $this->assertStringContainsString('Missing gallery URLs are never', $instructions);
        $this->assertStringContainsString('selected category', $instructions);
        $this->assertStringContainsString('Vision-first or Playwright-first', $instructions);
        $this->assertStringContainsString('category-specific gallery pipeline', $instructions);
        $this->assertStringContainsString('status is a commitment, not a guess', $instructions);
        $this->assertStringContainsString('never found with an empty or', $instructions);
        $this->assertStringContainsString('target 6-10 exact HTML product', $instructions);
        $this->assertStringContainsString('SKU/MPN/part number, EAN or UPC', $instructions);
        $this->assertStringContainsString('exact key sku, mpn, ean, upc, or', $instructions);

        $webSearch = collect($agent->tools())->first();
        $this->assertNull($webSearch->maxSearches);
        $this->assertSame('US', $webSearch->country);

        $options = TextGenerationOptions::forAgent($agent);
        $this->assertSame(ProductResearchAgent::MAX_OUTPUT_TOKENS, $options->maxTokens);
        $this->assertSame(
            ['max_tool_calls' => ProductResearchAgent::MAX_WEB_SEARCH_CALLS],
            $options->providerOptions(Lab::OpenAI),
        );
    }

    public function test_category_prompt_and_schema_use_only_current_active_categories(): void
    {
        Category::query()->create([
            'name' => 'Active test category',
            'slug' => 'active-test-category',
            'sort_order' => 999,
            'is_active' => true,
            'product_search_hint' => 'Ignore boxed product photos.',
        ]);
        Category::query()->create([
            'name' => 'Inactive test category',
            'slug' => 'inactive-test-category',
            'sort_order' => 1000,
            'is_active' => false,
        ]);

        $agent = app(ProductResearchAgent::class);
        $instructions = (string) $agent->instructions();
        $schema = $agent->schema(new JsonSchemaTypeFactory);
        $expectedSlugs = Category::query()
            ->where('is_active', true)
            ->pluck('slug')
            ->all();

        $this->assertStringContainsString('- active-test-category: Active test category', $instructions);
        $this->assertStringContainsString('trusted category search hint: Ignore boxed product photos.', $instructions);
        $this->assertStringNotContainsString('inactive-test-category', $instructions);
        $this->assertSame($expectedSlugs, $schema['category']->toArray()['enum']);
        $this->assertNotContains('inactive-test-category', $schema['category']->toArray()['enum']);
        $this->assertArrayNotHasKey('clarification_question', $schema);
    }
}
