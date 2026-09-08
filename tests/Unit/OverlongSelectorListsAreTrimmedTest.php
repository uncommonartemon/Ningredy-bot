<?php

namespace Tests\Unit;

use App\Services\Products\ProductGalleryRecipeTrainer;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Live on dell.com, 2026-09-08: a ninth exclude_selector threw away a finished
 * recipe and the paid round that produced it - a model call plus a full browser
 * run, watched happening. The page legitimately needs that many exclusions: two
 * colourways, video, 3D, AR, recommendations.
 *
 * The cap was eight, nothing in the code said why, and the agent's instructions
 * listed every numeric bound while never mentioning the list lengths at all. It
 * was punished for a rule nobody told it.
 */
class OverlongSelectorListsAreTrimmedTest extends TestCase
{
    public function test_an_overlong_exclusion_list_is_trimmed_rather_than_thrown_away(): void
    {
        $trimmed = $this->trim([
            'collect_selectors' => ['.gallery img'],
            'exclude_selectors' => $this->selectors(26),
        ]);

        $this->assertCount(20, $trimmed['exclude_selectors']);
        $this->assertSame('.junk-1', $trimmed['exclude_selectors'][0], 'The entries that matter come first.');
    }

    public function test_a_list_within_its_bound_is_untouched(): void
    {
        $recipe = ['collect_selectors' => ['.a', '.b'], 'attributes' => ['src', 'data-full-img']];

        $this->assertSame($recipe, $this->trim($recipe));
    }

    public function test_the_plan_itself_is_not_trimmed(): void
    {
        // actions is a sequence, and dropping its last step changes what runs
        // rather than costing a little precision. An overlong one is refused.
        $actions = collect(range(1, 15))
            ->map(fn (int $n): array => ['kind' => 'click', 'selector' => '.step-'.$n])
            ->all();

        $this->assertCount(15, $this->trim(['actions' => $actions])['actions']);
    }

    public function test_the_bounds_come_from_the_rules_rather_than_a_second_copy(): void
    {
        // A hand-kept duplicate is what drifts; this is the same list the
        // validator enforces.
        $rules = (new ReflectionMethod(ProductGalleryRecipeTrainer::class, 'recipeValidationRules'))
            ->invoke(app(ProductGalleryRecipeTrainer::class));

        $this->assertContains('max:20', $rules['exclude_selectors']);
        $this->assertCount(20, $this->trim(['exclude_selectors' => $this->selectors(40)])['exclude_selectors']);
    }

    /** @return array<int, string> */
    private function selectors(int $count): array
    {
        return collect(range(1, $count))->map(fn (int $n): string => '.junk-'.$n)->all();
    }

    /**
     * @param  array<string, mixed>  $recipe
     * @return array<string, mixed>
     */
    private function trim(array $recipe): array
    {
        $method = new ReflectionMethod(ProductGalleryRecipeTrainer::class, 'trimOverlongSelectorLists');

        return $method->invoke(app(ProductGalleryRecipeTrainer::class), $recipe);
    }
}
