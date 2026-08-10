<?php

namespace Tests\Unit;

use App\Services\Products\BrowserProductGalleryExtractor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

class BrowserProductGalleryExtractorTest extends TestCase
{
    use RefreshDatabase;

    public function test_selector_mismatch_is_detected_when_the_recipes_own_selectors_matched_nothing(): void
    {
        $mismatched = $this->invoke([
            'collect_selectors' => ['[data-test-hook="@hpi-sox/hpstellar-pdp/gallerycarousel"] img'],
            'thumbnail_selectors' => ['button[data-test-hook^="@hpi-sox/hpstellar-pdp/gallerycarousel__thumbnail-"]'],
        ], [
            'learned_recipe' => [
                'collect_selectors' => [],
                'thumbnail_selectors' => [],
            ],
        ]);

        $this->assertTrue($mismatched);
    }

    public function test_no_mismatch_when_at_least_one_configured_selector_matched(): void
    {
        $mismatched = $this->invoke([
            'collect_selectors' => ['.gallery img'],
            'thumbnail_selectors' => ['.thumb button'],
        ], [
            'learned_recipe' => [
                'collect_selectors' => ['.gallery img'],
                'thumbnail_selectors' => [],
            ],
        ]);

        $this->assertFalse($mismatched);
    }

    public function test_no_mismatch_when_the_recipe_configured_no_selectors_at_all(): void
    {
        $mismatched = $this->invoke([
            'collect_selectors' => [],
            'thumbnail_selectors' => [],
        ], [
            'learned_recipe' => [
                'collect_selectors' => [],
                'thumbnail_selectors' => [],
            ],
        ]);

        $this->assertFalse($mismatched);
    }

    public function test_no_mismatch_when_the_script_did_not_return_a_learned_recipe(): void
    {
        $mismatched = $this->invoke([
            'collect_selectors' => ['.gallery img'],
        ], []);

        $this->assertFalse($mismatched);
    }

    private function invoke(array $recipe, array $result): bool
    {
        $method = new ReflectionMethod(BrowserProductGalleryExtractor::class, 'recipeSelectorsMismatchPage');
        $method->setAccessible(true);

        return $method->invoke(app(BrowserProductGalleryExtractor::class), $recipe, $result);
    }
}
