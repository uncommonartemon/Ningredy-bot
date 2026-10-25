<?php

namespace App\Services\Products;

use App\Models\ProductDraft;
use App\Services\Ai\AiSettings;
use App\Services\Ai\ProductSearchCancellation;
use App\Services\Ai\ProductSearchTimeBudget;
use App\Services\ProductSearch\Recovery\FailureDiagnosis;
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

    // Bump when recovery/response contracts change so a repaired implementation
    // can retry failures persisted by an older contract.
    private const int REPAIR_STATE_VERSION = 1;

    private const int REPAIR_CONTRACT_VERSION = 2;

    public function __construct(
        private readonly ProductSpecificationReconciler $reconciler,
        private readonly ProductSearchTimeBudget $timeBudget,
        private readonly ProductSourceAttemptRecorder $attempts,
        private readonly AiSettings $settings,
    ) {}

    public function complete(ProductDraft $draft, array $source, ?int $updateId, bool $allowFallbackModel = false): SpecificationReconciliationOutcome
    {
        $seenSignatures = [];

        return $this->completeWithHistory($draft, $source, $updateId, $seenSignatures, $allowFallbackModel);
    }

    /**
     * Resume only facts for an already retained gallery. The caller persists
     * state beside that gallery, including across worker jobs; this method
     * never searches, replaces or writes photos, and never applies card facts.
     * Reconcile's own text tools perform any targeted evidence collection.
     */
    public function resumeFacts(ProductDraft $draft, array $source, ?int $updateId, array &$state): SpecificationReconciliationOutcome
    {
        $updateId ??= $draft->telegram_update_id;
        app(ProductSearchCancellation::class)->throwIfCancelled($updateId);
        $request = ProductRequestContext::forDraft($draft)->forAgent();
        // A newly inserted model may not yet expose the database default.
        // Refreshing it in another job must not look like a changed product.
        $request['selected_product']['condition'] ??= 'new';
        $identity = hash('sha256', json_encode([
            self::REPAIR_STATE_VERSION, $draft->id, $request,
            $source['url'] ?? null,
            $source['_photo_applicability']['verdict'] ?? null,
            $source['_photo_applicability']['source_model'] ?? null,
            $this->settings->modelFor('product_specification_reconciliation'), $this->settings->cardAssemblyFallbackModel(),
        ], JSON_THROW_ON_ERROR));
        if (($state['identity'] ?? null) !== $identity) {
            $state = ['identity' => $identity, 'seen_signatures' => []];
        }
        $observations = array_keys(app(ProductEvidenceProgress::class)->snapshot($draft));
        sort($observations);
        $evidence = hash('sha256', json_encode($observations, JSON_THROW_ON_ERROR));
        $previous = $state['last_failure'] ?? null;
        // A supervisor step (new guidance or a widened repair scope) is changed
        // input even when no new web quotation was saved.
        $supervisorGeneration = (int) ($state['supervisor']['generation'] ?? 0);
        $supervisorPending = $supervisorGeneration !== (int) ($state['supervisor_attempted'] ?? 0);
        if (! $supervisorPending
            && ($state['contract_version'] ?? 1) === self::REPAIR_CONTRACT_VERSION && is_array($previous)
            && (($previous['repair_context']['failure_kind'] ?? null) === 'response_format'
                || ($state['evidence'] ?? null) === $evidence)) {
            return ($previous['status'] ?? null) === SpecificationReconciliationStatus::NotReady->value
                ? SpecificationReconciliationOutcome::notReady($previous['summary'] ?? 'Facts remain unresolved.', false, $previous['repair_context'] ?? null)
                : SpecificationReconciliationOutcome::unavailable($previous['reason'], false, $previous['summary'] ?? null, $previous['repair_context'] ?? null);
        }

        if (is_array($state['previous_feedback'] ?? null)) {
            $source['_previous_assembly_feedback'] = $state['previous_feedback'];
        }
        if ($supervisorPending && is_string($state['supervisor']['guidance'] ?? null) && $state['supervisor']['guidance'] !== '') {
            $source['_previous_assembly_feedback']['supervisor_guidance'] = $state['supervisor']['guidance'];
        }
        $seenSignatures = is_array($state['seen_signatures'] ?? null) ? $state['seen_signatures'] : [];
        // The stronger (20x pricier) model is a last resort the recovery
        // supervisor asks for explicitly, never an automatic second pass.
        $outcome = $this->completeWithHistory($draft, $source, $updateId, $seenSignatures,
            $supervisorPending && (bool) ($state['supervisor']['stronger_model'] ?? false));
        $state['supervisor_attempted'] = $supervisorGeneration;
        $state['contract_version'] = self::REPAIR_CONTRACT_VERSION;
        $state['seen_signatures'] = array_values(array_unique($seenSignatures));
        $state['previous_feedback'] = $source['_previous_assembly_feedback'] ?? null;
        $observations = array_keys(app(ProductEvidenceProgress::class)->snapshot($draft));
        sort($observations);
        $state['evidence'] = hash('sha256', json_encode($observations, JSON_THROW_ON_ERROR));
        // A timeout/provider interruption can resume. Identical exhausted
        // factual or response repairs need changed evidence/input/method first.
        $state['last_failure'] = $outcome->status === SpecificationReconciliationStatus::NotReady
            || $outcome->unavailableReason === 'invalid_response' ? [
                'status' => $outcome->status->value, 'reason' => $outcome->unavailableReason,
                'summary' => $outcome->summary, 'repair_context' => $outcome->repairContext,
            ] : null;

        return $outcome;
    }

    private function completeWithHistory(ProductDraft $draft, array &$source, ?int $updateId, array &$seenSignatures, bool $allowFallbackModel = false): SpecificationReconciliationOutcome
    {
        $updateId ??= $draft->telegram_update_id;
        $outcome = $this->assemble($draft, $source, $updateId, null, $seenSignatures);

        // The assembly model could not finish the card: the fallback model
        // takes over from its last attempt (request #51) - but only when the
        // caller allows it. Live data: it cost 19% of all spend as an
        // automatic second pass (operator decision 2026-10-19).
        $fallback = $this->settings->cardAssemblyFallbackModel();
        if (! $allowFallbackModel || $fallback === null || $fallback === $this->settings->modelFor('product_specification_reconciliation')
            || $outcome->isReconciled() || $outcome->isBudgetExhausted()
            || $outcome->status === SpecificationReconciliationStatus::Disabled
            || ($outcome->repairContext['recovery_action'] ?? null) === 'try_other_source'
            || in_array($outcome->unavailableReason, ['empty_url', 'identity_check_unavailable'], true)) {
            return $outcome;
        }

        return $this->assemble($draft, $source, $updateId, $fallback, $seenSignatures);
    }

    /** @param array<string, mixed> $source carries the last attempt's feedback on to the next pass */
    private function assemble(ProductDraft $draft, array &$source, ?int $updateId, ?string $model, array &$seenSignatures): SpecificationReconciliationOutcome
    {
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
                    'repair_context' => $outcome->repairContext, 'offer_selection' => $outcome->offerSelection,
                    ...($outcome->isReconciled() ? [] : ['failure_diagnosis' => FailureDiagnosis::make(
                        'card_assembly', $outcome->repairContext['failure_kind'] ?? $outcome->unavailableReason ?? $outcome->status->value,
                        serverReason: ! empty($outcome->repairContext['validation_errors']) ? json_encode($outcome->repairContext['validation_errors'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : $outcome->unavailableReason,
                        agentComment: $outcome->status === SpecificationReconciliationStatus::NotReady ? $outcome->summary : null,
                        blockedBy: array_column($outcome->repairContext['unresolved_fields'] ?? [], 'key'),
                        suggestedNextAction: $outcome->repairContext['recovery_action'] ?? null,
                        retained: ['source_url' => $source['url'] ?? null, 'photos_preserved' => true],
                    )])],
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
            $unresolved = collect($outcome->repairContext['unresolved_fields'] ?? [])
                ->filter(fn ($field): bool => is_array($field) && isset($field['key'], $field['status']))
                ->map(fn (array $field): string => $field['key'].':'.$field['status'])
                ->unique()->sort()->values()->all();
            $signature = $outcome->status->value.'|'.$outcome->unavailableReason
                .($outcome->status === SpecificationReconciliationStatus::NotReady ? '' : '|'.json_encode(
                    $outcome->repairContext['validation_errors'] ?? $outcome->summary,
                )).($unresolved === [] ? '' : '|'.json_encode($unresolved));
            // Any earlier attempt, not only the last: failures that take turns
            // (A, B, A, ...) are the same loop as one failure repeated.
            // New website quotations cannot repair an empty/truncated JSON
            // response. Keep the same failure history when the fallback model
            // takes over: it gets its first attempt, not a fresh identical loop.
            // Progress is a change in what is wrong (PROJECT_STRATEGY: repairs go on
            // while each attempt changes the errors). New observations that leave
            // the very same errors are not progress: live #180 re-read a page four
            // times and failed four times on the same color_gamut quotation, each
            // fetch excusing the next identical attempt.
            $relevantProgress = $outcome->madeProgress
                && ($outcome->repairContext['failure_kind'] ?? null) !== 'response_format'
                // With explicit blockers, another quote is only useful if
                // it changes what remains unresolved. Old responses without
                // that structure retain the evidence-based compatibility path.
                && $unresolved === []
                && ! in_array($signature, $seenSignatures, true);
            $repeatWithoutProgress = ! $relevantProgress && in_array($signature, $seenSignatures, true);
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
            if ($repeatWithoutProgress) {
                return $outcome;
            }
        }

        return $outcome;
    }
}
