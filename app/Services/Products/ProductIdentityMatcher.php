<?php

namespace App\Services\Products;

use App\Models\ProductDraft;
use Illuminate\Support\Collection;
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

    /** A specification naming a limit rather than what is installed. */
    private const MEMORY_CEILING_KEY = '/\b(?:max|maximum|supported|expandable|upgrad\w*|slot|socket)/';

    private const MEMORY_CEILING_VALUE = '/\b(?:up to|maximum|expandable)\b/';

    /**
     * System memory, including its module names. "lpddr5x" and "ddr5" qualify;
     * "gddr6" deliberately does not - the leading g is exactly what separates a
     * graphics card's memory from the machine's own.
     */
    private const INSTALLED_MEMORY_CONTEXT = '/\b(?:ram|memory|unified|dimm|sodimm)\b|\b(?:lp)?ddr/';

    /** Memory that belongs to something other than the machine's own configuration. */
    private const OTHER_MEMORY_CONTEXT = '/\b(?:vram|graphics|video|gpu|geforce|rtx|gtx|radeon|ssd|hdd|nvme|emmc|storage|disk|drive|flash|microsd|sd)\b|\b(?:gddr|hbm)/';

    /** How far around a size its describing words are looked for, in characters. */
    private const MEMORY_CONTEXT_WINDOW = 16;

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
        return $this->matchesRequestedIdentifier($draft, $this->compact($this->sourceEvidence($source)));
    }

    /** @param array<string, mixed> $source */
    public function conflictsSource(ProductDraft $draft, array $source): bool
    {
        return $this->conflicts($draft, $this->sourceEvidence($source));
    }

    public function supportsEvidence(ProductDraft $draft, string $evidence): bool
    {
        return $this->matchesRequestedIdentifier($draft, $this->compact($evidence));
    }

    /**
     * A retailer's own title/URL word order routinely differs from the
     * requested model string's order - e.g. a real B&H Photo Video listing
     * for the exact Apple SKU MC7A4LL/A used the slug
     * "apple_mc7a4ll_a_15_macbook_air_m4" (size before the model name),
     * while the researched model is "MacBook Air 15 (M4)" - every
     * distinguishing part is genuinely present, just reordered, so the
     * plain concatenated-substring check below rejected a page that
     * actually was the exact product. Falling back to "every atomic part of
     * one operator-mentioned identifier appears somewhere" is still strict
     * (a page missing even one part still fails) but isn't fooled by
     * harmless reordering.
     */
    private function matchesRequestedIdentifier(ProductDraft $draft, string $compactEvidence): bool
    {
        if ($compactEvidence === '') {
            return false;
        }

        if (collect($this->requestedIdentifiers($draft))->contains(
            fn (string $identifier): bool => str_contains($compactEvidence, $identifier),
        )) {
            return true;
        }

        return collect($this->requestedIdentifierPartGroups($draft))->contains(
            fn (array $parts): bool => collect($parts)->every(
                fn (string $part): bool => str_contains($compactEvidence, $part),
            ),
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

                // identifierCandidates() also builds adjacent token pairs.
                // When the exact SKU is already present, that can produce a
                // synthetic value such as 16ah0097nr16 from the exact
                // 16-ah0097nr plus its first atom. It is derivable from the
                // exact match, not evidence of another variant. A genuinely
                // nearby SKU (for example 16ah0098nr) contains neither value
                // and is still inspected below.
                if ($exactIdentifiers->contains(
                    fn (string $exact): bool => str_contains($candidate, $exact)
                        || str_contains($exact, $candidate),
                )) {
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
    /**
     * Identifier matching deliberately only enforces what the operator typed,
     * because a researched part number is often a regional example and rejecting
     * on it would throw away legitimate regional cards. That leaves a gap the
     * fallback search walked straight into: a shop selling the same model in a
     * different configuration passes every identifier check, and its photos then
     * become the card's source while the card's own specifications say something
     * else (seen live: a 32 GB draft sourced from a 16 GB listing).
     *
     * Only installed memory is compared. It is the one configuration value
     * written unambiguously as "16 gb" in titles and slugs; storage appears as
     * "1-024-tb" and similar forms that cannot be parsed reliably, and a wrong
     * guess here would reject good sources. A conflict therefore needs the
     * evidence to state memory sizes explicitly and none of them to be the one
     * the draft committed to - evidence that says nothing about memory, which is
     * the common case, never conflicts.
     *
     * @param  array<string, mixed>  $source
     */
    public function conflictsConfiguration(ProductDraft $draft, array $source): bool
    {
        $expected = $this->memorySizes($this->draftMemoryValue($draft));

        if ($expected === []) {
            return false;
        }

        $found = $this->memorySizes($this->sourceEvidence($source));

        return $found !== [] && array_intersect($expected, $found) === [];
    }

    private function draftMemoryValue(ProductDraft $draft): string
    {
        return collect($draft->specifications ?? [])
            ->filter(fn (mixed $item): bool => is_array($item))
            ->filter(function (array $item): bool {
                $key = Str::lower((string) ($item['key'] ?? '').' '.($item['name'] ?? ''));
                $value = Str::lower((string) ($item['value'] ?? ''));

                // A ceiling is not a configuration. "max_memory_capacity",
                // "memory slots, expandable to 64 GB" and a value reading "up
                // to 64 GB" all say what the machine could take; reading any of
                // them as installed memory would let a 64 GB listing illustrate
                // a 32 GB card, which is the exact mistake this gate exists to
                // stop.
                return (str_contains($key, 'ram') || str_contains($key, 'memor') || str_contains($key, 'память'))
                    && preg_match(self::MEMORY_CEILING_KEY, $key) !== 1
                    && ! str_contains($key, 'video')
                    && ! str_contains($key, 'graphic')
                    && preg_match(self::MEMORY_CEILING_VALUE, $value) !== 1;
            })
            ->map(fn (array $item): string => (string) ($item['value'] ?? ''))
            ->implode(' ');
    }

    /**
     * A size only counts as installed memory when its own neighbourhood does
     * not name a different kind of memory. "8 GB GDDR6" on a graphics line and
     * "256 GB SSD" on a storage line are both plausible-looking powers of two,
     * and reading them as system memory would invent conflicts that do not
     * exist - or, worse, mask a real one. Each window stops at the neighbouring
     * size token, so one figure can never be described by the next figure's
     * words ("16 GB, 256 GB SSD" keeps the 16).
     *
     * @return array<int, int>
     */
    private function memorySizes(string $evidence): array
    {
        $text = str_replace(['-', '_'], ' ', Str::lower(Str::ascii(urldecode($evidence))));
        $found = preg_match_all('/(?<!\d)(\d{1,4})\s*(gb|tb)\b/', $text, $matches, PREG_OFFSET_CAPTURE);

        if (! $found) {
            return [];
        }

        $sizes = [];

        foreach ($matches[0] as $position => [$token, $offset]) {
            $size = (int) $matches[1][$position][0];

            // Terabyte tokens are only ever boundaries; sizes outside plausible
            // installed memory are something else on the page - a disk, a
            // bundled card, a promotion.
            if ($matches[2][$position][0] !== 'gb'
                || $size < 2 || $size > 256 || ($size & ($size - 1)) !== 0) {
                continue;
            }

            $end = $offset + strlen($token);
            $previous = $matches[0][$position - 1] ?? null;
            $previousEnd = $previous === null ? 0 : $previous[1] + strlen($previous[0]);
            $beforeStart = max($previousEnd, $offset - self::MEMORY_CONTEXT_WINDOW);
            $before = substr($text, $beforeStart, $offset - $beforeStart);
            $after = substr($text, $end, max(0, min(
                $matches[0][$position + 1][1] ?? strlen($text),
                $end + self::MEMORY_CONTEXT_WINDOW,
            ) - $end));

            if (preg_match(self::INSTALLED_MEMORY_CONTEXT, $after) === 1) {
                $sizes[] = $size;

                continue;
            }

            if (preg_match(self::OTHER_MEMORY_CONTEXT, $after) === 1
                || preg_match(self::OTHER_MEMORY_CONTEXT, $before) === 1) {
                continue;
            }

            $sizes[] = $size;
        }

        return array_values(array_unique($sizes));
    }

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
        $identifiers = $this->requestedRawValues($draft)
            ->flatMap(fn (string $value): array => $this->identifierCandidates($value))
            ->unique()
            ->values()
            ->all();

        $requestCompact = $this->requestCompact($draft);

        if ($requestCompact === null) {
            return $identifiers;
        }

        // Only an identifier actually supplied by the operator is mandatory.
        // A SKU inferred during research (often a regional example) must not
        // reject another exact regional card before its real HTML is checked.
        return collect($identifiers)
            ->filter(fn (string $identifier): bool => str_contains($requestCompact, $identifier))
            ->values()
            ->all();
    }

    /**
     * One group per operator-mentioned identifier value, each group being
     * every distinguishing atomic part of that one value (so "MacBook Air 15
     * (M4)" becomes ["macbook","air","15","m4"], not a single merged
     * string) - see matchesRequestedIdentifier() for why this needs to be
     * order-independent.
     *
     * @return array<int, array<int, string>>
     */
    private function requestedIdentifierPartGroups(ProductDraft $draft): array
    {
        $rawValues = $this->requestedRawValues($draft);
        $requestCompact = $this->requestCompact($draft);

        if ($requestCompact !== null) {
            $rawValues = $rawValues->filter(
                fn (string $value): bool => str_contains($requestCompact, $this->compact($value)),
            );
        }

        return $rawValues
            ->map(fn (string $value): array => $this->atomicParts($value))
            ->filter(fn (array $parts): bool => count($parts) >= 2)
            ->values()
            ->all();
    }

    /** @return Collection<int, string> */
    private function requestedRawValues(ProductDraft $draft): Collection
    {
        return collect([
            $draft->model,
            ...collect($draft->specifications ?? [])
                ->filter(fn (mixed $item): bool => is_array($item) && in_array($item['key'] ?? null, [
                    'model', 'sku', 'mpn', 'ean', 'upc', 'gtin',
                ], true))
                ->map(fn (array $item): string => (string) ($item['value'] ?? ''))
                ->all(),
        ])->filter(fn (mixed $value): bool => is_string($value) && trim($value) !== '');
    }

    private function requestCompact(ProductDraft $draft): ?string
    {
        if (! $draft->telegram_update_id && ! $draft->relationLoaded('telegramUpdate')) {
            return null;
        }

        $request = $draft->relationLoaded('telegramUpdate')
            ? $draft->telegramUpdate?->text
            : $draft->telegramUpdate()->value('text');

        if (! is_string($request) || trim($request) === '') {
            return null;
        }

        return $this->compact($request);
    }

    /**
     * Tokenizes into independent atomic pieces (a word or a run of digits),
     * without merging any of them into one order-dependent string.
     *
     * @return array<int, string>
     */
    private function atomicParts(string $value): array
    {
        $ascii = Str::lower(Str::ascii(urldecode($value)));
        preg_match_all('/[a-z0-9]+(?:[-_.][a-z0-9]+)*/', $ascii, $matches);

        return collect($matches[0] ?? [])
            ->flatMap(fn (string $part): array => [
                $this->compact($part),
                ...array_map(
                    fn (string $atom): string => $this->compact($atom),
                    preg_split('/[-_.]+/', $part) ?: [],
                ),
            ])
            ->filter(fn (string $part): bool => strlen($part) >= 2)
            ->unique()
            ->values()
            ->all();
    }

    /** @return array<int, string> */
    private function identifierCandidates(string $value): array
    {
        $parts = collect($this->atomicParts($value));
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
