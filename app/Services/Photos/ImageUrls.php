<?php

namespace App\Services\Photos;

use Illuminate\Support\Str;

/** Identity and ranking of observed URLs. Never invents or downloads a rendition. */
class ImageUrls
{
    /**
     * A CMS image cache run: "/cache/", at most three parameter segments, then a hash
     * directory (Magento 2 "/cache/<md5>/", Magento 1 "/cache/1/image/600x/<md5>/").
     * The uploaded original sits at the same path without it. Mirrors
     * CMS_IMAGE_CACHE_RUN in scripts/product-gallery-utils.mjs.
     */
    public const CMS_IMAGE_CACHE_RUN = '#/cache/(?:[^/]+/){0,3}[0-9a-f]{16,64}/#i';

    /**
     * A shop image type after the image id (PrestaShop "/308628-large_default/name.jpg"):
     * "/308628/name.jpg" is the original. Mirrors IMAGE_TYPE_SEGMENT in
     * scripts/product-gallery-utils.mjs.
     */
    public const IMAGE_TYPE_SEGMENT = '#/(\d+)-[a-z0-9]+(?:[-_][a-z0-9]+)*_default/#i';

    /**
     * Normalize only the syntax of an actually observed URL. Rendition
     * canonicalization belongs in imageAssetKey(); this return value is a
     * download target and therefore must never contain a guessed size.
     */
    public static function normalizeCandidateUrl(string $url): string
    {
        // A URL lifted out of inline JSON keeps its escaped slashes: live #1
        // dropped sixteen product photos as https://www.esseshop.it\/getimage/...
        return trim(str_replace(['\\/', '\\u002F', '\\u002f'], '/', html_entity_decode($url, ENT_QUOTES | ENT_HTML5)));
    }

    /**
     * Identity of the physical gallery frame, independent from its CDN
     * rendition. Unlike normalizeCandidateUrl(), this key is never fetched.
     */
    public static function imageAssetKey(string $url): string
    {
        $normalized = self::normalizeCandidateUrl($url);
        $host = Str::lower((string) parse_url($normalized, PHP_URL_HOST));
        $path = (string) parse_url($normalized, PHP_URL_PATH);

        // An image service's size token closing the file name ("._AC_SL1500_.jpg": Amazon's
        // CDN) picks a rendition of the same picture, whatever the host.
        $normalized = preg_replace('#\._[A-Za-z0-9,_-]+_(?=\.(?:jpe?g|png|webp|gif)(?:$|\?))#', '', $normalized) ?: $normalized;
        // A rendition-coded first directory ("/r705/": LDLC's CDN) picks a size of the frame below it.
        $normalized = preg_replace('#^(https?://[^/]+)/r\d{2,5}/#i', '$1/__rendition__/', $normalized) ?: $normalized;

        // A width segment closing the path ("/gain/<id>/w80", "//w184": live #278 asus.com) names a
        // rendition of the frame at that path, whatever the host. Mirrors product-gallery-utils.mjs.
        $normalized = preg_replace('#/{1,2}w\d{2,5}(?=\?|$)#i', '', $normalized) ?: $normalized;

        if (preg_match('#/is/image/#i', $path) === 1) {
            $normalized = Str::before($normalized, '?');
        }

        // A filename that is a long hexadecimal digest names the photograph's
        // content; directory and extension only pick a rendition (request #93:
        // ispace.cz /photos/1600/8/<sha1>.webp and /photos/780/8/<sha1>.jpg).
        // At least one letter: a 32-digit number is an id, not a digest.
        // An observed image endpoint can omit its extension. Only rendition
        // parameters disappear; an id/frame/version query can select content.
        // Mirrors CONTENT_DIGEST_FILENAME in scripts/product-gallery-utils.mjs.
        if (preg_match('#/([0-9a-f]{32,})(?:\.(?:jpe?g|png|webp|gif|avif))?$#i', $path, $digest) === 1
            && preg_match('/[a-f]/i', $digest[1]) === 1) {
            $query = collect(explode('&', (string) parse_url($normalized, PHP_URL_QUERY)))
                ->filter(fn (string $part): bool => $part !== '')
                ->map(fn (string $part): array => array_map('urldecode', explode('=', $part, 2)))
                ->reject(fn (array $pair): bool => in_array($pair[0], ['width', 'height', 'w', 'h', 'quality', 'q', 'fit'], true)
                    || ($pair[0] === 'x' && ctype_digit($pair[1] ?? '')))
                ->sortBy(fn (array $pair): string => $pair[0], SORT_STRING)
                ->map(fn (array $pair): string => urlencode($pair[0]).'='.urlencode($pair[1] ?? ''))
                ->implode('&');

            return 'digest:'.$host.':'.Str::lower($digest[1]).($query === '' ? '' : '?'.$query);
        }

        // Shopify's file paths, on its own CDN ("/s/files/") and on a shop's domain ("/cdn/shop/files/").
        if (preg_match('#/(?:cdn/shop/(?:files|products)|s/files)/#i', $path) === 1) {
            $normalized = preg_replace(
                '/_(?:\d+x\d*|pico|icon|thumb|small|compact|medium|large|grande|original|master)(?:@\dx)?(?=\.(?:jpe?g|png|webp|gif)(?:$|\?))/i',
                '',
                $normalized,
            ) ?: $normalized;
            $base = Str::before($normalized, '?');
            parse_str((string) parse_url($normalized, PHP_URL_QUERY), $query);
            foreach (['width', 'height', 'w', 'h'] as $key) {
                unset($query[$key]);
            }
            $normalized = $base.($query === [] ? '' : '?'.http_build_query($query));
        }

        $normalized = preg_replace(
            '/([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})(?:_(?:\d{2,5}|sea))?\.(?:jpe?g|png|webp|gif|avif)(?=$|\?)/i',
            '$1.__image__',
            $normalized,
        ) ?: $normalized;

        // An asset id with its size (VTEX "/arquivos/ids/2225516-461-461/", live
        // #187) is asset 2225516: every size and the bare original are one frame.
        // A date's month or day is never a size. Mirrors imageAssetKey() in
        // scripts/product-gallery-utils.mjs.
        $normalized = preg_replace_callback(
            '#/(\d+)-(\d{2,4})-(\d{2,4})/#',
            fn (array $match): string => (int) $match[2] >= 32 && (int) $match[3] >= 32 ? '/'.$match[1].'/' : $match[0],
            $normalized,
        ) ?: $normalized;
        // A CMS image cache run (Magento "/cache/<hash>/", live #273) only picks a
        // rendition of the original at the same path.
        $normalized = preg_replace(self::CMS_IMAGE_CACHE_RUN, '/', $normalized) ?: $normalized;
        // A shop image type (PrestaShop "/308628-large_default/") names a size of image 308628.
        $normalized = preg_replace(self::IMAGE_TYPE_SEGMENT, '/$1/', $normalized) ?: $normalized;
        // "/original/" beside size buckets is one more bucket (BigCommerce "/stencil/original/").
        $normalized = preg_replace('#/original/(?=.+/)#i', '/__rendition__/', $normalized) ?: $normalized;

        // A bare "WxH" or "Nw" path segment (BigCommerce Stencil:
        // /stencil/1280x1280/products/.../file.jpg vs /stencil/640w/... same
        // file - a real case that reached production undeduped) is a size
        // bucket, not part of the asset identity, wherever it sits in the
        // path - unlike the named buckets below it is rarely adjacent to the
        // filename.
        $normalized = preg_replace(
            '#/(?:[a-z][a-z_-]*)?\d{2,5}(?:x\d{2,5}|w)/#i',
            '/__rendition__/',
            $normalized,
        ) ?: $normalized;

        return Str::lower(preg_replace(
            '#/(?:thumb(?:nail)?s?|small|medium|large|xlarge|xxlarge|original)/(?=[^/?]+(?:\?|$))#i',
            '/__rendition__/',
            $normalized,
        ) ?: $normalized);
    }

    /**
     * Whether a candidate URL names the colour the draft asked for.
     *
     * Deliberately no list of colour names: the draft already holds the word,
     * and the only question is whether the URL says it too. Matched on token
     * boundaries so "blue" cannot match "bluetooth", and only for a word long
     * enough to mean something on its own.
     */
    public static function candidateMatchesVariant(string $url, ?string $variantHint): bool
    {
        $tokens = collect(preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower((string) $variantHint)) ?: [])
            ->filter(fn (string $token): bool => mb_strlen($token) >= 4)
            ->values();

        if ($tokens->isEmpty()) {
            return false;
        }

        $haystack = mb_strtolower((string) parse_url($url, PHP_URL_PATH));

        return $tokens->contains(fn (string $token): bool => preg_match(
            '/(?:^|[^a-z0-9])'.preg_quote($token, '/').'(?:[^a-z0-9]|$)/i',
            $haystack,
        ) === 1);
    }

    public static function candidateUrlQualityScore(string $url): int
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        $directoryScore = 0;

        if (preg_match(
            '#/(thumb(?:nail)?s?|small|medium|large|xlarge|xxlarge|original)/(?=[^/?]+(?:\?|$))#i',
            $path,
            $matches,
        ) === 1) {
            $directoryScore = match (Str::lower($matches[1])) {
                'thumb', 'thumbnail', 'thumbnails' => 100,
                'small' => 300,
                'medium' => 600,
                'large' => 1000,
                'xlarge' => 1600,
                'xxlarge' => 2200,
                'original' => 3000,
                default => 0,
            };
        }

        $uuidRenditionScore = 0;

        if (preg_match(
            '/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}_(\d{2,5}|sea)(?=\.(?:jpe?g|png|webp|gif|avif)$)/i',
            $path,
            $matches,
        ) === 1) {
            $uuidRenditionScore = Str::lower($matches[1]) === 'sea'
                ? 2000
                : (int) $matches[1];
        }

        $queryScore = 0;
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        foreach (['w', 'width', 'wid', 'h', 'height', 'hei'] as $key) {
            if (isset($query[$key]) && is_numeric($query[$key])) {
                $queryScore = max($queryScore, min(4000, (int) $query[$key]));
            }
        }

        $pathRenditionScore = preg_match('#/r(\d{2,5})/#i', $path, $matches) === 1
            ? min(4000, (int) $matches[1])
            : 0;

        $sizeSegmentScore = match (true) {
            preg_match('#/(?:[a-z][a-z_-]*)?(\d{2,5})x(\d{2,5})/#i', $path, $matches) === 1 => min(4000, max((int) $matches[1], (int) $matches[2])),
            preg_match('#/(?:[a-z][a-z_-]*)?(\d{2,5})w/#i', $path, $matches) === 1 => min(4000, (int) $matches[1]),
            default => 0,
        };

        return max($directoryScore, $uuidRenditionScore, $queryScore, $pathRenditionScore, $sizeSegmentScore);
    }
}
