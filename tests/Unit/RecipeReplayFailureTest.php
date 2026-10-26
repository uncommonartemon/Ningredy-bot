<?php

namespace Tests\Unit;

use App\Services\GalleryRecipes\RecipeReplayFailure;
use PHPUnit\Framework\TestCase;

class RecipeReplayFailureTest extends TestCase
{
    public function test_a_browser_timeout_is_an_interruption_to_recover_from(): void
    {
        $failure = RecipeReplayFailure::inspect(['failure_kind' => 'browser_timeout', 'action_trace' => [['clicked' => true]]], false);

        $this->assertTrue($failure->interrupted);
        $this->assertFalse($failure->planBlocked);
        $this->assertSame('execution_recovery', $failure->recoveryMode());
        $this->assertFalse($failure->mayAffectCompatibility());
    }

    public function test_a_deadline_spent_on_hidden_click_targets_is_a_plan_to_repair_not_bad_luck(): void
    {
        // Live #181, #193: three rounds each on ASUS, the hidden zoom the only
        // click, the plan replayed every time because a deadline read as chance.
        $failure = RecipeReplayFailure::inspect([
            'failure_kind' => 'browser_timeout',
            'action_trace' => [['clicked' => false, 'interaction_error' => 'locator.click: Timeout 2000ms exceeded. element is not visible']],
            'diagnostics' => ['partial' => true, 'hidden_click_targets' => ['[aria-label^="Zoom in"]']],
        ], false);

        $this->assertTrue($failure->planBlocked);
        $this->assertFalse($failure->interrupted);
        $this->assertSame('hidden_click_targets', $failure->kind);
        $this->assertSame('recipe_repair', $failure->recoveryMode());
        $this->assertFalse($failure->mayAffectCompatibility(), 'A wrong plan says nothing about the layout.');
    }

    public function test_a_hidden_target_after_a_click_that_worked_is_still_an_interruption(): void
    {
        $failure = RecipeReplayFailure::inspect([
            'failure_kind' => 'browser_timeout',
            'action_trace' => [['clicked' => true], ['clicked' => false, 'interaction_error' => 'element is not visible']],
            'diagnostics' => ['partial' => true, 'hidden_click_targets' => ['#zoom']],
        ], false);

        $this->assertFalse($failure->planBlocked);
        $this->assertTrue($failure->interrupted);
    }

    public function test_missing_selectors_without_interruption_mean_an_unconfirmed_source(): void
    {
        $failure = RecipeReplayFailure::inspect(['failure_kind' => 'recipe_mismatch'], true);

        $this->assertTrue($failure->sourceUnconfirmed);
        $this->assertSame('source_diagnosis', $failure->recoveryMode());
    }
}
