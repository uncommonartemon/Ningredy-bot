<?php

namespace App\Services\Products;

use App\Services\Photos\ImageUrls;
use DOMDocument;
use DOMXPath;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;

/** Observed page identity, separate from search-result labels and recommendations. */
final class ProductSourcePageMetadata
{
    public static function fromHtml(string $html, string $url): array
    {
        if ($html === '' || strlen($html) > 5_000_000) {
            return [];
        }

        $previous = libxml_use_internal_errors(true);
        $document = new DOMDocument;
        $loaded = $document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (! $loaded) {
            return [];
        }

        $xpath = new DOMXPath($document);
        $canonical = null;
        $alternates = [];
        foreach ($xpath->query('//link[@href]') ?: [] as $node) {
            $rel = preg_split('/\s+/', strtolower($node->getAttribute('rel'))) ?: [];
            $href = self::absoluteUrl($node->getAttribute('href'), $url);
            if (in_array('canonical', $rel, true) && $href !== null) {
                $canonical = $href;
            }
            if (in_array('alternate', $rel, true) && $node->hasAttribute('hreflang') && $href !== null) {
                $alternates[] = $href;
            }
        }

        $products = [];
        foreach ($xpath->query('//script[@type="application/ld+json"]') ?: [] as $node) {
            self::collectProducts(json_decode($node->nodeValue, true), $products);
        }
        if ($products === []) {
            // Retain native microdata identity, but never collect identifiers
            // from every recommendation card as if they belonged to this item.
            foreach ($xpath->query('//*[@itemscope and (@itemtype="https://schema.org/Product" or @itemtype="http://schema.org/Product") and not(ancestor::*[@itemscope])]') ?: [] as $node) {
                $product = ['@type' => 'Product'];
                foreach ($xpath->query('.//*[@itemprop]', $node) ?: [] as $property) {
                    $parent = $property->parentNode;
                    while ($parent !== null && $parent !== $node && ! ($parent instanceof \DOMElement && $parent->hasAttribute('itemscope'))) {
                        $parent = $parent->parentNode;
                    }
                    if ($parent !== $node || $property->hasAttribute('itemscope')) {
                        continue;
                    }
                    $value = $property->getAttribute('content') ?: $property->getAttribute('href') ?: $property->nodeValue;
                    $product[$property->getAttribute('itemprop')] = trim($value);
                }
                $products[] = $product;
            }
        }
        // Prefer the entity that explicitly owns this page. ItemList and
        // isRelatedTo objects never enter this collection in the first place.
        $pageKeys = array_map(self::urlKey(...), array_filter([$url, $canonical]));
        $bound = array_values(array_filter($products, function (array $product) use ($pageKeys, $url): bool {
            foreach (['url', '@id', 'mainEntityOfPage'] as $key) {
                $value = $product[$key] ?? null;
                $value = is_array($value) ? ($value['@id'] ?? $value['url'] ?? null) : $value;
                if (is_string($value) && in_array(self::urlKey(self::absoluteUrl($value, $url) ?? ''), $pageKeys, true)) {
                    return true;
                }
            }

            return false;
        }));
        $product = count($bound) === 1 ? $bound[0] : (count($products) === 1 ? $products[0] : []);
        $identity = [];
        foreach (['name', 'model', 'sku', 'mpn', 'gtin', 'gtin8', 'gtin12', 'gtin13', 'gtin14'] as $field) {
            $value = $product[$field] ?? null;
            if (is_scalar($value) && trim((string) $value) !== '') {
                $identity[$field] = trim((string) $value);
            }
        }

        $titles = [];
        foreach (['//title', '//h1', '//meta[@property="og:title"]/@content', '//meta[@name="twitter:title"]/@content'] as $query) {
            foreach ($xpath->query($query) ?: [] as $node) {
                $text = trim(preg_replace('/\s+/u', ' ', $node->nodeValue) ?? '');
                if ($text !== '') {
                    $titles[] = $text;
                }
            }
        }
        $images = self::imageUrls($product['image'] ?? [], $url);

        return [
            'observed_url' => $url,
            'canonical_url' => $canonical,
            'alternate_urls' => array_values(array_unique($alternates)),
            'titles' => array_values(array_unique($titles)),
            'product' => $identity,
            'product_image_urls' => $images,
        ];
    }

    /** A declared alternate is a reserve route, not proof that its pixels match. */
    public static function mirrorEvidence(array $left, array $right): ?array
    {
        $a = $left['_preflight_page_identity'] ?? [];
        $b = $right['_preflight_page_identity'] ?? [];
        $aUrl = self::urlKey($a['observed_url'] ?? '');
        $bUrl = self::urlKey($b['observed_url'] ?? '');
        if ($aUrl === '' || $bUrl === '' || $aUrl === $bUrl) {
            return null;
        }

        $sameIdentifier = false;
        foreach (['sku', 'mpn', 'gtin', 'gtin8', 'gtin12', 'gtin13', 'gtin14'] as $key) {
            $av = $a['product'][$key] ?? null;
            $bv = $b['product'][$key] ?? null;
            if ($av !== null && $bv !== null) {
                if (strcasecmp($av, $bv) !== 0) {
                    return null;
                }
                $sameIdentifier = true;
            }
        }
        $canonical = self::urlKey($a['canonical_url'] ?? '');
        $sameCanonical = $canonical !== '' && $canonical === self::urlKey($b['canonical_url'] ?? '')
            && trim((string) parse_url($canonical, PHP_URL_PATH), '/') !== '';
        $reciprocal = in_array($bUrl, array_map(self::urlKey(...), $a['alternate_urls'] ?? []), true)
            && in_array($aUrl, array_map(self::urlKey(...), $b['alternate_urls'] ?? []), true);
        if (! $sameIdentifier || (! $sameCanonical && ! $reciprocal)) {
            return null;
        }

        $assets = static function (array $urls): array {
            $keys = array_values(array_unique(array_map(ImageUrls::imageAssetKey(...), $urls)));
            sort($keys);

            return $keys;
        };
        $aAssets = $assets($a['product_image_urls'] ?? []);
        $bAssets = $assets($b['product_image_urls'] ?? []);

        return [
            'reason' => $sameCanonical ? 'shared_observed_canonical' : 'reciprocal_observed_alternates',
            'matching_observed_identifier' => true,
            'same_observed_product_assets' => count($aAssets) >= 2 && $aAssets === $bAssets,
            'product_asset_count' => count($bAssets),
        ];
    }

    private static function collectProducts(mixed $node, array &$products, int $depth = 0): void
    {
        if (! is_array($node) || $depth > 8) {
            return;
        }
        if (array_is_list($node)) {
            foreach ($node as $item) {
                self::collectProducts($item, $products, $depth + 1);
            }

            return;
        }
        $types = (array) ($node['@type'] ?? []);
        if (array_intersect($types, ['Product', 'https://schema.org/Product', 'http://schema.org/Product']) !== []) {
            $products[] = $node;
        }
        foreach (['@graph', 'mainEntity'] as $key) {
            self::collectProducts($node[$key] ?? null, $products, $depth + 1);
        }
    }

    private static function imageUrls(mixed $image, string $base, int $depth = 0): array
    {
        if ($depth > 8) {
            return [];
        }
        if (is_string($image)) {
            $url = self::absoluteUrl($image, $base);

            return $url === null ? [] : [$url];
        }
        if (! is_array($image)) {
            return [];
        }
        if (! array_is_list($image)) {
            return self::imageUrls($image['contentUrl'] ?? $image['url'] ?? [], $base, $depth + 1);
        }

        return array_values(array_unique(array_merge([], ...array_map(fn ($item): array => self::imageUrls($item, $base, $depth + 1), $image))));
    }

    private static function absoluteUrl(string $href, string $base): ?string
    {
        try {
            $url = (string) UriResolver::resolve(new Uri($base), new Uri(html_entity_decode(trim($href), ENT_QUOTES | ENT_HTML5)));

            return $href !== '' && in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true) ? $url : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private static function urlKey(string $url): string
    {
        return rtrim(explode('#', trim($url), 2)[0], '/');
    }
}
