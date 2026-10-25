<?php

namespace App\Services\Photos;

/**
 * Where a CDN names the rendition in the address, the same frame can be asked
 * for without that name or at a large size. Over 45 live searches, 286 frames
 * of product size were dropped as too small (live #87: 38 on two shops and no
 * photo at all; #161: 34 on five shops), and almost every one of their
 * addresses said how small: _300x.png, /400x400/, -500x500-1.webp?w=640,
 * /-/resize/400/, ?height=650, /_next/image?url=…&w=256.
 *
 * These are guesses about the address, never about the picture: every guess is
 * downloaded and measured like any other candidate, and a wrong one is only a
 * request that failed.
 */
final class ImageRenditionProbe
{
    private const SIZE_QUERY_KEYS = ['w', 'h', 'width', 'height', 'wid', 'hei', 'size', 'resize', 'fit', 'dpr', 'q', 'quality',
        'maxwidth', 'maxheight', 'max_width', 'max_height', 'scale', 'crop', 'sw', 'sh'];

    private const IMAGE_PROXY_KEYS = ['url', 'src', 'image', 'img', 'file'];

    /** @return list<string> addresses of the same frame that may be larger, best guess first; never the address itself */
    public static function largerRenditions(string $url, int $limit = 4): array
    {
        $url = ImageUrls::normalizeCandidateUrl($url);
        $parts = parse_url($url);
        if (! is_array($parts) || empty($parts['host']) || empty($parts['path'])) {
            return [];
        }
        parse_str((string) ($parts['query'] ?? ''), $query);
        $variants = [];

        // An image proxy carries the original address as a parameter.
        foreach (self::IMAGE_PROXY_KEYS as $key) {
            $inner = $query[$key] ?? null;
            if (is_string($inner) && preg_match('#^https?://#i', $inner) === 1) {
                $variants[] = $inner;
            } elseif (is_string($inner) && str_starts_with($inner, '/')) {
                $variants[] = $parts['scheme'].'://'.$parts['host'].$inner;
            }
        }

        // Size parameters in the query: the address without them.
        $withoutSize = array_diff_key($query, array_flip(self::SIZE_QUERY_KEYS));
        if (count($withoutSize) !== count($query)) {
            $variants[] = self::build($parts, $parts['path'], $withoutSize);
        }

        // Rendition names in the path: dropped, or replaced with a large size.
        $path = $parts['path'];
        foreach ([
            // An image service's own resize operation (uploadcare "/-/resize/400/") comes off first.
            ['#/-/(?:resize|scale_crop)/[^/]+/#i', '/'],
            // A size in the file name: Shopify "_300x", WordPress "-500x500" (also before its
            // "-1" duplicate counter), retina "@2x" - without it, or at a large size.
            ['#[-_]\d{2,4}x\d{0,4}(?:@\dx)?(?=(?:-\d+)?\.[a-z0-9]{3,4}$)#i', ''],
            ['#(?<=[-_])\d{2,4}x(?=(?:-\d+)?\.[a-z0-9]{3,4}$)#i', '1600x'],
            ['#(?<=[-_])\d{2,4}x\d{2,4}(?:@\dx)?(?=(?:-\d+)?\.[a-z0-9]{3,4}$)#i', '1600x1600'],
            ['#[-_](?:xs|s|sm|small|m|md|medium|thumb|thumbnail|mini|preview|tn)(?=\.[a-z0-9]{3,4}$)#i', ''],
            // A size directory (BigCommerce "/stencil/400x400/", "/300x300/") at a large size.
            ['#/(?:[a-z]+[-_]?)?\d{2,4}x\d{2,4}/#i', '/1600x1600/'],
            // A width directory ("/w80/", "/w_600/": ASUS, imgix-style CDNs) at a large width, or without it.
            ['#/w_?\d{2,4}/#i', '/w1600/'],
            ['#/w_?\d{2,4}/#i', '/'],
            ['#(/-/(?:resize|preview)/)[^/]+/#i', '${1}1600x1600/'],
            ['#/r\d{2,5}/#i', '/r1600/'],
            ['#/(?:thumbs?|thumbnails?|small|medium)/(?=[^/]+$)#i', '/large/'],
        ] as [$pattern, $replacement]) {
            $rewritten = preg_replace($pattern, $replacement, $path);
            if (is_string($rewritten) && $rewritten !== $path) {
                $variants[] = self::build($parts, $rewritten, $query);
            }
        }

        $variants = array_values(array_unique(array_filter($variants,
            fn (string $variant): bool => $variant !== $url && filter_var($variant, FILTER_VALIDATE_URL) !== false)));

        return array_slice($variants, 0, max(1, $limit));
    }

    /** @param array<string, mixed> $parts @param array<string, mixed> $query */
    private static function build(array $parts, string $path, array $query): string
    {
        return $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '').$path
            .($query === [] ? '' : '?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986));
    }
}
