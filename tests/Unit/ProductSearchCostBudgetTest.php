<?php

namespace Tests\Unit;

use App\Services\Ai\AiSettings;
use App\Services\Ai\AiUsageReporter;
use App\Services\Ai\ProductSearchCostBudget;
use Mockery;
use Tests\TestCase;

class ProductSearchCostBudgetTest extends TestCase
{
    private function budget(float $limit, ?float $spent): ProductSearchCostBudget
    {
        $settings = Mockery::mock(AiSettings::class);
        $settings->shouldReceive('maxSearchCostUsd')->andReturn($limit);
        $usageReporter = Mockery::mock(AiUsageReporter::class);
        $usageReporter->shouldReceive('forTelegramUpdate')->andReturn(['estimated_cost_usd' => $spent]);

        return new ProductSearchCostBudget($settings, $usageReporter);
    }

    public function test_a_source_that_stayed_under_its_share_is_not_exceeded(): void
    {
        // Limit $1, 40% share = $0.40 allowance. This source has spent
        // 0.30 - 0.05 = $0.25 so far, under its $0.40 share.
        $budget = $this->budget(limit: 1.0, spent: 0.30);

        $this->assertFalse($budget->exceededForSource(123, spentAtSourceStart: 0.05, shareFraction: 0.4));
    }

    public function test_a_source_that_reached_its_share_is_exceeded(): void
    {
        // 0.50 - 0.05 = $0.45 spent by this source alone, over its $0.40 share.
        $budget = $this->budget(limit: 1.0, spent: 0.50);

        $this->assertTrue($budget->exceededForSource(123, spentAtSourceStart: 0.05, shareFraction: 0.4));
    }

    public function test_only_this_sources_own_delta_counts_not_the_whole_searchs_prior_spend(): void
    {
        // A different source already spent $0.90 before this one started;
        // this source itself has only spent 0.95 - 0.90 = $0.05, well under
        // its own $0.40 share of the total limit.
        $budget = $this->budget(limit: 1.0, spent: 0.95);

        $this->assertFalse($budget->exceededForSource(123, spentAtSourceStart: 0.90, shareFraction: 0.4));
    }

    public function test_a_disabled_limit_never_exceeds(): void
    {
        $budget = $this->budget(limit: 0.0, spent: 5.0);

        $this->assertFalse($budget->exceededForSource(123, spentAtSourceStart: 0.0, shareFraction: 0.4));
    }

    public function test_unmeasurable_spend_never_exceeds(): void
    {
        $budget = $this->budget(limit: 1.0, spent: null);

        $this->assertFalse($budget->exceededForSource(123, spentAtSourceStart: 0.0, shareFraction: 0.4));
    }
}
