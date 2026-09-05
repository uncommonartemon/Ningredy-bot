<?php

namespace Tests\Unit;

use App\Services\Products\ProductGalleryRecipeTrainer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

/**
 * What counts as a round having got somewhere.
 *
 * Both directions matter and they pull against each other. Too narrow and a
 * page that is genuinely opening up reads as stuck - the rule that used to end
 * a page after three empty rounds gave up one round before the gallery
 * appeared. Too wide and nothing ever stops: a clock in an attribute or a
 * revisited state would look like progress for ever.
 */
class TrainingProgressSignatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_round_that_reaches_new_dom_assets_is_progress_even_with_no_photographs(): void
    {
        // The canvas-viewer case from the other side: no frame is collectable
        // yet, but the page is demonstrably filling with assets, and the next
        // round is the one that finds them.
        $first = $this->signature($this->roundResult(['distinct_dom_assets' => 4]));
        $second = $this->signature($this->roundResult(['distinct_dom_assets' => 9]));

        $this->assertNotSame($first, $second);
    }

    public function test_a_round_that_only_moves_the_clock_is_not_progress(): void
    {
        // The same page, twice, with nothing but volatile noise between them.
        // A signature that changed here would disable the stagnation rule
        // entirely and let a hopeless page run until the budget stopped it.
        $first = $this->signature($this->roundResult([], [
            'fragments' => ['<div class=viewer data-render-ms="184"></div>'],
            'interactive_controls' => [['selector' => '.viewer .next']],
        ]));
        $second = $this->signature($this->roundResult([], [
            'fragments' => ['<div class=viewer data-render-ms="207"></div>'],
            'interactive_controls' => [['selector' => '.viewer .next']],
        ]));

        $this->assertSame($first, $second);
    }

    public function test_returning_to_a_state_already_seen_signs_the_same_way(): void
    {
        // A plan that cycles between two layers must not read as endless
        // progress: coming back to a state is arriving where it has been.
        $opened = $this->roundResult(['distinct_dom_assets' => 3], ['interactive_controls' => [['selector' => '.viewer']]]);
        $closed = $this->roundResult(['distinct_dom_assets' => 1], ['interactive_controls' => [['selector' => '.thumb']]]);

        $this->assertSame($this->signature($opened), $this->signature($opened));
        $this->assertNotSame($this->signature($opened), $this->signature($closed));
    }

    public function test_the_same_photographs_found_again_are_not_new(): void
    {
        $result = $this->roundResult(['distinct_dom_assets' => 5]);
        $result['images'] = ['https://cdn.example/a.jpg', 'https://cdn.example/b.jpg'];

        $this->assertSame($this->signature($result), $this->signature($result));
    }

    /** @param array<string, mixed> $result */
    private function signature(array $result): string
    {
        $method = new ReflectionMethod(ProductGalleryRecipeTrainer::class, 'trainingProgressSignature');

        return $method->invoke(app(ProductGalleryRecipeTrainer::class), $result);
    }

    /**
     * @param  array<string, mixed>  $diagnostics
     * @param  array<string, mixed>  $scout
     * @return array<string, mixed>
     */
    private function roundResult(array $diagnostics = [], array $scout = []): array
    {
        return [
            'images' => [],
            'diagnostics' => $diagnostics,
            'post_interaction_scout' => $scout,
            'action_trace' => [['action' => 'click', 'clicked' => true, 'changed' => true]],
        ];
    }
}
