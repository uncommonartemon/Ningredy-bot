<?php

namespace App\Services\Products;

use App\Models\ProductDraft;
use Illuminate\Support\Str;

/**
 * Single source of truth for "does this URL identify the exact requested
 * product?" - used both before a source page is opened and when Vision
 * evaluates loose image-search candidates.
 *
 * ProductImageStorage and ProductImageVisionVerifier used to each carry
 * their own slightly different version of this check (different token
 * pools, different exclusion lists, different thresholds), so the same
 * draft+URL pair could get a different answer depending on which class
 * asked.
 */
class ProductIdentityMatcher
{
    /**
     * Union of both former exclusion lists - brand names and generic
     * category/spec words that appear in almost every URL for a brand and
     * so carry no distinguishing signal on their own.
     */
    private const GENERIC_WORDS = [
        'asus', 'acer', 'apple', 'dell', 'hp', 'intel', 'amd', 'lenovo',
        'laptop', 'notebook', 'tablet', 'product', 'processor', 'graphics',
        'memory', 'storage', 'quiet', 'blue', 'card', 'edition',
    ];

    public function supports(ProductDraft $draft, string $sourceUrl): bool
    {
        if ($sourceUrl === '') {
            return false;
        }

        $source = Str::lower(Str::ascii(urldecode($sourceUrl)));
        $tokens = $this->tokens($draft);

        if ($tokens === []) {
            return false;
        }

        // Strong signal: a real model/SKU code (letters+digits mixed, or a
        // distinctive standalone number) is specific enough to trust alone.
        if (collect($tokens)->contains(
            fn (string $token): bool => $this->isStrongToken($token) && str_contains($source, $token)
        )) {
            return true;
        }

        // Weak signal: plain words only count when several independently
        // corroborate each other. Two was too easy to satisfy by accident -
        // an Apple color-swatch banner URL matched "imac" + "pink" alone and
        // got waved past Vision entirely without anyone ever looking at it.
        return collect($tokens)
            ->filter(fn (string $token): bool => str_contains($source, $token))
            ->count() >= 3;
    }

    /** @param array<string, mixed> $source */
    public function supportsSource(ProductDraft $draft, array $source): bool
    {
        $evidence = $this->sourceEvidence($source);
        $compactEvidence = $this->compact($evidence);

        return $compactEvidence !== '' && collect($this->requestedIdentifiers($draft))->contains(
            fn (string $identifier): bool => str_contains($compactEvidence, $identifier),
        );
    }

    /** @param array<string, mixed> $source */
    public function conflictsSource(ProductDraft $draft, array $source): bool
    {
        return $this->conflicts($draft, $this->sourceEvidence($source));
    }

    public function supportsEvidence(ProductDraft $draft, string $evidence): bool
    {
        $compactEvidence = $this->compact($evidence);

        return $compactEvidence !== '' && collect($this->requestedIdentifiers($draft))->contains(
            fn (string $identifier): bool => str_contains($compactEvidence, $identifier),
        );
    }

    public function requiresExactIdentifier(ProductDraft $draft): bool
    {
        return $this->requestedIdentifiers($draft) !== [];
    }

    /**
     * A generic URL is merely unconfirmed. Reject only a URL that contains a
     * very similar but different full model/part identifier.
     */
    public function conflicts(ProductDraft $draft, string $evidence): bool
    {
        $requested = $this->requestedIdentifiers($draft);

        if ($evidence === '' || $requested === []) {
            return false;
        }

        $compactEvidence = $this->compact($evidence);
        $evidenceIdentifiers = $this->identifierCandidates($evidence);
        $exactIdentifiers = collect($requested)
            ->filter(fn (string $identifier): bool => str_contains($compactEvidence, $identifier))
            ->values();

        foreach ($requested as $identifier) {
            $identifierIsPresent = str_contains($compactEvidence, $identifier);

            // Once one exact requested identifier is present, a missing weaker
            // fragment must not turn that exact page into a false conflict.
            // Close alternatives to the identifier that is present are still
            // inspected, so a mixed variant table remains ambiguous.
            if (! $identifierIsPresent && $exactIdentifiers->isNotEmpty()) {
                continue;
            }

            foreach ($evidenceIdentifiers as $candidate) {
                if ($candidate === $identifier || abs(strlen($candidate) - strlen($identifier)) > 3) {
                    continue;
                }

                $prefixLength = min(4, max(2, (int) floor(strlen($identifier) * 0.35)));
                $distanceLimit = max(1, min(3, (int) ceil(strlen($identifier) * 0.12)));

                if (substr($candidate, 0, $prefixLength) === substr($identifier, 0, $prefixLength)
                    && levenshtein($candidate, $identifier) <= $distanceLimit) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @param array<string, mixed> $source */
    private function sourceEvidence(array $source): string
    {
        return implode(' ', array_filter([
            is_string($source['url'] ?? null) ? $source['url'] : null,
            is_string($source['title'] ?? null) ? $source['title'] : null,
            is_string($source['_preflight_final_url'] ?? null) ? $source['_preflight_final_url'] : null,
            is_string($source['_preflight_identity_evidence'] ?? null) ? $source['_preflight_identity_evidence'] : null,
        ]));
    }

    private function isStrongToken(string $token): bool
    {
        if (strlen($token) < 4) {
            return false;
        }

        $hasLetter = preg_match('/[a-z]/', $token) === 1;
        $hasDigit = preg_match('/\d/', $token) === 1;

        return ($hasLetter && $hasDigit) || ctype_digit($token);
    }

    /** @return array<int, string> */
    private function requestedIdentifiers(ProductDraft $draft): array
    {
        $identifiers = collect([
            $draft->model,
            ...collect($draft->specifications ?? [])
                ->filter(fn (mixed $item): bool => is_array($item) && in_array($item['key'] ?? null, [
                    'model', 'sku', 'mpn', 'ean', 'upc', 'gtin',
                ], true))
                ->map(fn (array $item): string => (string) ($item['value'] ?? ''))
                ->all(),
        ])
            ->filter(fn (mixed $value): bool => is_string($value) && trim($value) !== '')
            ->flatMap(fn (string $value): array => $this->identifierCandidates($value))
            ->unique()
            ->values()
            ->all();

        if (! $draft->telegram_update_id && ! $draft->relationLoaded('telegramUpdate')) {
            return $identifiers;
        }

        $request = $draft->relationLoaded('telegramUpdate')
            ? $draft->telegramUpdate?->text
            : $draft->telegramUpdate()->value('text');

        if (! is_string($request) || trim($request) === '') {
            return $identifiers;
        }

        $requestCompact = $this->compact($request);

        // Only an identifier actually supplied by the operator is mandatory.
        // A SKU inferred during research (often a regional example) must not
        // reject another exact regional card before its real HTML is checked.
        return collect($identifiers)
            ->filter(fn (string $identifier): bool => str_contains($requestCompact, $identifier))
            ->values()
            ->all();
    }

    /** @return array<int, string> */
    private function identifierCandidates(string $value): array
    {
        $ascii = Str::lower(Str::ascii(urldecode($value)));
        preg_match_all('/[a-z0-9]+(?:[-_.][a-z0-9]+)*/', $ascii, $matches);
        $parts = collect($matches[0] ?? [])
            ->flatMap(fn (string $part): array => [
                $this->compact($part),
                ...array_map(
                    fn (string $atom): string => $this->compact($atom),
                    preg_split('/[-_.]+/', $part) ?: [],
                ),
            ])
            ->filter(fn (string $part): bool => strlen($part) >= 2)
            ->unique()
            ->values();
        $combined = [$this->compact($value)];

        for ($index = 0; $index < $parts->count() - 1; $index++) {
            $combined[] = $parts[$index].$parts[$index + 1];
        }

        return $parts
            ->merge($combined)
            ->filter(fn (string $candidate): bool => strlen($candidate) >= 4
                && preg_match('/[a-z]/', $candidate) === 1
                && preg_match('/\d/', $candidate) === 1)
            ->unique()
            ->values()
            ->all();
    }

    private function compact(string $value): string
    {
        return preg_replace('/[^a-z0-9]+/', '', Str::lower(Str::ascii(urldecode($value)))) ?: '';
    }

    /** @return array<int, string> */
    private function tokens(ProductDraft $draft): array
    {
        return collect([
            $draft->model,
            $draft->title,
            $draft->color,
            ...collect($draft->specifications ?? [])
                ->filter(fn (mixed $item): bool => is_array($item) && in_array($item['key'] ?? null, ['model', 'mpn', 'color'], true))
                ->map(fn (array $item): string => (string) ($item['value'] ?? ''))
                ->all(),
        ])
            ->flatMap(fn (mixed $value): array => preg_split('/[^a-z0-9]+/', Str::lower(Str::ascii((string) $value))) ?: [])
            ->filter(fn (string $token): bool => strlen($token) >= 3 && ! in_array($token, self::GENERIC_WORDS, true))
            ->unique()
            ->values()
            ->all();
    }
}
