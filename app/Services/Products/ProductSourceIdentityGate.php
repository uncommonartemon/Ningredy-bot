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

    /** Gallery eligibility is independent of the configuration used for technical facts. */
    public function assessPhotos(ProductDraft $draft, array $source, ?int $runId): array
    {
        $observed = $source['_observed_identity'] ?? null;
        if (is_array($observed) && ($observed['purpose'] ?? null) === 'photos'
            && ($observed['product_draft_id'] ?? null) === $draft->id
            && ($observed['telegram_update_id'] ?? null) === $runId
            && ($observed['source']['url'] ?? null) === ($source['url'] ?? null)
            && ($observed['verdict'] ?? null) === 'conflicting') {
            return ['verdict' => 'conflicting', 'may_inspect_gallery' => false,
                'reason' => 'Observed gallery belongs to an incompatible product.', 'differences' => [], 'evidence' => []];
        }

        $cached = $source['_photo_applicability'] ?? null;
        if (is_array($cached) && ProductPhotoApplicability::isAccepted($draft, $source, $cached)) {
            return $cached;
        }

        // Preserve the inexpensive, existing exact-source route. Contradictory codes or
        // memory are referred to the photo judge; neither is a blanket photo rejection.
        if (! $this->matcher->conflictsSource($draft, $source)
            && ! $this->matcher->conflictsMemoryConfiguration($draft, $source)
            && $this->matcher->confirmsExactIdentifier($draft, $source)) {
            return ProductPhotoApplicability::bind($draft, $source, [
                'verdict' => 'exact', 'may_inspect_gallery' => true,
                'reason' => 'Exact product identifier observed; image content and colour still require verification.',
                'source_model' => $draft->model, 'differences' => [],
                'evidence' => ['Exact identifier in the observed product page.'],
            ]);
        }

        return app(ProductPhotoApplicability::class)->assess($draft, $source, $runId);
    }
}
