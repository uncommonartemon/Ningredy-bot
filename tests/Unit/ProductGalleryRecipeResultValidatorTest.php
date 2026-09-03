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
            ['gallery_present' => true, 'content_confirmed_product' => true, 'expected_image_count' => 13],
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
            ['gallery_present' => true, 'content_confirmed_product' => true, 'expected_image_count' => 13],
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
            ['gallery_present' => true, 'content_confirmed_product' => true, 'expected_image_count' => 13],
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
                'content_confirmed_product' => true,
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
                'content_confirmed_product' => true,
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

    public function test_a_single_arrow_pressed_once_cannot_pass_a_multi_frame_traversal(): void
    {
        // Real case (2026-09-02, B&H modal recipe): the recipe declared 4 frames
        // and asked for three presses of one next arrow, the runner pressed it
        // once, and validation passed anyway because the required click count
        // was capped at the arrow's own match count of 1.
        $result = app(ProductGalleryRecipeResultValidator::class)->validate(
            [
                'gallery_present' => true,
                'content_confirmed_product' => true,
                'expected_image_count' => 4,
                'open_selectors' => ['img.main'],
                'actions' => [
                    ['kind' => 'click', 'selector' => 'img.main', 'limit' => 1, 'purpose' => 'Open viewer'],
                    ['kind' => 'click_each', 'selector' => 'button.next', 'limit' => 3, 'after_each_selector' => ''],
                ],
            ],
            [
                'images' => $this->images(3),
                'diagnostics' => ['distinct_dom_assets' => 3],
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
                    'repetition' => 0,
                    'selector_match_count' => 1,
                    'clicked' => true,
                    'changed' => true,
                ]],
            ],
        );

        $this->assertFalse($result['passed']);
        $this->assertStringContainsString('clicked 1 of 3', $result['reason']);
    }

    public function test_a_single_arrow_that_runs_out_of_frames_early_still_passes(): void
    {
        // The same arrow legitimately exhausts before its limit: the press that
        // changes nothing is the end of the gallery, not an incomplete plan.
        $result = app(ProductGalleryRecipeResultValidator::class)->validate(
            [
                'gallery_present' => true,
                'content_confirmed_product' => true,
                'expected_image_count' => 3,
                'open_selectors' => ['img.main'],
                'actions' => [
                    ['kind' => 'click', 'selector' => 'img.main', 'limit' => 1, 'purpose' => 'Open viewer'],
                    ['kind' => 'click_each', 'selector' => 'button.next', 'limit' => 5],
                ],
            ],
            [
                'images' => $this->images(3),
                'diagnostics' => ['distinct_dom_assets' => 3],
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
                    'repetition' => 0,
                    'selector_match_count' => 1,
                    'clicked' => true,
                    'changed' => true,
                ], [
                    'action' => 'click_each',
                    'action_index' => 1,
                    'repetition' => 1,
                    'selector_match_count' => 1,
                    'clicked' => true,
                    'changed' => false,
                ]],
            ],
        );

        $this->assertTrue($result['passed']);
    }

    public function test_opener_click_without_a_gallery_state_change_is_rejected(): void
    {
        $result = app(ProductGalleryRecipeResultValidator::class)->validate(
            [
                'gallery_present' => true,
                'content_confirmed_product' => true,
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
            'content_confirmed_product' => true,
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
            ['gallery_present' => true, 'content_confirmed_product' => true, 'expected_image_count' => 3],
            [
                'images' => $this->images(3),
                'failure_kind' => 'browser_crash',
                'diagnostics' => ['partial' => true],
            ],
        );

        $this->assertFalse($result['passed']);
        $this->assertStringContainsString('Browser execution was partial', $result['reason']);
    }

    public function test_observed_zoom_control_rejects_low_resolution_thumbnail_traversal_without_after_each(): void
    {
        $result = app(ProductGalleryRecipeResultValidator::class)->validate(
            [
                'gallery_present' => true,
                'content_confirmed_product' => true,
                'expected_image_count' => 2,
                'actions' => [['kind' => 'click_each', 'selector' => '.thumb', 'limit' => 2]],
            ],
            [
                'images' => $this->images(2),
                'action_trace' => collect(range(0, 1))->map(fn (int $repetition): array => [
                    'action' => 'click_each', 'action_index' => 0, 'repetition' => $repetition,
                    'selector_match_count' => 2, 'clicked' => true,
                ])->all(),
                'diagnostics' => ['validated_image_evidence' => [
                    ['width' => 750], ['width' => 750],
                ]],
                'post_interaction_scout' => ['action_candidates' => [[
                    'selector' => 'button[data-zoom-plus]',
                    'title' => 'Zoom plus',
                ]]],
            ],
        );

        $this->assertFalse($result['passed']);
        $this->assertStringContainsString('after_each_selector', $result['reason']);
    }

    public function test_unprobed_urls_cannot_publish_a_recipe(): void
    {
        $result = app(ProductGalleryRecipeResultValidator::class)->validate(
            ['gallery_present' => true, 'content_confirmed_product' => true, 'expected_image_count' => 3],
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
            ['gallery_present' => true, 'content_confirmed_product' => true, 'expected_image_count' => 3],
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
            ['gallery_present' => true, 'content_confirmed_product' => true, 'expected_image_count' => 3],
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
    public function test_a_zoom_control_that_disappears_at_maximum_is_exhaustion_not_failure(): void
    {
        // A "zoom in" button that removes itself once the image is at full size
        // has finished its job. Its missing-selector entry carries clicked=false,
        // so it counted for nothing and a working recipe was thrown away.
        $result = app(ProductGalleryRecipeResultValidator::class)->validate(
            $this->zoomingOpenerRecipe(afterEachLimit: 3),
            [
                'images' => $this->images(3),
                'diagnostics' => ['distinct_dom_assets' => 3],
                'action_trace' => [
                    $this->openerTrace(),
                    $this->zoomTrace(followupRepetition: 0, changed: true),
                    [
                        'action' => 'click',
                        'action_index' => 0,
                        'parent_repetition' => 0,
                        'followup_repetition' => 1,
                        'after_each' => true,
                        'clicked' => false,
                        'changed' => false,
                        'selector_missing' => true,
                        'selector_match_count' => 0,
                    ],
                ],
            ],
        );

        $this->assertTrue($result['passed'], $result['reason'] ?? '');
    }

    public function test_the_opening_click_owes_its_zoom_follow_up_too(): void
    {
        // The frame the opener reveals needs the same enlargement as the frames
        // an arrow reaches later, or one gallery comes back at two resolutions.
        $result = app(ProductGalleryRecipeResultValidator::class)->validate(
            $this->zoomingOpenerRecipe(afterEachLimit: 2),
            [
                'images' => $this->images(3),
                'diagnostics' => ['distinct_dom_assets' => 3],
                'action_trace' => [$this->openerTrace()],
            ],
        );

        $this->assertFalse($result['passed']);
        $this->assertStringContainsString('nested follow-up', $result['reason']);
    }

    public function test_a_zoom_selector_that_never_worked_is_still_a_broken_step(): void
    {
        // Nothing was ever clicked, so the missing selector is not exhaustion -
        // it is a step of the plan that does not exist on the page.
        $result = app(ProductGalleryRecipeResultValidator::class)->validate(
            $this->zoomingOpenerRecipe(afterEachLimit: 2),
            [
                'images' => $this->images(3),
                'diagnostics' => ['distinct_dom_assets' => 3],
                'action_trace' => [
                    $this->openerTrace(),
                    [
                        'action' => 'click',
                        'action_index' => 0,
                        'parent_repetition' => 0,
                        'followup_repetition' => 0,
                        'after_each' => true,
                        'clicked' => false,
                        'changed' => false,
                        'selector_missing' => true,
                        'selector_match_count' => 0,
                    ],
                ],
            ],
        );

        $this->assertFalse($result['passed']);
        $this->assertStringContainsString('nested follow-up', $result['reason']);
    }

    public function test_a_zoom_that_was_still_enlarging_sends_the_round_back(): void
    {
        // The recipe asked for two presses and the image was still growing on
        // the second, so these frames are below the page's full resolution.
        // Recording that in the trace was not enough on its own: validation
        // passed, training ended, and the agent never got a round in which to
        // raise its own number.
        $result = app(ProductGalleryRecipeResultValidator::class)->validate(
            $this->zoomingOpenerRecipe(afterEachLimit: 2),
            [
                'images' => $this->images(3),
                'diagnostics' => ['distinct_dom_assets' => 3],
                'action_trace' => [
                    $this->openerTrace(),
                    $this->zoomTrace(followupRepetition: 0, changed: true),
                    [...$this->zoomTrace(followupRepetition: 1, changed: true), 'after_each_truncated' => true],
                ],
            ],
        );

        $this->assertFalse($result['passed']);
        $this->assertStringContainsString('Raise after_each_limit', $result['reason']);
    }

    public function test_a_zoom_already_at_the_schema_ceiling_is_accepted_as_it_is(): void
    {
        // Nothing left to ask for, so a still-growing image stops being a
        // reason to spend another training round.
        $trace = [$this->openerTrace()];

        for ($press = 0; $press < 20; $press++) {
            $trace[] = $this->zoomTrace(followupRepetition: $press, changed: true);
        }

        $trace[19]['after_each_truncated'] = true;

        $result = app(ProductGalleryRecipeResultValidator::class)->validate(
            $this->zoomingOpenerRecipe(afterEachLimit: 20),
            [
                'images' => $this->images(3),
                'diagnostics' => ['distinct_dom_assets' => 3],
                'action_trace' => $trace,
            ],
        );

        $this->assertTrue($result['passed'], $result['reason'] ?? '');
    }

    /** @return array<string, mixed> */
    private function zoomingOpenerRecipe(int $afterEachLimit): array
    {
        return [
            'gallery_present' => true,
            'content_confirmed_product' => true,
            'expected_image_count' => 3,
            'open_selectors' => ['img.main'],
            'actions' => [[
                'kind' => 'click',
                'selector' => 'img.main',
                'limit' => 1,
                'purpose' => 'Open viewer',
                'after_each_selector' => 'button.zoom',
                'after_each_limit' => $afterEachLimit,
            ]],
        ];
    }

    /** @return array<string, mixed> */
    private function openerTrace(): array
    {
        return [
            'action' => 'click',
            'action_index' => 0,
            'selector_match_count' => 1,
            'clicked' => true,
            'changed' => true,
            'expanded_gallery_visible_after' => true,
        ];
    }

    /** @return array<string, mixed> */
    private function zoomTrace(int $followupRepetition, bool $changed): array
    {
        return [
            'action' => 'click',
            'action_index' => 0,
            'parent_repetition' => 0,
            'followup_repetition' => $followupRepetition,
            'after_each' => true,
            'clicked' => true,
            'changed' => $changed,
            'selector_match_count' => 1,
        ];
    }

    private function images(int $count): array
    {
        return collect(range(1, $count))
            ->map(fn (int $index): string => 'https://cdn.example/frame-'.$index.'.jpg')
            ->all();
    }
}
