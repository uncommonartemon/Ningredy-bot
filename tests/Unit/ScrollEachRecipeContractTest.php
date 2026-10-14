<?php

namespace Tests\Unit;

use App\Ai\Agents\ProductGalleryRecipeTrainerAgent;
use App\Services\GalleryRecipes\RecipeContract;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Tests\TestCase;

class ScrollEachRecipeContractTest extends TestCase
{
    public function test_trainer_schema_and_php_contract_allow_scroll_each_with_frame_and_followup_fields(): void
    {
        $schema = (new ProductGalleryRecipeTrainerAgent)->schema(new JsonSchemaTypeFactory);
        $this->assertContains('scroll_each', $schema['actions']->toArray()['items']['properties']['kind']['enum']);

        $action = [
            'kind' => 'scroll_each', 'selector' => '.product-media .frame',
            'index' => 0, 'limit' => 4, 'wait_after_ms' => 150, 'when' => 'always',
            'iteration_mode' => 'elements', 'frame_selectors' => ['iframe.viewer'],
            'after_each_selector' => 'button.enlarge', 'after_each_limit' => 2,
            'after_each_wait_after_ms' => 200, 'purpose' => 'Reveal each lazy media frame',
        ];
        $recipe = [
            'gallery_present' => true, 'content_confirmed_product' => true,
            'expected_image_count' => 4, 'expected_count_evidence' => 'Current page has four frames',
            'actions' => [$action], 'pre_click_selectors' => [],
            'collect_selectors' => ['.product-media img'], 'thumbnail_selectors' => [],
            'open_selectors' => [], 'next_selectors' => [], 'exclude_selectors' => [],
            'attributes' => ['src'], 'confidence' => 0.9, 'reason' => 'Observed lazy gallery',
        ];

        $validated = app(RecipeContract::class)->validateRecipe($recipe);
        $this->assertEquals($action, $validated['actions'][0]);

        $recipe['actions'][0]['kind'] = 'scroll_into_view';
        $validated = app(RecipeContract::class)->validateRecipe($recipe);
        $this->assertSame('scroll_into_view', $validated['actions'][0]['kind']);
        $this->assertSame('elements', $validated['actions'][0]['iteration_mode']);
        $this->assertSame(4, $validated['actions'][0]['limit']);
    }
}
