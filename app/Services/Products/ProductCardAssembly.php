<?php

namespace App\Services\Products;

use App\Models\ProductDraft;
use App\Services\Ai\AiSettings;
use App\Services\Ai\ProductSearchCancellation;
use App\Services\Ai\ProductSearchTimeBudget;
use App\Services\ProductSearch\Recovery\RecoveryContext;

/**
 * Finish the chosen-source card before it reaches review. Recovery reuses
 * captured evidence and never restarts research or trains another gallery.
 *
 * The search's money limit does not stop a started assembly: that limit
 * guards against a search spending without end, and assembly cannot - a
 * failure identical to the last is not bought twice, so it keeps repairing
 * only while each attempt changes what is wrong, and MAX_ATTEMPTS is the
 * loop guard behind that. A search that paid for its photos and then delivered nothing
 * (request #89: a complete 28-field card refused over a missing citation,
 * its $0.07 repair forbidden at $1.14 of $1) is the outcome this prevents.
 * Time is bounded by the job running it, see assemblyCanStart().
 */
class ProductCardAssembly
{
    /**
     * Only a guard against a bug: repair stops much earlier, the moment an
     * attempt fails in a way an earlier one already did. Three used to end
     * cards that were one fix away (requests #99, #102: each attempt cleared
     * the last error and hit a new formal one).
     */
    public const int MAX_ATTEMPTS = 20;

    public function __construct(
        private readonly ProductSpecificationReconciler $reconciler,
        private readonly ProductSearchTimeBudget $timeBudget,
        private readonly ProductSourceAttemptRecorder $attempts,
        private readonly AiSettings $settings,
    ) {}

    public function complete(ProductDraft $draft, array $source, ?int $updateId): SpecificationReconciliationOutcome
    {
        $updateId ??= $draft->telegram_update_id;
        $outcome = $this->assemble($draft, $source, $updateId, null);

        // The assembly model could not finish the card: the fallback model
        // takes over from its last attempt instead of the operator getting
        // "search not finished" (request #51: gpt-6-luna quoted one display
        // line four times the same wrong way).
        $fallback = $this->settings->cardAssemblyFallbackModel();
        if ($fallback === null || $fallback === $this->settings->modelFor('product_specification_reconciliation')
            || $outcome->isReconciled() || $outcome->isBudgetExhausted()
            || $outcome->status === SpecificationReconciliationStatus::Disabled
            || ($outcome->repairContext['recovery_action'] ?? null) === 'try_other_source'
            || in_array($outcome->unavailableReason, ['empty_url', 'identity_check_unavailable'], true)) {
            return $outcome;
        }

        return $this->assemble($draft, $source, $updateId, $fallback);
    }

    /** @param array<string, mixed> $source carries the last attempt's feedback on to the next pass */
    private function assemble(ProductDraft $draft, array &$source, ?int $updateId, ?string $model): SpecificationReconciliationOutcome
    {
        $seenSignatures = [];
        // Bound recovery by attempts, not by a fresh budget, and never expose
        // a separate reconciliation job to the operator.
        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            app(ProductSearchCancellation::class)->throwIfCancelled($updateId);
            if ($attempt > 1 && ! $this->timeBudget->assemblyCanStart($updateId, 20)) {
                return SpecificationReconciliationOutcome::unavailable('budget_exhausted');
            }

            $outcome = $model === null
                ? $this->reconciler->reconcile($draft, $source, $updateId)
                : $this->reconciler->reconcile($draft, $source, $updateId, $model);
            app(ProductSearchCancellation::class)->throwIfCancelled($updateId);
            $this->attempts->record([
                'telegram_update_id' => $updateId,
                'product_draft_id' => $draft->id,
                'product_url' => $source['url'] ?? '',
                'actor' => 'server', 'phase' => 'card_assembly', 'action' => 'reconcile_card',
                'status' => $outcome->isReconciled() ? 'completed' : 'interrupted',
                'decision' => $outcome->status->value,
                'message' => $outcome->summary ?? $outcome->unavailableReason,
                'output' => ['attempt' => $attempt, 'model' => $model, 'made_progress' => $outcome->madeProgress,
                    'reason' => $outcome->unavailableReason,
                    'repair_context' => $outcome->repairContext, 'offer_selection' => $outcome->offerSelection],
            ]);

            if ($outcome->isReconciled() || $outcome->isBudgetExhausted()
                || $outcome->status === SpecificationReconciliationStatus::Disabled
                || ($outcome->status === SpecificationReconciliationStatus::NotReady
                    && ($outcome->repairContext['recovery_action'] ?? null) === 'try_other_source')
                || $outcome->unavailableReason === 'empty_url') {
                return $outcome;
            }

            // Waiting for the OpenAI queue is not a failure of the card: try
            // again while the job's clock allows (request #51 ran beside
            // another search and twice gave up waiting, which then read as
            // the same failure repeated and ended the card).
            if ($outcome->unavailableReason === 'provider_busy') {
                continue;
            }

            // Rephrasing a not-ready verdict is not new evidence. Allow one
            // recovery with feedback, but do not buy another identical task
            // unless the evidence actually changed. Technical errors retain
            // their full structured details: the display summary can truncate
            // or group different field failures into the same message. Changes
            // to the candidate's wording alone are not evidence of progress.
            $signature = $outcome->status->value.'|'.$outcome->unavailableReason
                .($outcome->status === SpecificationReconciliationStatus::NotReady ? '' : '|'.json_encode(
                    $outcome->repairContext['validation_errors'] ?? $outcome->summary,
                ));
            // Any earlier attempt, not only the last: failures that take turns
            // (A, B, A, ...) are the same loop as one failure repeated.
            if (! $outcome->madeProgress && in_array($signature, $seenSignatures, true)) {
                return $outcome;
            }
            $seenSignatures[] = $signature;
            $source['_previous_assembly_feedback'] = [
                'status' => $outcome->status->value,
                'reason' => $outcome->unavailableReason,
                'summary' => $outcome->summary,
                'made_progress' => $outcome->madeProgress,
                'repair_context' => $outcome->repairContext,
                'recovery' => (new RecoveryContext('card_assembly',
                    $outcome->unavailableReason ?? $outcome->status->value,
                    $outcome->summary ?? 'Card assembly is not complete.',
                    ['FetchProductSourcePageText', 'SearchProductTextEvidence', 'return_corrected_card', 'adopt_source_offer'],
                    ['source_url' => $source['url'] ?? null, 'photos_preserved' => true],
                    ['repair_context' => $outcome->repairContext, 'made_progress' => $outcome->madeProgress],
                ))->toArray(),
            ];
        }

        return $outcome;
    }
}
