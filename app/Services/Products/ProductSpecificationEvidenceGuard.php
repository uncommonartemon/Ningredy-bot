<?php

namespace App\Services\Products;

/**
 * Checks provenance and response consistency, not semantic truth. Saved research
 * excerpts are reused as observations; this does not independently fetch them
 * or prove that a quoted passage entails the proposed product value.
 */
class ProductSpecificationEvidenceGuard
{
    // A sign after a letter or digit is an identifier separator (X-123,
    // i7-13620H), not the sign of a standalone numeric value.
    private const NUMBER_PATTERN = '(?:(?<![\p{L}\p{N}])[+\-\x{2212}])?(?:\p{N}+(?:[.,]\p{N}+)*|[.,]\p{N}+)';

    /** Differences for the agent to resolve; formatting differences are not automatic conflicts. */
    public function differingObservations(array $facts): array
    {
        return collect($facts)->filter(fn ($fact) => $this->isCitedFact($fact))
            ->groupBy('key')->filter(fn ($group) => $group->map(fn ($fact) => $this->text($fact['value']))->unique()->count() > 1)
            ->map(fn ($group) => $group->values()->all())->all();
    }

    /** Repair only a status label, then run every provenance/conflict check. */
    public function normalizeAndValidate(array $input, array $result, array $observedFacts, array $observedPages, array $inputEvidence = [], ?string $operatorRequest = null): array
    {
        $originals = collect($input)->keyBy('key');
        // A saved page is an observation too: live #450 re-cited the same RAM type
        // from the Icecat datasheet and lost the card to "corrected but unchanged".
        // validate() below still requires the quote to be on that page.
        $pageUrls = collect($observedPages)->flatMap(fn ($page): array => is_array($page)
            ? array_filter([$page['url'] ?? null, $page['final_url'] ?? null], 'is_string') : [])->all();
        foreach ($result as $index => $item) {
            $old = $originals->get($item['key']);
            // The reverse slip: a changed value with its own explanation labelled
            // "confirmed" (live #451, MPN). It becomes the correction it is, and
            // every correction requirement in validate() still applies to it.
            if (($item['evidence'] ?? null) === 'confirmed' && $old !== null
                && $this->text((string) ($old['value'] ?? '')) !== $this->text((string) ($item['value'] ?? ''))
                && trim((string) ($item['reason'] ?? '')) !== '' && $this->quoteHoldsValue($item)) {
                $result[$index]['evidence'] = 'corrected';

                continue;
            }
            if (($item['evidence'] ?? null) !== 'corrected' || $old === null
                || ! $this->sameActualValue($old, $item)
                || trim((string) ($item['reason'] ?? '')) === '') {
                continue;
            }

            // Do not reinterpret a claimed correction as confirmation merely
            // because the old value survived. A cited observation must retain
            // this very value, and the chosen quote must literally contain it.
            // This is lexical metadata repair, not semantic fact verification.
            $label = ($item['source_kind'] ?? 'web') === 'photo_label';
            $support = collect($label ? $inputEvidence : [...$input, ...$observedFacts])->contains(
                fn ($fact) => is_array($fact) && ($fact['key'] ?? null) === $item['key']
                    && $this->sameActualValue($fact, $item)
                    && ($label
                        ? ($fact['source_kind'] ?? null) === 'photo_label'
                            && filled($item['source_ref'] ?? null) && ($fact['source_ref'] ?? null) === $item['source_ref']
                        : $this->isCitedFact($fact) && ($fact['source_kind'] ?? 'web') === 'web'
                            && $fact['source_url'] === ($item['source_url'] ?? null)),
            ) || (! $label && in_array($item['source_url'] ?? null, $pageUrls, true));
            if ($support && $this->quoteHoldsValue($item)) {
                $result[$index]['evidence'] = 'confirmed';
            }
        }

        $result = $this->listSourcesNamedInReason($input, $result, $observedFacts);
        $this->validate($input, $result, $observedFacts, $observedPages, $inputEvidence, $operatorRequest);

        return $result;
    }

    /**
     * A disagreement is reviewed when the reason speaks of the other source - its
     * site or the value it gave. Then its URL is copied into considered_source_urls
     * for the agent: in 79 of 124 rejected assemblies over 30 days the reason was
     * written and only that list was short. A source the reason never mentions
     * stays unreviewed, and validate() refuses it as before.
     */
    private function listSourcesNamedInReason(array $input, array $result, array $observedFacts): array
    {
        $facts = collect([...$input, ...$observedFacts])->filter(fn ($fact) => $this->isCitedFact($fact));
        foreach ($result as $index => $item) {
            $reason = $this->text((string) ($item['reason'] ?? ''));
            if (! is_array($item) || $reason === '') {
                continue;
            }
            $named = $facts->where('key', $item['key'] ?? null)
                ->filter(function (array $fact) use ($reason): bool {
                    $host = ProductSourcePriority::host((string) $fact['source_url']);
                    $value = $this->text((string) ($fact['value'] ?? ''));

                    // A whole value, not a fragment: "no" must not be found in "not".
                    return ($host !== '' && str_contains($reason, $this->text($host)))
                        || (mb_strlen($value) >= 3
                            && preg_match('/(?<![\p{L}\p{N}])'.preg_quote($value, '/').'(?![\p{L}\p{N}])/u', $reason) === 1);
                })
                ->pluck('source_url')->all();
            if ($named !== []) {
                $result[$index]['considered_source_urls'] = array_values(array_unique([
                    ...(array) ($item['considered_source_urls'] ?? []), ...$named,
                ]));
            }
        }

        return $result;
    }

    /** The chosen quote literally contains the whole value (and unit), not a piece of a larger number. */
    private function quoteHoldsValue(array $item): bool
    {
        $literal = $this->literalText((string) ($item['value'] ?? ''));
        $unit = $this->literalText((string) ($item['unit'] ?? ''));
        if ($unit !== '' && ! str_ends_with($literal, ' '.$unit)) {
            $literal .= ' '.$unit;
        }
        $quote = $this->literalText((string) ($item['source_excerpt'] ?? ''));

        return $literal !== '' && $this->containsWholeNumbers($quote, $literal)
            && preg_match('/(?<![\p{L}\p{N}])'.preg_quote($literal, '/').'(?![\p{L}\p{N}])/u', $quote) === 1;
    }

    private function sameActualValue(array $left, array $right): bool
    {
        return is_string($left['value'] ?? null) && is_string($right['value'] ?? null)
            && $this->literalText($left['value']) === $this->literalText($right['value'])
            && $this->literalText((string) ($left['unit'] ?? '')) === $this->literalText((string) ($right['unit'] ?? ''));
    }

    /** Preserve case (GB and Gb), numbers, punctuation and unit spelling. */
    private function literalText(string $text): string
    {
        return trim(preg_replace('/[\s\p{Z}]+/u', ' ', html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? $text);
    }

    public function validate(array $input, array $result, array $observedFacts, array $observedPages, array $inputEvidence = [], ?string $operatorRequest = null): void
    {
        $originals = collect($input)->keyBy('key');
        $facts = collect([...$input, ...$observedFacts])->filter(fn ($fact) => $this->isCitedFact($fact));
        // This is an intake confidence policy, not an independent verification
        // of the physical label. Never promote handwriting or legacy OCR to it.
        $labels = collect($inputEvidence)->filter(fn ($fact) => is_array($fact)
            && ($fact['source_kind'] ?? null) === 'photo_label'
            && is_numeric($fact['confidence'] ?? null) && $fact['confidence'] >= 0.8
            && empty($fact['uncertain_characters'])
            && is_string($fact['key'] ?? null) && is_string($fact['value'] ?? null)
            && filled($fact['source_ref'] ?? null) && filled($fact['source_excerpt'] ?? null));
        $texts = [];
        foreach ($facts as $fact) {
            $texts[$fact['source_url']][] = $this->text($fact['source_excerpt']);
        }
        foreach ($observedPages as $page) {
            foreach (array_unique(array_filter([$page['url'] ?? null, $page['final_url'] ?? null])) as $url) {
                foreach (['specification_text', 'identity_evidence'] as $field) {
                    if (is_string($page[$field] ?? null) && trim($page[$field]) !== '') {
                        $texts[$url][] = $this->text($page[$field]);
                    }
                }
            }
        }

        $errors = [];
        $citationObligations = [];
        foreach ($result as $item) {
            $key = $item['key'];
            $dropped = $item['evidence'] === 'dropped';
            $old = $originals->get($key);
            $sameValue = $old !== null && $this->text((string) ($old['value'] ?? '')) === $this->text($item['value']);
            if (! $dropped && $old !== null) {
                if ($item['evidence'] === 'corrected' && $sameValue) {
                    $errors[] = $key.': corrected but unchanged; change the actual value or use confirmed with supporting evidence.';
                } elseif ($item['evidence'] === 'confirmed' && ! $sameValue) {
                    $errors[] = $key.': confirmed but changed; account for the correction explicitly.';
                }
            }
            if (! $dropped) {
                $url = trim((string) ($item['source_url'] ?? ''));
                $quote = $this->text((string) ($item['source_excerpt'] ?? ''));
                if (($item['source_kind'] ?? 'web') === 'operator_request') {
                    // The operator's own words (live #430: a label SKU no page
                    // repeats). Copied verbatim and containing the value, so it
                    // cannot be invented; it never counts as website evidence.
                    $request = $this->text((string) $operatorRequest);
                    if ($request === '' || $quote === '' || $url !== ''
                        || ! $this->observed([$request], $quote, (string) ($item['source_excerpt'] ?? ''))
                        || ! str_contains($quote, $this->text((string) $item['value']))) {
                        $errors[] = $key.': operator_request excerpt must be copied verbatim from original_operator_request and contain this value; it records the operator\'s statement, not website evidence.';
                    }
                } elseif (($item['source_kind'] ?? 'web') === 'photo_label') {
                    $reference = trim((string) ($item['source_ref'] ?? ''));
                    // Intake and catalog may name the same field ram_capacity
                    // and ram. Provenance follows the photo and literal quote;
                    // semantic applicability still belongs to the assembler.
                    $labelTexts = $labels->where('source_ref', $reference)
                        ->map(fn ($label) => $this->text($label['source_excerpt']))->all();
                    if ($reference === '' || $quote === '' || $url !== ''
                        || ! $this->observed($labelTexts, $quote, (string) ($item['source_excerpt'] ?? ''))) {
                        $errors[] = $key.': photo_label excerpt was not observed in clear factory-label evidence for this source_ref; do not relabel handwriting, ambiguous OCR or a website as a verified label.';
                    }
                } elseif ($url === '' || $quote === '') {
                    $errors[] = $key.': missing source evidence; cite an observed source_url and source_excerpt, fetch evidence, or drop the unsupported field.';
                } elseif (! $this->observed($texts[$url] ?? [], $quote, (string) $item['source_excerpt'])) {
                    // Distinguish an unread source from an inaccurate quotation.
                    // Request #158 repeatedly changed www/non-www while keeping
                    // the same stitched sentences: the evidence was already here.
                    $guidance = empty($texts[$url])
                        ? 'No saved text is available for this URL. Read this source with a tool, or cite the observed source that actually contains the passage; a known URL alone is not evidence.'
                        : 'Saved text exists for this URL, but the submitted quotation does not match it. Copy its exact words and numbers; separate nonadjacent passages with a newline or semicolon, not a space after a period. Do not translate, paraphrase or silently omit intervening sentences within a passage. Do not change the source URL just to retry this quote. Read more only if the needed passage is absent from the saved evidence.';
                    $errors[] = $key.': source excerpt was not observed at '.$url.'. '.$guidance;
                }
                if ($item['evidence'] === 'corrected' && trim((string) ($item['reason'] ?? '')) === '') {
                    $errors[] = $key.': corrected value requires a reason.';
                }
            }

            $known = $facts->where('key', $key)->concat($labels->where('key', $key));
            $hasDifference = $dropped ? $known->isNotEmpty()
                : $known->contains(fn ($fact) => $this->text($fact['value']) !== $this->text($item['value']));
            if ($hasDifference) {
                $needed = $known->pluck('source_url')->filter();
                $considered = $item['considered_source_urls'] ?? [];
                $neededRefs = $known->pluck('source_ref')->filter()->unique()->all();
                $consideredRefs = $item['considered_source_refs'] ?? [];
                if (! $dropped && ($item['source_kind'] ?? null) === 'photo_label') {
                    $consideredRefs[] = $item['source_ref'] ?? '';
                }
                if (! $dropped && filled($item['source_url'] ?? null)) {
                    // The retained value already cites this source explicitly;
                    // its quotation is checked above. Repeating the same URL in
                    // a second field does not add evidence. Dropped fields do
                    // not have a selected source and must account for all URLs.
                    $selectedUrl = trim($item['source_url']);
                    $needed->push($selectedUrl);
                    $considered[] = $selectedUrl;
                }
                $missing = array_diff($needed->unique()->all(), $considered);
                $missingRefs = array_diff($neededRefs, $consideredRefs);
                if ($missing !== [] || $missingRefs !== [] || trim((string) ($item['reason'] ?? '')) === '') {
                    $citationObligations[$key] = [
                        'source_urls' => array_values($missing),
                        'source_refs' => array_values($missingRefs),
                        'explanation_required' => true,
                    ];
                    $error = $key.': differing or dropped cited observations require reason and considered_source_urls for the other sources. For retained fields, source_url is already counted and need not be repeated.';
                    if ($missing !== []) {
                        $error .= ' Missing source URLs: '.implode(', ', $missing).'.';
                    }
                    if ($missingRefs !== []) {
                        $error .= ' Missing input evidence references in considered_source_refs: '.implode(', ', $missingRefs).'.';
                    }
                    if (trim((string) ($item['reason'] ?? '')) === '') {
                        $error .= ' Missing explanation in reason.';
                    }
                    $errors[] = $error.' Explain applicability or equivalence; no source is automatically the winner.';
                }
            }
        }

        if ($errors !== []) {
            throw new InvalidSpecificationEvidence($errors, $citationObligations);
        }
    }

    /**
     * The quote as saved, or else every one of its lines, compared on letters
     * and whole numbers. A model quoting a specification table puts a tab or a
     * colon between label and value and may take its rows in another order
     * (request #99: "Processor Type⇥Core Ultra 7↵Processor Model⇥155H" from a
     * page listing those rows apart). Separators are not evidence; the words
     * and numbers still have to be the page's own.
     *
     * @param  array<int, string>  $texts  already normalized by text()
     */
    private function observed(array $texts, string $quote, string $rawQuote): bool
    {
        if (collect($texts)->contains(fn (string $text): bool => $this->containsWholeNumbers($text, $quote))) {
            return true;
        }

        // A semicolon joins two places on the page the same way a line break
        // does (request #51: "3K (2880 x 1800) 120Hz OLED 16:10 aspect ratio;
        // Touch Screen: Yes" - two rows of the page, quoted as one).
        $lines = collect(preg_split('/\R|;\s*/u', $rawQuote) ?: [])
            ->map(fn (string $line): string => $this->letters($line))
            ->filter(fn (string $line): bool => $line !== '')
            ->values();

        if ($lines->isEmpty()) {
            return false;
        }

        $haystacks = array_map(fn (string $text): string => $this->letters($text), $texts);

        return $lines->every(fn (string $line): bool => collect($haystacks)->contains(
            fn (string $haystack): bool => $this->containsWholeNumbers($haystack, $line),
        ));
    }

    /** A short excerpt may start or end anywhere except inside a number. */
    private function containsWholeNumbers(string $text, string $quote): bool
    {
        $start = strpos($text, $quote);
        if ($start === false) {
            return false;
        }

        preg_match_all('/'.self::NUMBER_PATTERN.'/u', $text, $numbers, PREG_OFFSET_CAPTURE);
        do {
            $end = $start + strlen($quote);
            $splitsNumber = false;
            foreach ($numbers[0] as [$number, $numberStart]) {
                $numberEnd = $numberStart + strlen($number);
                if (($numberStart < $start && $start < $numberEnd)
                    || ($numberStart < $end && $end < $numberEnd)) {
                    $splitsNumber = true;
                    break;
                }
            }
            if (! $splitsNumber) {
                return true;
            }

            $start = strpos($text, $quote, $start + 1);
        } while ($start !== false);

        return false;
    }

    /**
     * Words and numbers only, separators dropped - except where they are part
     * of a number. "Weight: 2.5 kg" must not match a quote saying 25 kg, and
     * "1 6" must not become 16: numeric signs and decimal/thousands marks
     * stay, and two numbers keep a space between them.
     */
    private function letters(string $text): string
    {
        preg_match_all('/\p{L}+|'.self::NUMBER_PATTERN.'/u', mb_strtolower(html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8')), $tokens);

        $joined = '';
        $previousWasNumber = false;
        foreach ($tokens[0] as $token) {
            $isNumber = preg_match('/\p{N}/u', $token) === 1;
            $hasSign = preg_match('/^[+\-\x{2212}]/u', $token) === 1;
            // Keep the sign outside a preceding word after compaction, so
            // the numeric-boundary check still sees Voltage: -12 as signed.
            $needsSpace = ($previousWasNumber && $isNumber) || ($joined !== '' && $hasSign);
            $joined .= ($needsSpace ? ' ' : '').$token;
            $previousWasNumber = $isNumber;
        }

        return $joined;
    }

    private function isCitedFact(mixed $fact): bool
    {
        return is_array($fact) && is_string($fact['key'] ?? null) && is_string($fact['value'] ?? null)
            && is_string($fact['source_url'] ?? null) && trim($fact['source_url']) !== ''
            && is_string($fact['source_excerpt'] ?? null) && trim($fact['source_excerpt']) !== '';
    }

    private function text(string $text): string
    {
        return mb_strtolower(trim(preg_replace('/[\s\p{Z}]+/u', ' ', html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? $text));
    }
}
