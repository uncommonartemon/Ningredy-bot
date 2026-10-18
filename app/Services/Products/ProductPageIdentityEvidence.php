<?php

namespace App\Services\Products;

/** Identity evidence from one rendered page, never from its search result. */
final class ProductPageIdentityEvidence
{
    /** @return array{url: string, title: ?string, _preflight_identity_evidence: string} */
    public static function fromBrowserObservation(string $url, array $observation): array
    {
        $page = is_array($observation['scout'] ?? null) ? $observation['scout'] : [];
        $identity = is_array($observation['product_identity'] ?? null) ? $observation['product_identity'] : [];
        $pageUrl = self::text($observation['product_page_url'] ?? null)
            ?? self::text($page['final_url'] ?? null)
            ?? $url;
        $headings = is_array($page['product_headings'] ?? null) ? $page['product_headings'] : [];
        $specificationText = self::text($page['specification_text'] ?? null)
            ?? self::text($observation['specification_text'] ?? null);

        return [
            'url' => $pageUrl,
            'title' => self::text($page['title'] ?? null) ?? self::text($identity['name'] ?? null),
            // Structured IDs and H1 precede the longer page text so the
            // identity judge's compact window keeps the product's own codes.
            '_preflight_identity_evidence' => implode("\n", array_filter([
                $identity === [] ? '' : json_encode($identity, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                implode("\n", array_filter(array_map(self::text(...), $headings))),
                $specificationText,
            ])),
        ];
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? $value : null;
    }
}
