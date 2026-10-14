<?php

namespace Tests\Unit;

use App\Services\Products\ProductGalleryRecipeResultValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ScrollEachResultValidatorTest extends TestCase
{
    use RefreshDatabase;

    public function test_current_element_coverage_not_training_limit_confirms_different_gallery_sizes(): void
    {
        $recipe = $this->recipe();
        foreach ([4, 9, 4] as $count) {
            $validation = app(ProductGalleryRecipeResultValidator::class)->validate(
                $recipe, $this->galleryResult($count), countedOnThisPage: false,
            );
            $this->assertTrue($validation['passed'], $validation['reason']);
            $this->assertSame($count, $validation['expected']);
        }
    }

    public function test_scroll_each_always_requires_explicit_traversal_end_even_with_enough_photos(): void
    {
        foreach ([null, 'auto', 'elements', 'repeat'] as $mode) {
            $recipe = $this->recipe();
            if ($mode !== null) {
                $recipe['actions'][0]['iteration_mode'] = $mode;
            }
            $result = $this->galleryResult(4);
            unset($result['action_trace'][3]['traversal_complete']);
            $validation = app(ProductGalleryRecipeResultValidator::class)->validate($recipe, $result);
            $this->assertFalse($validation['passed']);
            $this->assertStringContainsString('traversal_not_confirmed', $validation['reason']);
        }
    }

    public function test_safety_ceiling_and_loading_failure_are_not_successful_gallery_ends(): void
    {
        foreach (['safety_ceiling_reached', 'media_wait_timeout', 'action_failed'] as $reason) {
            $result = $this->galleryResult(4);
            $result['action_trace'][3]['traversal_complete'] = false;
            $result['action_trace'][3]['traversal_stop_reason'] = $reason;
            $validation = app(ProductGalleryRecipeResultValidator::class)->validate($this->recipe(), $result);
            $this->assertFalse($validation['passed']);
            $this->assertStringContainsString($reason, $validation['reason']);
        }
    }

    public function test_completion_flag_cannot_hide_missing_failed_or_duplicate_element_visits(): void
    {
        foreach (['missing', 'failed', 'duplicate'] as $fault) {
            $result = $this->galleryResult(4);
            if ($fault === 'missing') {
                unset($result['action_trace'][1]);
            } elseif ($fault === 'failed') {
                $result['action_trace'][1]['clicked'] = false;
            } else {
                $result['action_trace'][1]['index'] = 0;
            }
            $validation = app(ProductGalleryRecipeResultValidator::class)->validate($this->recipe(), $result);
            $this->assertFalse($validation['passed']);
            $this->assertStringContainsString('scrolled 3 of 4', $validation['reason']);
        }
    }

    public function test_start_offset_is_respected_but_a_shrinking_or_growing_list_does_not_hide_unvisited_elements(): void
    {
        $recipe = $this->recipe();
        $recipe['actions'][0]['index'] = 1;
        $result = $this->galleryResult(4);
        $result['action_trace'] = array_slice($result['action_trace'], 1);
        $validation = app(ProductGalleryRecipeResultValidator::class)->validate($recipe, $result);
        $this->assertTrue($validation['passed'], $validation['reason']);

        $result['action_trace'][0]['selector_match_count'] = 9;
        $validation = app(ProductGalleryRecipeResultValidator::class)->validate($recipe, $result);
        $this->assertFalse($validation['passed']);
        $this->assertStringContainsString('scrolled 3 of 8', $validation['reason']);

        $result['action_trace'][0]['selector_match_count'] = 4;
        $result['action_trace'][2]['traversal_match_count'] = 5;
        $validation = app(ProductGalleryRecipeResultValidator::class)->validate($recipe, $result);
        $this->assertFalse($validation['passed']);
        $this->assertStringContainsString('scrolled 3 of 4', $validation['reason']);
    }

    public function test_an_absent_scroll_traversal_cannot_be_waived_as_an_optional_obstacle(): void
    {
        $recipe = $this->recipe();
        $recipe['actions'][0]['when'] = 'if_present';
        $result = $this->galleryResult(4);
        $result['action_trace'] = [[
            'action' => 'scroll_each', 'action_index' => 0, 'clicked' => false,
            'optional_absent' => true, 'selector_match_count' => 0,
        ]];
        $validation = app(ProductGalleryRecipeResultValidator::class)->validate($recipe, $result);
        $this->assertFalse($validation['passed']);
        $this->assertStringContainsString('selector_missing', $validation['reason']);

        // Compatibility: this change does not reinterpret a legacy optional gate.
        $recipe['actions'][0]['kind'] = 'click';
        $result['action_trace'][0]['action'] = 'click';
        $validation = app(ProductGalleryRecipeResultValidator::class)->validate($recipe, $result);
        $this->assertTrue($validation['passed'], $validation['reason']);
    }

    public function test_each_successful_scroll_still_owes_its_declared_nested_followup(): void
    {
        $recipe = $this->recipe();
        $recipe['actions'][0]['after_each_selector'] = '.enlarge';
        $recipe['actions'][0]['after_each_limit'] = 1;
        $result = $this->galleryResult(4);
        $validation = app(ProductGalleryRecipeResultValidator::class)->validate($recipe, $result);
        $this->assertFalse($validation['passed']);
        $this->assertStringContainsString('nested follow-up', $validation['reason']);

        foreach (range(0, 3) as $repetition) {
            $result['action_trace'][] = [
                'action' => 'scroll_each', 'action_index' => 0, 'after_each' => true,
                'parent_repetition' => $repetition, 'clicked' => true, 'changed' => true,
            ];
        }
        $validation = app(ProductGalleryRecipeResultValidator::class)->validate($recipe, $result);
        $this->assertTrue($validation['passed'], $validation['reason']);
    }

    private function recipe(): array
    {
        return ['gallery_present' => true, 'content_confirmed_product' => true,
            'expected_image_count' => 4, 'actions' => [[
                'kind' => 'scroll_each', 'selector' => '.product-media .frame', 'index' => 0, 'limit' => 4,
            ]]];
    }

    private function galleryResult(int $count): array
    {
        $result = [
            'images' => array_map(fn (int $index): string => 'https://cdn.example/frame-'.$index.'.jpg', range(1, $count)),
            'diagnostics' => ['observed_gallery_count' => $count],
            'action_trace' => array_map(fn (int $index): array => [
                'action' => 'scroll_each', 'action_index' => 0, 'index' => $index, 'repetition' => $index,
                'clicked' => true, 'changed' => false, 'selector_match_count' => $count,
            ], range(0, $count - 1)),
        ];
        $result['action_trace'][$count - 1] += [
            'traversal_complete' => true, 'traversal_stop_reason' => 'all_current_controls_visited',
            'traversal_match_count' => $count,
        ];

        return $result;
    }
}
