<?php

namespace App\Services\Products;

use App\Models\ProductDraft;
use App\Services\Ai\ProductSearchCostBudget;
use App\Services\Ai\ProductSearchTimeBudget;

/**
 * Finish the chosen-source card before it reaches review. Recovery reuses
 * captured evidence and never restarts research or trains another gallery.
 */
class ProductCardAssembly
{
    public function __construct(
        private readonly ProductSpecificationReconciler $reconciler,
        private readonly ProductSearchCostBudget $costBudget,
        private readonly ProductSearchTimeBudget $timeBudget,
        private readonly ProductSourceAttemptRecorder $attempts,
    ) {}

    public function complete(ProductDraft $draft, array $source, ?int $updateId): SpecificationReconciliationOutcome
    {
        $updateId ??= $draft->telegram_update_id;
        $previousSignature = null;
        // Bound recovery without granting a fresh budget or exposing a
        // separate reconciliation job to the operator.
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            if ($attempt > 1 && ($this->costBudget->exceeded($updateId) || ! $this->timeBudget->canStart($updateId, 20))) {
                return SpecificationReconciliationOutcome::unavailable('budget_exhausted');
            }

            $outcome = $this->reconciler->reconcile($draft, $source, $updateId);
            $this->attempts->record([
                'telegram_update_id' => $updateId,
                'product_draft_id' => $draft->id,
                'product_url' => $source['url'] ?? '',
                'actor' => 'server', 'phase' => 'card_assembly', 'action' => 'reconcile_card',
                'status' => $outcome->isReconciled() ? 'completed' : 'interrupted',
                'decision' => $outcome->status->value,
                'message' => $outcome->summary ?? $outcome->unavailableReason,
                'output' => ['attempt' => $attempt, 'made_progress' => $outcome->madeProgress,
                    'reason' => $outcome->unavailableReason],
            ]);

            if ($outcome->isReconciled() || $outcome->isBudgetExhausted()
                || $outcome->status === SpecificationReconciliationStatus::Disabled
                || $outcome->unavailableReason === 'empty_url') {
                return $outcome;
            }

            $signature = $outcome->status->value.'|'.$outcome->unavailableReason.'|'.$outcome->summary;
            if (! $outcome->madeProgress && $signature === $previousSignature) {
                return $outcome;
            }
            $previousSignature = $signature;
            $source['_previous_assembly_feedback'] = [
                'status' => $outcome->status->value,
                'reason' => $outcome->unavailableReason,
                'summary' => $outcome->summary,
                'made_progress' => $outcome->madeProgress,
            ];
        }

        return $outcome;
    }
}
