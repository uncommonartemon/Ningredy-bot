<?php

namespace App\Services\Photos;

/**
 * Where a CDN names the rendition in the address, the same frame can be asked
 * for without that name or at a large size. Over 45 live searches, 286 frames
 * of product size were dropped as too small (live #87: 38 on two shops and no
 * photo at all; #161: 34 on five shops), and almost every one of their
 * addresses said how small: _300x.png, /400x400/, -500x500-1.webp?w=640,
 * /-/resize/400/, ?height=650, /_next/image?url=…&w=256. Live #187 added the
 * commonest shop platform of Spain and Latin America: VTEX serves every size
 * of asset 2225516 as /ids/2225516-461-461/ and the original as /ids/2225516/.
 *
 * These are guesses about the address, never about the picture: every guess is
 * downloaded and measured like any other candidate, and a wrong one is only a
 * request that failed. What a site's path names is data (RenditionRules: seeds,
 * rules the agent learned for a site, each one's record per host), not code;
 * the measured size, the size parameters and proxies stay here. The browser
 * applies the same rules (scripts/product-gallery-utils.mjs largerRenditions),
 * and both are held to tests/Fixtures/rendition-probe-cases.json.
 */
final class ImageRenditionProbe
{
    private const SIZE_QUERY_KEYS = ['w', 'h', 'width', 'height', 'wid', 'hei', 'size', 'resize', 'fit', 'dpr', 'q', 'quality',
        'maxwidth', 'maxheight', 'max_width', 'max_height', 'scale', 'crop', 'sw', 'sh'];

    private const IMAGE_PROXY_KEYS = ['url', 'src', 'image', 'img', 'file'];

    /** The smallest width or height a numeric path token can mean: a date's month or day never is one. */
    private const SMALLEST_SIZE_TOKEN = 32;

    /**
     * Addresses of the same frame that may be larger, best guess first; never the
     * address itself. The frame's measured width and height, when known, make the
     * surest guesses: a number in the address that equals what was measured is
     * its size, whatever form the site gives it (live #203: smarty.cz
     * "-600-600.webp", ispace.cz "/photos/320/", elektro-unima.cz "-300-300.jpg").
     *
     * @return list<string>
     */
    public static function largerRenditions(string $url, int $limit = 6, ?int $width = null, ?int $height = null): array
    {
        return array_keys(self::guesses($url, $limit, $width, $height));
    }

    /**
     * The same guesses, each with the rendition rule that produced it (null for the
     * measured size, size parameters and proxies), so its outcome can be counted.
     *
     * @return array<string, ?int>
     */
    public static function guesses(string $url, int $limit = 6, ?int $width = null, ?int $height = null): array
    {
        $url = ImageUrls::normalizeCandidateUrl($url);
        $guesses = [];
        foreach ([...array_map(fn (string $variant): array => [$variant, null], self::measuredVariants($url, $width, $height)),
            ...self::variants($url, true)] as [$variant, $ruleId]) {
            if ($variant !== $url && ! array_key_exists($variant, $guesses) && filter_var($variant, FILTER_VALIDATE_URL) !== false) {
                $guesses[$variant] = $ruleId;
            }
        }

        return array_slice($guesses, 0, max(1, $limit), true);
    }

    /** @return list<string> the address with its measured size rewritten large, in two sizes a CDN commonly keeps */
    private static function measuredVariants(string $url, ?int $width, ?int $height): array
    {
        if (! $width || $width < self::SMALLEST_SIZE_TOKEN) {
            return [];
        }
        $parts = parse_url($url);
        if (! is_array($parts) || empty($parts['host']) || empty($parts['path'])) {
            return [];
        }
        parse_str((string) ($parts['query'] ?? ''), $query);
        $height = $height && $height >= self::SMALLEST_SIZE_TOKEN ? $height : $width;
        $w = preg_quote((string) $width, '#');
        $h = preg_quote((string) $height, '#');
        $variants = [];
        foreach ([
            ["#([-_]){$w}([-_x]){$h}(?=\.[a-z0-9]{3,4}$)#i", ['${1}1600${2}1600', '${1}1000${2}1000']],
            ["#/{$w}/#", ['/1600/', '/1000/']],
        ] as [$pattern, $replacements]) {
            if (preg_match($pattern, $parts['path']) !== 1) {
                continue;
            }
            foreach ($replacements as $replacement) {
                $variants[] = self::build($parts, (string) preg_replace($pattern, $replacement, $parts['path']), $query);
            }
        }
        // A lone number closing the file name, with or without an extension, is
        // the bounding box of what was measured (#212 xcom-shop: .../79+f2Npd7n8pG_600
        // measured 600x405 and 540x472, _80 measured 80x54; no suffix and _1000 are
        // served, _1200 and above are not): the bare name first, then the ladder.
        $longest = max($width, $height);
        if (preg_match('#([-_])(\d{2,4})((?:\.[a-z0-9]{3,4})?)$#i', $parts['path'], $token) === 1
            && (int) $token[2] >= $longest && (int) $token[2] <= 2 * $longest) {
            $stem = substr($parts['path'], 0, -strlen($token[0]));
            foreach ([$stem.$token[3], $stem.$token[1].'1600'.$token[3], $stem.$token[1].'1000'.$token[3]] as $path) {
                $variants[] = self::build($parts, $path, $query);
            }
        }

        return $variants;
    }

    /** @return list<array{0: string, 1: ?int}> each variant with the rule that produced it */
    private static function variants(string $url, bool $unwrapProxies): array
    {
        $parts = parse_url($url);
        if (! is_array($parts) || empty($parts['host']) || empty($parts['path'])) {
            return [];
        }
        parse_str((string) ($parts['query'] ?? ''), $query);
        $variants = [];

        // An image proxy carries the original address as a parameter - and that
        // address may name a rendition of its own (#187: /_next/image?url=…/ids/2225515-64-64/…).
        if ($unwrapProxies) {
            foreach (self::IMAGE_PROXY_KEYS as $key) {
                $inner = $query[$key] ?? null;
                if (is_string($inner) && str_starts_with($inner, '/')) {
                    $inner = $parts['scheme'].'://'.$parts['host'].$inner;
                }
                if (is_string($inner) && preg_match('#^https?://#i', $inner) === 1) {
                    array_push($variants, [$inner, null], ...self::variants($inner, false));
                }
            }
        }

        // Size parameters in the query: the address without them.
        $withoutSize = array_diff_key($query, array_flip(self::SIZE_QUERY_KEYS));
        if (count($withoutSize) !== count($query)) {
            $variants[] = [self::build($parts, $parts['path'], $withoutSize), null];
        }

        // Rendition names in the path, by the rules this image host has: data, not code
        // (RenditionRules), each one counted on this host and learned anew when a site changes.
        $path = $parts['path'];
        foreach (RenditionRules::forHost((string) $parts['host']) as $rule) {
            $rewritten = RenditionRules::apply($rule, $path);
            if ($rewritten !== null && $rewritten !== $path) {
                $variants[] = [self::build($parts, $rewritten, $query), $rule['id']];
            }
        }

        return $variants;
    }

    /** @param array<string, mixed> $parts @param array<string, mixed> $query */
    private static function build(array $parts, string $path, array $query): string
    {
        return $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '').$path
            .($query === [] ? '' : '?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986));
    }
}
