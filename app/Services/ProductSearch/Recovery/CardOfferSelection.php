<?php

namespace App\Services\ProductSearch\Recovery;

use App\Models\ProductDraft;
use App\Services\Ai\ProductSearchCancellation;
use App\Services\Photos\ImageUrls;
use App\Services\Products\ProductIdentityMatcher;
use App\Services\Products\ProductPhotoApplicability;
use App\Services\Products\ProductRequestContext;
use App\Services\Products\ProductSourceIdentityJudge;

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

        $differences = $request->forAgent()['request_differences'] ?? null;

        return [
            'original_operator_request' => $request->originalRequest(),
            ...($differences === null ? [] : ['request_differences' => $differences]),
            // Full facts and page text already have their own payload fields.
            // Keep only the choice here, rather than paying for a second copy.
            'research_offer' => array_intersect_key($request->selectedProduct() ?? [],
                array_flip(['model', 'identifiers', 'color', 'condition'])),
            'gallery_source' => array_intersect_key($source, array_flip(['url', 'title'])),
            'photo_applicability' => $source['_photo_applicability'] ?? null,
            'available_actions' => ($source['_photo_applicability']['verdict'] ?? null) === 'compatible'
                ? ['retain_selected_offer'] : ['retain_selected_offer', 'adopt_source_offer'],
        ];
    }

    public function validate(ProductDraft $draft, array $source, array $card, array $observedPages, ?int $updateId): ?array
    {
        $selection = $card['offer_selection'] ?? null;
        $url = (string) ($source['url'] ?? '');
        $photoAssessment = $source['_photo_applicability'] ?? [];
        if (($photoAssessment['verdict'] ?? null) === 'compatible') {
            if (! ProductPhotoApplicability::isAccepted($draft, $source, $photoAssessment)) {
                $this->reject('The photo applicability decision is stale or belongs to another source/target. Reassess photos before using this variant.');
            }
            if (($selection['action'] ?? null) === 'adopt_source_offer') {
                $this->reject('A compatible gallery illustrates the selected product; it does not authorize adopting the gallery offer or its configuration.');
            }
            if ($this->identifier((string) ($card['model'] ?? '')) !== $this->identifier((string) $draft->model)) {
                $this->reject('Keep the selected model when using photos of a compatible variant.', ['paths' => ['model']]);
            }
            $proposedTarget = clone $draft;
            $proposedTarget->fill(array_intersect_key($card, array_flip(['model', 'color'])));
            $proposedTarget->specifications = array_values(array_filter($card['specifications'] ?? [],
                fn (array $fact): bool => ($fact['evidence'] ?? null) !== 'dropped'));
            if (! ProductPhotoApplicability::matchesTarget($proposedTarget, $photoAssessment)) {
                $originals = collect($draft->specifications ?? [])->keyBy('key');
                $changedKeys = collect($card['specifications'])->filter(fn (array $fact): bool => in_array($fact['key'], $photoAssessment['target_physical_keys'] ?? [], true)
                    && (($fact['evidence'] ?? null) === 'dropped'
                        || ($fact['value'] ?? null) !== ($originals[$fact['key']]['value'] ?? null)
                        || ($fact['unit'] ?? null) !== ($originals[$fact['key']]['unit'] ?? null)))
                    ->pluck('key')->values()->all();
                $this->reject('The proposed card changes the colour or known physical characteristics covered by the photo decision. Preserve them or request a new photo applicability check.',
                    ['specification_keys' => $changedKeys, 'paths' => $draft->color !== ($card['color'] ?? null) ? ['color'] : [],
                        'requires_photo_reassessment' => true]);
            }
            $this->retainConfigurationFacts($draft, $source, $card['specifications'], $observedPages);
        }
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

    private function reject(string $message, array $targets = []): never
    {
        throw CardRepairValidationException::forTargets($message, $targets);
    }

    /** A gallery-only offer cannot supply a changed or newly introduced configuration value. */
    private function retainConfigurationFacts(ProductDraft $draft, array $source, array $facts, array $observedPages): void
    {
        $pageKey = static fn (string $url): string => rtrim(explode('#', ImageUrls::normalizeCandidateUrl($url), 2)[0], '/');
        $galleryUrls = [$pageKey((string) $source['url'])];
        foreach ($observedPages as $page) {
            $urls = array_values(array_filter(array_map($pageKey, [
                (string) ($page['url'] ?? ''), (string) ($page['final_url'] ?? ''),
            ])));
            if (array_intersect($galleryUrls, $urls) !== []) {
                $galleryUrls = array_values(array_unique([...$galleryUrls, ...$urls]));
            }
        }
        $originals = collect($draft->specifications ?? [])->keyBy('key');
        $valueKey = static fn (mixed $value): string => mb_strtolower(trim(preg_replace('/\s+/u', ' ', (string) $value) ?? ''));
        foreach ($facts as $fact) {
            if (($fact['evidence'] ?? null) === 'dropped'
                || ($fact['source_kind'] ?? 'web') !== 'web'
                || ! in_array($pageKey((string) ($fact['source_url'] ?? '')), $galleryUrls, true)) {
                continue;
            }
            $original = $originals->get($fact['key']);
            if ($original !== null && $valueKey($original['value'] ?? '') === $valueKey($fact['value'])
                && $valueKey($original['unit'] ?? '') === $valueKey($fact['unit'] ?? '')) {
                continue;
            }
            $this->reject('The compatible gallery cannot supply the new or changed configuration fact '.$fact['key']
                .'. Keep the selected product facts; cite observed evidence for its own configuration from a separate text source or factory label. Shared unchanged facts remain allowed.',
                ['specification_keys' => [$fact['key']]]);
        }
    }

    private function identifier(string $value): string
    {
        return preg_replace('/[^\p{L}\p{N}]/u', '', mb_strtolower($value)) ?? '';
    }
}
