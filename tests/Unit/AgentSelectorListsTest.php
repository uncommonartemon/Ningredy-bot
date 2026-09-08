<?php

namespace Tests\Unit;

use App\Services\Products\ProductGalleryRecipeTrainer;
use ReflectionMethod;
use Tests\TestCase;

class AgentSelectorListsTest extends TestCase
{
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
        $result = (new ReflectionMethod(ProductGalleryRecipeTrainer::class, 'validateRecipe'))
            ->invoke(app(ProductGalleryRecipeTrainer::class), $recipe);
        $this->assertSame($selectors, $result['exclude_selectors']);
    }

    public function test_selector_lists_have_no_arbitrary_count_cap_but_entries_remain_validated(): void
    {
        $rules = (new ReflectionMethod(ProductGalleryRecipeTrainer::class, 'recipeValidationRules'))
            ->invoke(app(ProductGalleryRecipeTrainer::class));
        foreach (['pre_click_selectors', 'collect_selectors', 'thumbnail_selectors', 'open_selectors', 'next_selectors', 'exclude_selectors'] as $key) {
            $this->assertSame(['present', 'array'], $rules[$key]);
            $this->assertContains('string', $rules[$key.'.*']);
        }
    }
}
