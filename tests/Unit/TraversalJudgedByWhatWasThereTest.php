<?php

namespace Tests\Unit;

use App\Services\Products\ProductGalleryRecipeResultValidator;
use Tests\TestCase;

/**
 * Live on dell.com, 2026-09-08: three separate rounds collected all eighteen
 * photographs and all three were refused for "clicked 9 of 18 required
 * thumbnail controls".
 *
 * The strip is not a fixed thing. Eighteen thumbnails before the viewer opened
 * - two colourways - and nine once it had, because only the shown colourway is
 * rendered. The runner walked the nine that existed and stopped because there
 * were no more. This check remembered the eighteen it had seen earlier and
 * called that half a traversal, which no page with a variant selector could
 * ever satisfy.
 */
class TraversalJudgedByWhatWasThereTest extends TestCase
{
    public function test_a_strip_that_shrank_is_judged_on_what_was_left(): void
    {
        $result = $this->validate($this->trace(before: 18, walked: 9));

        $this->assertTrue(
            $result['passed'],
            'Nine presses against the nine controls that existed is a finished walk.',
        );
    }

    public function test_a_walk_cut_short_is_still_caught(): void
    {
        // The strip never shrank: eighteen controls throughout, nine pressed.
        // That is a real half-traversal and must still fail.
        $result = $this->validate($this->trace(before: 18, walked: 9, shrinks: false));

        $this->assertFalse($result['passed']);
        $this->assertStringContainsString('clicked 9 of 18', $result['reason']);
    }

    public function test_a_full_walk_of_an_unchanged_strip_passes(): void
    {
        $this->assertTrue($this->validate($this->trace(before: 4, walked: 4, shrinks: false))['passed']);
    }

    /**
     * @param  array<int, array<string, mixed>>  $trace
     * @return array{passed: bool, expected: int, extracted: int, reason: string}
     */
    private function validate(array $trace): array
    {
        return app(ProductGalleryRecipeResultValidator::class)->validate(
            [
                'gallery_present' => true,
                'content_confirmed_product' => true,
                'collect_selectors' => ['.gallery img'],
                'actions' => [[
                    'kind' => 'click_each',
                    'selector' => 'button.thumb',
                    'index' => 0,
                    'limit' => 20,
                    'when' => 'always',
                ]],
            ],
            [
                'images' => collect(range(1, 18))
                    ->map(fn (int $n): string => 'https://cdn.example/frame-'.$n.'.jpg')
                    ->all(),
                'action_trace' => $trace,
                'diagnostics' => ['distinct_dom_assets' => 18, 'observed_gallery_count' => 18],
            ],
            minimumSuccessCount: 6,
        );
    }

    /** @return array<int, array<string, mixed>> */
    private function trace(int $before, int $walked, bool $shrinks = true): array
    {
        return collect(range(1, $walked))
            ->map(fn (int $press): array => [
                'action' => 'click_each',
                'action_index' => 0,
                'clicked' => true,
                'changed' => true,
                // The runner re-reads the control count on every repetition, so
                // the first press sees the strip the page opened with.
                'selector_match_count' => $shrinks && $press > 1 ? $walked : $before,
            ])
            ->all();
    }
}
