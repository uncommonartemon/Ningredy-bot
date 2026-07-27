<?php

namespace App\Services\Products;

use Illuminate\Support\Str;

/**
 * Junk-URL marker groups shared by the image-candidate filters so the
 * duplicated lists cannot drift apart. Each filter combines the groups it
 * needs and may add its own specific markers on top.
 */
class ImageUrlHeuristics
{
    /** Decorative assets rejected by every candidate filter. */
    public const COMMON_MARKERS = ['logo', 'favicon', 'sprite', 'placeholder'];

    /** Small thumbnails and size-suffixed variants. */
    public const THUMBNAIL_MARKERS = ['thumb', 'thumbnail', '/small/', '_small'];

    /** Analytics beacons. */
    public const TRACKING_MARKERS = ['tracking', 'pixel.'];

    /** Non-photo formats and site chrome (headers, icons, tiny renditions). */
    public const ASSET_MARKERS = ['.svg', '/header/', '/icons/', '/w48', '/w64', '/w96', '/w184'];

    /** @param array<int, string> $markers */
    public static function containsMarker(string $url, array $markers): bool
    {
        return Str::contains(Str::lower($url), $markers);
    }
}
