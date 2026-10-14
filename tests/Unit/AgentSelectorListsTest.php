<?php

namespace Tests\Unit;

use App\Services\GalleryRecipes\RecipeContract;
use ReflectionMethod;
use Tests\TestCase;

class AgentSelectorListsTest extends TestCase
{
    public function test_long_observed_action_plan_is_preserved(): void
    {
        $actions = array_map(fn ($n) => [
            'kind' => 'click', 'selector' => '#media-step-'.$n, 'index' => 0,
            'limit' => 1, 'wait_after_ms' => 200, 'when' => 'always',
            'purpose' => 'Open the next observed product media layer',
        ], range(1, 24));
        $recipe = [
            'gallery_present' => true, 'content_confirmed_product' => true,
            'expected_image_count' => 3, 'expected_count_evidence' => 'Three gallery frames',
            'pre_click_selectors' => [], 'collect_selectors' => ['.gallery img'],
            'thumbnail_selectors' => [], 'open_selectors' => [], 'next_selectors' => [],
            'attributes' => ['src'], 'confidence' => 0.9, 'reason' => 'Observed media layers',
            'actions' => $actions,
        ];
        $result = app(RecipeContract::class)->validateRecipe($recipe);
        $this->assertSame(array_column($actions, 'selector'), array_column($result['actions'], 'selector'));
    }

    public function test_all_safe_exclusions_survive_recipe_validation(): void
    {
        $selectors = array_map(fn ($n) => '.unwanted-'.$n, range(1, 40));
        $recipe = [
            'gallery_present' => true, 'content_confirmed_product' => true,
            'expected_image_count' => 9, 'expected_count_evidence' => 'Nine frames in one gallery',
            'pre_click_selectors' => [], 'collect_selectors' => ['.gallery img'],
            'thumbnail_selectors' => [], 'open_selectors' => [], 'next_selectors' => [],
            'exclude_selectors' => $selectors, 'attributes' => ['src'],
            'confidence' => 0.9, 'reason' => 'Observed isolated gallery',
        ];
        $result = app(RecipeContract::class)->validateRecipe($recipe);
        $this->assertSame($selectors, $result['exclude_selectors']);
    }

    public function test_selector_lists_have_no_arbitrary_count_cap_but_entries_remain_validated(): void
    {
        $rules = (new ReflectionMethod(RecipeContract::class, 'recipeValidationRules'))
            ->invoke(app(RecipeContract::class));
        foreach (['pre_click_selectors', 'collect_selectors', 'thumbnail_selectors', 'open_selectors', 'next_selectors', 'exclude_selectors'] as $key) {
            $this->assertSame(['present', 'array'], $rules[$key]);
            $this->assertContains('string', $rules[$key.'.*']);
        }
    }
}
