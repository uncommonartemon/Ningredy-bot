<?php

namespace Tests\Unit;

use App\Services\Products\ProductGalleryRecipeResultValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductGalleryRecipeResultValidatorTest extends TestCase
{
    use RefreshDatabase;

    public function test_five_of_thirteen_structurally_observed_frames_is_not_complete(): void
    {
        $result = app(ProductGalleryRecipeResultValidator::class)->validate(
            ['gallery_present' => true, 'expected_image_count' => 13],
            [
                'images' => $this->images(5),
                'diagnostics' => ['distinct_dom_assets' => 13],
            ],
        );

        $this->assertFalse($result['passed']);
        $this->assertSame(10, $result['expected']);
        $this->assertSame(5, $result['extracted']);
    }

    public function test_ten_of_thirteen_structurally_observed_frames_is_complete_at_global_limit(): void
    {
        $result = app(ProductGalleryRecipeResultValidator::class)->validate(
            ['gallery_present' => true, 'expected_image_count' => 13],
            [
                'images' => $this->images(10),
                'diagnostics' => ['distinct_dom_assets' => 13],
            ],
        );

        $this->assertTrue($result['passed']);
        $this->assertSame(10, $result['expected']);
        $this->assertSame(10, $result['extracted']);
    }

    public function test_category_minimum_remains_fallback_when_gallery_size_is_unknown(): void
    {
        $result = app(ProductGalleryRecipeResultValidator::class)->validate(
            ['gallery_present' => true, 'expected_image_count' => 13],
            ['images' => $this->images(3)],
        );

        $this->assertTrue($result['passed']);
        $this->assertSame(3, $result['expected']);
    }

    public function test_modal_thumbnail_count_cannot_hide_an_incomplete_click_each_traversal(): void
    {
        $result = app(ProductGalleryRecipeResultValidator::class)->validate(
            [
                'gallery_present' => true,
                'expected_image_count' => 16,
                'open_selectors' => ['button.open'],
                'actions' => [
                    ['kind' => 'click', 'selector' => 'button.open', 'limit' => 1, 'purpose' => 'Open viewer'],
                    ['kind' => 'click_each', 'limit' => 16],
                ],
            ],
            [
                'images' => $this->images(16),
                'diagnostics' => ['distinct_dom_assets' => 16],
                'action_trace' => [[
                    'action' => 'click',
                    'action_index' => 0,
                    'selector_match_count' => 1,
                    'clicked' => true,
                    'changed' => true,
                    'expanded_gallery_visible_after' => true,
                ], [
                    'action' => 'click_each',
                    'action_index' => 1,
                    'selector_match_count' => 16,
                    'clicked' => true,
                ]],
            ],
        );

        $this->assertFalse($result['passed']);
        $this->assertStringContainsString('clicked 1 of 16', $result['reason']);
    }

    public function test_complete_click_each_traversal_can_publish_the_gallery_recipe(): void
    {
        $trace = collect(range(0, 15))
            ->map(fn (int $index): array => [
                'action' => 'click_each',
                'action_index' => 1,
                'repetition' => $index,
                'selector_match_count' => 16,
                'clicked' => true,
            ])
            ->prepend([
                'action' => 'click',
                'action_index' => 0,
                'selector_match_count' => 1,
                'clicked' => true,
                'changed' => true,
                'expanded_gallery_visible_after' => true,
            ])
            ->all();
        $result = app(ProductGalleryRecipeResultValidator::class)->validate(
            [
                'gallery_present' => true,
                'expected_image_count' => 16,
                'open_selectors' => ['button.open'],
                'actions' => [
                    ['kind' => 'click', 'selector' => 'button.open', 'limit' => 1, 'purpose' => 'Open viewer'],
                    ['kind' => 'click_each', 'limit' => 16],
                ],
            ],
            [
                'images' => $this->images(16),
                'diagnostics' => ['distinct_dom_assets' => 16],
                'action_trace' => $trace,
            ],
        );

        $this->assertTrue($result['passed']);
        $this->assertSame(10, $result['expected']);
    }

    public function test_opener_click_without_a_gallery_state_change_is_rejected(): void
    {
        $result = app(ProductGalleryRecipeResultValidator::class)->validate(
            [
                'gallery_present' => true,
                'expected_image_count' => 3,
                'open_selectors' => ['button.open'],
                'actions' => [[
                    'kind' => 'click',
                    'selector' => 'button.open',
                    'purpose' => 'Open full screen viewer',
                ]],
            ],
            [
                'images' => $this->images(3),
                'action_trace' => [[
                    'action' => 'click',
                    'action_index' => 0,
                    'clicked' => true,
                    'changed' => false,
                    'expanded_gallery_visible_after' => false,
                ]],
            ],
        );

        $this->assertFalse($result['passed']);
        $this->assertStringContainsString('viewer did not open', $result['reason']);
    }

    public function test_arrow_traversal_must_reach_no_change_or_its_limit(): void
    {
        $recipe = [
            'gallery_present' => true,
            'expected_image_count' => 3,
            'actions' => [[
                'kind' => 'click_until_no_change',
                'selector' => 'button.next',
                'limit' => 5,
            ]],
        ];
        $trace = collect(range(0, 1))->map(fn (int $index): array => [
            'action' => 'click_until_no_change',
            'action_index' => 0,
            'repetition' => $index,
            'clicked' => true,
            'changed' => true,
        ])->all();

        $incomplete = app(ProductGalleryRecipeResultValidator::class)->validate(
            $recipe,
            ['images' => $this->images(3), 'action_trace' => $trace],
        );
        $complete = app(ProductGalleryRecipeResultValidator::class)->validate(
            $recipe,
            [
                'images' => $this->images(3),
                'action_trace' => [...$trace, [
                    'action' => 'click_until_no_change',
                    'action_index' => 0,
                    'repetition' => 2,
                    'clicked' => true,
                    'changed' => false,
                ]],
            ],
        );

        $this->assertFalse($incomplete['passed']);
        $this->assertStringContainsString('before exhaustion', $incomplete['reason']);
        $this->assertTrue($complete['passed']);
    }

    public function test_partial_browser_result_cannot_publish_a_recipe_even_with_enough_urls(): void
    {
        $result = app(ProductGalleryRecipeResultValidator::class)->validate(
            ['gallery_present' => true, 'expected_image_count' => 3],
            [
                'images' => $this->images(3),
                'failure_kind' => 'browser_crash',
                'diagnostics' => ['partial' => true],
            ],
        );

        $this->assertFalse($result['passed']);
        $this->assertStringContainsString('Browser execution was partial', $result['reason']);
    }

    public function test_unprobed_urls_cannot_publish_a_recipe(): void
    {
        $result = app(ProductGalleryRecipeResultValidator::class)->validate(
            ['gallery_present' => true, 'expected_image_count' => 3],
            [
                'images' => $this->images(3),
                'diagnostics' => ['validated_candidates' => 1],
            ],
        );

        $this->assertFalse($result['passed']);
        $this->assertStringContainsString('without technical image validation', $result['reason']);
    }

    public function test_strict_recipe_cannot_publish_network_or_recommendation_images_without_dom_provenance(): void
    {
        $images = $this->images(3);
        $result = app(ProductGalleryRecipeResultValidator::class)->validate(
            ['gallery_present' => true, 'expected_image_count' => 3],
            [
                'images' => $images,
                'diagnostics' => [
                    'strict_recipe' => true,
                    'validated_candidates' => 3,
                    'validated_image_evidence' => collect($images)->map(fn (string $url): array => [
                        'url' => $url,
                        'source' => 'network_or_payload',
                    ])->all(),
                ],
            ],
        );

        $this->assertFalse($result['passed']);
        $this->assertStringContainsString('without recipe-scoped DOM provenance', $result['reason']);
    }

    public function test_strict_recipe_can_publish_only_recipe_scoped_dom_images(): void
    {
        $images = $this->images(3);
        $result = app(ProductGalleryRecipeResultValidator::class)->validate(
            ['gallery_present' => true, 'expected_image_count' => 3],
            [
                'images' => $images,
                'diagnostics' => [
                    'strict_recipe' => true,
                    'validated_candidates' => 3,
                    'validated_image_evidence' => collect($images)->map(fn (string $url): array => [
                        'url' => $url,
                        'source' => 'recipe_dom',
                    ])->all(),
                ],
            ],
        );

        $this->assertTrue($result['passed']);
    }

    /** @return array<int, string> */
    private function images(int $count): array
    {
        return collect(range(1, $count))
            ->map(fn (int $index): string => 'https://cdn.example/frame-'.$index.'.jpg')
            ->all();
    }
}
