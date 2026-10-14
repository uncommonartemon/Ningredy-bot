<?php

namespace App\Services\ProductSearch\Recovery;

use App\Models\ProductDraft;
use App\Services\Ai\ProductSearchCancellation;
use App\Services\Photos\ImageUrls;
use App\Services\Products\ProductIdentityMatcher;
use App\Services\Products\ProductRequestContext;
use App\Services\Products\ProductSourceIdentityJudge;
use Illuminate\Validation\ValidationException;

/**
 * An explicit, checked transition between research's offer and the gallery's
 * offer. This never mutates a draft, changes photos or grants another budget.
 * The entire candidate card is committed by the normal caller only on success.
 */
final class CardOfferSelection
{
    public function __construct(
        private readonly ProductIdentityMatcher $identity,
        private readonly ProductSourceIdentityJudge $judge,
    ) {}

    public function context(ProductDraft $draft, array $source): array
    {
        $request = ProductRequestContext::forDraft($draft);

        return [
            'original_operator_request' => $request->originalRequest(),
            // Full facts and page text already have their own payload fields.
            // Keep only the choice here, rather than paying for a second copy.
            'research_offer' => array_intersect_key($request->selectedProduct() ?? [],
                array_flip(['model', 'identifiers', 'color', 'condition'])),
            'gallery_source' => array_intersect_key($source, array_flip(['url', 'title'])),
            'available_actions' => ['retain_selected_offer', 'adopt_source_offer'],
        ];
    }

    public function validate(ProductDraft $draft, array $source, array $card, array $observedPages, ?int $updateId): ?array
    {
        $selection = $card['offer_selection'] ?? null;
        $url = (string) ($source['url'] ?? '');
        if ($selection !== null && ImageUrls::normalizeCandidateUrl((string) ($selection['source_url'] ?? '')) !== ImageUrls::normalizeCandidateUrl($url)) {
            $this->reject('Offer selection must keep the selected gallery source URL. Changing the gallery requires photo search, not a card-only update.');
        }
        if (($selection['action'] ?? null) !== 'adopt_source_offer') {
            $originalCodes = collect($draft->specifications ?? [])->filter(fn ($fact) => is_array($fact)
                && in_array($fact['key'] ?? null, ['sku', 'mpn', 'ean', 'upc', 'gtin'], true))->keyBy('key');
            foreach ($card['specifications'] as $fact) {
                $old = $originalCodes->get($fact['key']);
                if ($old !== null && ($fact['evidence'] === 'dropped'
                    || $this->identifier((string) $old['value']) !== $this->identifier($fact['value']))) {
                    $this->reject('A commercial identifier changed: explicitly select adopt_source_offer and rebuild the whole card, or retain the original offer.');
                }
            }

            return $selection;
        }

        app(ProductSearchCancellation::class)->throwIfCancelled($updateId);
        if ($draft->fresh()?->status !== 'pending_review') {
            $this->reject('The draft is no longer pending review; offer selection is cancelled.');
        }
        if (ProductRequestContext::forDraft($draft)->originalRequest() === null) {
            $this->reject('The original operator request is unavailable; do not replace research\'s selected offer without its requirements.');
        }

        // Only evidence captured at the selected URL (including a recorded
        // redirect to it). Text from an unrelated offer cannot authorize it.
        $sourceText = collect($observedPages)->filter(function (array $page) use ($url): bool {
            return collect([$page['url'] ?? '', $page['final_url'] ?? ''])->contains(
                fn ($candidate): bool => is_string($candidate) && $candidate !== ''
                    && ImageUrls::normalizeCandidateUrl($candidate) === ImageUrls::normalizeCandidateUrl($url),
            );
        })->flatMap(fn (array $page): array => [
            $page['identity_evidence'] ?? '', $page['specification_text'] ?? '',
        ])->filter(fn ($text): bool => is_string($text) && trim($text) !== '')->implode("\n");
        $observedSource = [
            'url' => $url,
            'title' => $source['title'] ?? null,
            '_preflight_identity_evidence' => $sourceText,
        ];
        $proposal = clone $draft;
        $proposal->fill(array_intersect_key($card, array_flip(['title', 'model', 'color', 'description', 'specifications'])));
        $proposal->specifications = array_values(array_filter($card['specifications'], fn ($fact) => $fact['evidence'] !== 'dropped'));

        $requiredCodes = $this->identity->missingOperatorCodes($draft, '');
        $returnedFacts = collect($card['specifications'])->keyBy('key');
        foreach ($draft->specifications ?? [] as $fact) {
            if (! is_array($fact) || ! in_array($fact['key'] ?? null, ['sku', 'mpn', 'ean', 'upc', 'gtin'], true)
                || ! in_array($this->identifier((string) ($fact['value'] ?? '')), $requiredCodes, true)) {
                continue;
            }
            $returned = $returnedFacts->get($fact['key']);
            if ($returned === null || $returned['evidence'] === 'dropped'
                || $this->identifier($returned['value']) !== $this->identifier((string) $fact['value'])) {
                $this->reject('An explicit operator commercial identifier cannot be changed or dropped, even when the old code also appears elsewhere on the page or in the model name.');
            }
        }

        // A model cannot authorize replacing a code actually supplied by the
        // operator. Check both the proposed identity and the source, not the
        // old SKU appearing incidentally in an explanation of the change.
        $proposedIdentity = implode(' ', [
            $card['model'],
            ...collect($card['specifications'])->filter(fn ($fact) => $fact['evidence'] !== 'dropped'
                && in_array($fact['key'], ['model', 'sku', 'mpn', 'ean', 'upc', 'gtin'], true))
                ->pluck('value')->all(),
        ]);
        if ($this->identity->missingOperatorCodes($draft, $sourceText) !== []
            || $this->identity->missingOperatorCodes($draft, $proposedIdentity) !== []) {
            $this->reject('The proposed source offer does not preserve an explicit operator identifier. Choose another source; do not replace the requested code.');
        }
        if ($sourceText === '' || ! $this->identity->confirmsExactIdentifier($proposal, $observedSource)) {
            $this->reject('The selected source has not evidenced the proposed offer identity. Read that source before adopting it.');
        }

        // Codes alone cannot settle RAM, keyboard, colour or other semantic
        // requirements. Reuse the existing judge/cache only for this explicit
        // transition; ordinary assembly does not pay an additional judge.
        $verdict = $this->judge->judgeForAssembly($draft, $observedSource, $updateId, [
            ...array_intersect_key($card, array_flip(['title', 'model', 'color', 'description'])),
            'specifications' => $proposal->specifications,
        ]);
        app(ProductSearchCancellation::class)->throwIfCancelled($updateId);
        if ($verdict === 'unavailable') {
            throw new OfferSelectionUnavailable('Source offer requirement check is unavailable. Retain the assembled candidate and retry its check, not the whole assembly.');
        }
        if ($verdict !== 'confirmed') {
            $this->reject('Source offer adoption did not confirm the original operator requirements: '.$verdict.'.');
        }

        return $selection;
    }

    private function reject(string $message): never
    {
        throw ValidationException::withMessages(['offer_selection' => [$message]]);
    }

    private function identifier(string $value): string
    {
        return preg_replace('/[^\p{L}\p{N}]/u', '', mb_strtolower($value)) ?? '';
    }
}
