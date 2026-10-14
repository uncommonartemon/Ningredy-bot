<?php

namespace App\Services\Products;

use App\Models\ProductDraft;

/** Shared evidence decision; family plausibility is never exact confirmation. */
final class ProductSourceIdentityGate
{
    public function __construct(
        private readonly ProductIdentityMatcher $matcher,
        private readonly ProductSourceIdentityJudge $judge,
    ) {}

    public function assess(ProductDraft $draft, array $source, ?int $runId): string
    {
        // A rejection from this very browser execution is not a new question
        // for the model. Never downgrade it because a later check lost the
        // scout's H1/title, ran out of budget or could not reach the provider.
        $observed = $source['_observed_identity'] ?? null;
        if (is_array($observed)
            && ($observed['verdict'] ?? null) === 'conflicting'
            && ($observed['product_draft_id'] ?? null) === $draft->id
            && ($observed['telegram_update_id'] ?? null) === $runId
            && ($observed['source']['url'] ?? null) === ($source['url'] ?? null)) {
            return 'conflicting';
        }

        if ($this->matcher->conflictsSource($draft, $source)) {
            return 'conflicting';
        }
        if ($this->matcher->confirmsExactIdentifier($draft, $source)) {
            return 'confirmed';
        }

        // The existing judge owns budget, caching and the distinction between
        // uncertain evidence and an unavailable check. Neither is a conflict.
        return $this->judge->judge($draft, $source, $runId);
    }
}
