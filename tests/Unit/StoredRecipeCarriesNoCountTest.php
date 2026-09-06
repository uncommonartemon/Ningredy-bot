<?php

namespace Tests\Unit;

use App\Services\Products\ProductGalleryRecipeTrainer;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Every working recipe in the catalog carried the number of photographs the
 * product it was trained on happened to have - Dell 7, Lenovo 10, clicknet 12 -
 * and three separate places had quietly used one as a target. Each was fixed
 * where it was found. The number itself is the thing that keeps inviting it.
 *
 * A recipe is how to open and walk a gallery. The domain's copy no longer says
 * how big one was.
 */
class StoredRecipeCarriesNoCountTest extends TestCase
{
    public function test_the_count_does_not_travel_to_the_next_product(): void
    {
        $stored = $this->strip([
            'collect_selectors' => ['.gallery img'],
            'expected_image_count' => 7,
        ]);

        $this->assertArrayNotHasKey('expected_image_count', $stored);
    }

    public function test_the_traversal_bounds_survive(): void
    {
        // These are ceilings, not targets - and the zero is the agent saying
        // this gallery has no carousel, which the next product needs to know.
        $stored = $this->strip([
            'collect_selectors' => ['.gallery img'],
            'expected_image_count' => 7,
            'max_thumbnail_clicks' => 7,
            'max_next_clicks' => 0,
            'actions' => [['kind' => 'click_each', 'selector' => '.thumb', 'limit' => 7]],
        ]);

        $this->assertSame(7, $stored['max_thumbnail_clicks']);
        $this->assertSame(0, $stored['max_next_clicks']);
        $this->assertSame(7, $stored['actions'][0]['limit']);
    }

    public function test_a_recipe_that_never_had_one_is_unchanged(): void
    {
        $recipe = ['collect_selectors' => ['.gallery img'], 'attributes' => ['src']];

        $this->assertSame($recipe, $this->strip($recipe));
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @return array<string, mixed>
     */
    private function strip(array $candidate): array
    {
        $method = new ReflectionMethod(ProductGalleryRecipeTrainer::class, 'withoutTrainingCounts');

        return $method->invoke(app(ProductGalleryRecipeTrainer::class), $candidate);
    }
}
