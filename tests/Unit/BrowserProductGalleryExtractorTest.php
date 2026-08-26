<?php

namespace Tests\Unit;

use App\Services\Products\BrowserProductGalleryExtractor;
use App\Services\Products\ProductGalleryRecipeTrainer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

class BrowserProductGalleryExtractorTest extends TestCase
{
    use RefreshDatabase;

    public function test_vision_first_does_not_train_when_the_domain_has_no_active_recipe(): void
    {
        config()->set('product-images.browser_fallback.enabled', true);
        $this->mock(ProductGalleryRecipeTrainer::class)->shouldNotReceive('train');
        $events = [];

        $images = app(BrowserProductGalleryExtractor::class)->extract(
            'https://example.com/products/component',
            10,
            function (string $level, string $message) use (&$events): void {
                $events[] = [$level, $message];
            },
            activeRecipeOnly: true,
        );

        $this->assertSame([], $images);
        $this->assertTrue(collect($events)->contains(
            fn (array $event): bool => str_contains($event[1], 'нет активного рецепта')
                && str_contains($event[1], 'без обучения Playwright'),
        ));
    }

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

    public function test_no_mismatch_when_an_ordered_action_selector_matched(): void
    {
        $mismatched = $this->invoke([
            'collect_selectors' => ['[data-gallery-image]'],
            'thumbnail_selectors' => [],
            'actions' => [[
                'kind' => 'click',
                'selector' => 'button[data-gallery]',
            ]],
        ], [
            'learned_recipe' => [
                'collect_selectors' => [],
                'thumbnail_selectors' => [],
                'actions' => [[
                    'kind' => 'click',
                    'selector' => 'button[data-gallery]',
                ]],
            ],
        ]);

        $this->assertFalse($mismatched);
    }

    public function test_strict_recipe_dom_evidence_beats_empty_pre_action_snapshot(): void
    {
        $mismatched = $this->invoke([
            'collect_selectors' => ['.dialog img'],
        ], [
            'images' => ['https://cdn.example/product.jpg'],
            'learned_recipe' => [
                'collect_selectors' => [],
                'thumbnail_selectors' => [],
                'actions' => [],
            ],
            'diagnostics' => [
                'strict_recipe' => true,
                'validated_image_evidence' => [[
                    'url' => 'https://cdn.example/product.jpg',
                    'source' => 'recipe_dom',
                ]],
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

    public function test_saved_recipe_drops_a_broad_collect_selector_when_scoped_variant_exists(): void
    {
        $method = new ReflectionMethod(BrowserProductGalleryExtractor::class, 'normalizeRecipeForExecution');
        $method->setAccessible(true);

        $recipe = $method->invoke(app(BrowserProductGalleryExtractor::class), [
            'collect_selectors' => [
                'img.w-full.h-full',
                'button.w-16.h-16 img.w-full.h-full',
            ],
            'thumbnail_selectors' => ['button.w-16.h-16'],
        ]);

        $this->assertSame(
            ['button.w-16.h-16 img.w-full.h-full'],
            $recipe['collect_selectors'],
        );
        $this->assertSame(['button.w-16.h-16'], $recipe['thumbnail_selectors']);
    }

    private function invoke(array $recipe, array $result): bool
    {
        $method = new ReflectionMethod(BrowserProductGalleryExtractor::class, 'recipeSelectorsMismatchPage');
        $method->setAccessible(true);

        return $method->invoke(app(BrowserProductGalleryExtractor::class), $recipe, $result);
    }
}
