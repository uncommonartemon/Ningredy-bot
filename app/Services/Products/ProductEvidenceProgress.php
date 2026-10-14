<?php

namespace App\Services\Products;

use App\Models\ProductDraft;
use App\Models\ProductSourcePageEvidence;
use Illuminate\Support\Str;

/** Observed sources/text, not the agent's changing interpretation of them. */
final class ProductEvidenceProgress
{
    /** @return array<string, true> Set of observations; ordering and row IDs are irrelevant. */
    public function snapshot(ProductDraft $draft): array
    {
        $observations = [];
        foreach ($draft->fresh()?->specification_evidence ?? [] as $fact) {
            if (! is_array($fact)) {
                continue;
            }
            $url = trim((string) ($fact['source_url'] ?? ''));
            $quote = Str::squish((string) ($fact['source_excerpt'] ?? ''));
            if ($url !== '' && $quote !== '') {
                // A new key/value/applicability label over the same citation
                // is not a new observation. Preserve all interpretations in
                // storage for the agent and conflict review, without paying
                // for endless recovery on their rewording (#202).
                $observations[$this->fingerprint(['citation', $url, $quote])] = true;
            }
        }

        foreach (ProductSourcePageEvidence::query()->where('product_draft_id', $draft->id)->get() as $page) {
            $observations[$this->fingerprint([
                'page', $page->url, $page->final_url, $page->open_selector,
                $page->specification_text, $page->identity_evidence, $page->click_outcome,
            ])] = true;
        }

        return $observations;
    }

    /** @param array<string, true> $before
     * @param  array<string, true>  $after
     */
    public function hasNewObservations(array $before, array $after): bool
    {
        // Removing/reordering old records is not discovery either.
        return array_diff_key($after, $before) !== [];
    }

    private function fingerprint(array $observation): string
    {
        return hash('sha256', json_encode($observation, JSON_THROW_ON_ERROR));
    }
}
