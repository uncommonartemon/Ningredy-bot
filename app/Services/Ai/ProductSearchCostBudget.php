<?php

namespace App\Services\Ai;

class ProductSearchCostBudget
{
    public function __construct(
        private readonly AiSettings $settings,
        private readonly AiUsageReporter $usageReporter,
    ) {}

    public function exceeded(?int $telegramUpdateId): bool
    {
        if (! $telegramUpdateId) {
            return false;
        }

        $limit = $this->settings->maxSearchCostUsd();

        if ($limit <= 0) {
            return false;
        }

        $spent = $this->spent($telegramUpdateId);

        return $spent !== null && $spent >= $limit;
    }

    public function spent(?int $telegramUpdateId): ?float
    {
        if (! $telegramUpdateId) {
            return null;
        }

        $spent = $this->usageReporter->forTelegramUpdate($telegramUpdateId)['estimated_cost_usd'] ?? null;

        return is_numeric($spent) ? (float) $spent : null;
    }

    /**
     * True when a dollar limit is configured but this search's spend cannot
     * currently be priced (AiUsageReporter::estimateCost() only returns null
     * when not one of the search's AI runs so far has a known per-token
     * price - e.g. a model was added to ai_models without pricing filled
     * in). exceeded() silently treats unpriceable spend as "under budget"
     * forever, so callers that would otherwise loop purely on this check
     * (the fallback discovery search) must fall back to a round-count
     * safety cap instead - the same one already used for tests/budgetless
     * runs - rather than being bounded only by the time limit.
     */
    public function unmeasurable(?int $telegramUpdateId): bool
    {
        return $this->limit() > 0 && $this->spent($telegramUpdateId) === null;
    }

    public function reachedFraction(?int $telegramUpdateId, float $fraction): bool
    {
        $limit = $this->limit();
        $spent = $this->spent($telegramUpdateId);

        return $limit > 0 && $spent !== null && $spent >= $limit * max(0.0, min(1.0, $fraction));
    }

    public function limit(): float
    {
        return $this->settings->maxSearchCostUsd();
    }
}
