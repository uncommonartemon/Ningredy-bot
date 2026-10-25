<?php

namespace App\Services\Products;

/**
 * Where a source's identity came from, carried on the source array.
 *
 * A structured-database record matched by the selected part number or GTIN
 * is the strongest identity evidence a search has: the code names the exact
 * product. A later look at the record's own page is weaker evidence about the
 * same thing, and it can be no evidence at all (live #157, #159: the page
 * answered with a bot check, the page look replaced the part number with
 * "Making sure you're not a bot!", and the official gallery was set aside or
 * lost). So for such a source the page look may add to the record's evidence
 * but never replaces it, and the record's address stays the source's address.
 */
final class SourceIdentityProvenance
{
    public const KEY = '_identity_confirmed_by';

    public const CODE_MATCH = 'code_match';

    /** @param array<int, string> $codes @return array{kind: string, database: string, codes: array<int, string>} */
    public static function codeMatch(string $database, array $codes): array
    {
        return ['kind' => self::CODE_MATCH, 'database' => $database,
            'codes' => array_values(array_filter(array_map('strval', $codes), fn (string $code): bool => trim($code) !== ''))];
    }

    /** @param array<string, mixed> $source */
    public static function confirmedByCode(array $source): bool
    {
        return is_array($source[self::KEY] ?? null) && ($source[self::KEY]['kind'] ?? null) === self::CODE_MATCH;
    }

    /**
     * The identity evidence after a page look: a code-matched record's own
     * evidence first, the page's observations after it; anything else keeps
     * only what the page showed, as before.
     *
     * @param  array<string, mixed>  $source
     */
    public static function mergeEvidence(array $source, ?string $pageEvidence): string
    {
        $page = trim((string) $pageEvidence);
        $record = trim((string) ($source['_preflight_identity_evidence'] ?? ''));
        if (! self::confirmedByCode($source) || $record === '') {
            return $page;
        }

        return $page === '' || str_contains($record, $page) ? $record : $record."\n".$page;
    }

    /**
     * The address a page look leaves the source with. A record's page is the
     * record's address; a redirect to a challenge wrapper is not the product.
     *
     * @param  array<string, mixed>  $source
     */
    public static function pageUrl(array $source, string $observedUrl): string
    {
        return self::confirmedByCode($source) && is_string($source['url'] ?? null) && $source['url'] !== ''
            ? $source['url'] : $observedUrl;
    }

    /** The source keys a page context built from a page look carries along. @param array<string, mixed> $source @return array<string, mixed> */
    public static function carry(array $source): array
    {
        return array_intersect_key($source, array_flip([self::KEY, '_official_gallery']));
    }
}
