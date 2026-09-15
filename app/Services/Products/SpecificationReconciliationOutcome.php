<?php

namespace App\Services\Products;

/**
 * The verdict of one attempt to reconcile a draft's whole card - title,
 * model, color, description, and specifications - against its actual, final
 * chosen photo source. See ProductSpecificationReconciler.
 *
 * isReconciled() is what ProductImageStorage and ProductDraftWorkflow::
 * approve() both act on: true only for Reconciled/AlreadyReconciled, false
 * for Disabled/Unavailable/NotReady. Disabled is deliberately NOT treated as
 * reconciled - "an unreconciled draft is not ready for publication" was
 * agreed without a carve-out for the checker being turned off.
 *
 * unavailableReason distinguishes WHY the AI was never actually consulted -
 * ProductImageStorage needs this specifically to tell "the whole shared
 * budget for this search is spent" (which must stop automatic retries the
 * same way cost_budget/time_budget already do everywhere else) from every
 * other reason (which is still bounded by the draft's own attempt counter,
 * not the search budget).
 */
final readonly class SpecificationReconciliationOutcome
{
    /** @param array<int, array{key: string, name: string, value: string}>|null $specifications */
    private function __construct(
        public SpecificationReconciliationStatus $status,
        public ?string $reconciledSourceUrl,
        public ?string $title,
        public ?string $model,
        public ?string $color,
        public ?string $description,
        public ?array $specifications,
        public ?string $summary,
        public ?string $unavailableReason,
        // True when this attempt itself added or changed a
        // ProductSourcePageEvidence row for this draft (the agent's own
        // FetchProductSourcePageText tool found something new) even though
        // the overall attempt did not reach reconciled - new evidence is
        // progress, and the caller's own attempt counter must not treat it
        // the same as a repeat of the exact same failed work.
        public bool $madeProgress = false,
    ) {}

    /** @param array<int, array{key: string, name: string, value: string}> $specifications */
    public static function reconciled(
        string $url,
        string $title,
        string $model,
        ?string $color,
        string $description,
        array $specifications,
        string $summary,
    ): self {
        return new self(
            SpecificationReconciliationStatus::Reconciled,
            $url, $title, $model, $color, $description, $specifications, $summary, null,
        );
    }

    public static function alreadyReconciled(?string $url): self
    {
        return new self(SpecificationReconciliationStatus::AlreadyReconciled, $url, null, null, null, null, null, null, null);
    }

    public static function disabled(?string $url): self
    {
        return new self(SpecificationReconciliationStatus::Disabled, $url, null, null, null, null, null, null, null);
    }

    /** @param 'budget_exhausted'|'empty_url'|'technical_error' $reason */
    public static function unavailable(string $reason, bool $madeProgress = false, ?string $detail = null): self
    {
        return new self(
            SpecificationReconciliationStatus::Unavailable,
            null, null, null, null, null, null, $detail, $reason, $madeProgress,
        );
    }

    /**
     * The evidence clearly names a different product, or - just as
     * terminal for the caller - an explicit operator requirement or the
     * specific product itself could not be confirmed from what is known.
     * Both mean the same thing to a caller deciding whether to publish:
     * not yet. summary is what tells a human which one it actually was.
     */
    public static function notReady(string $summary, bool $madeProgress = false): self
    {
        return new self(
            SpecificationReconciliationStatus::NotReady,
            null, null, null, null, null, null, $summary, null, $madeProgress,
        );
    }

    public function isReconciled(): bool
    {
        return in_array($this->status, [
            SpecificationReconciliationStatus::Reconciled,
            SpecificationReconciliationStatus::AlreadyReconciled,
        ], true);
    }

    public function isBudgetExhausted(): bool
    {
        return $this->unavailableReason === 'budget_exhausted';
    }
}
