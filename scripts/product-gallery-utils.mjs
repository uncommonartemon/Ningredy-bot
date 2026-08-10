export const normalizeImageCandidate = (rawUrl, sourceUrl) => {
    let url;

    try {
        url = new URL(String(rawUrl).replaceAll('&amp;', '&'), sourceUrl);
    } catch {
        return null;
    }

    if (!['http:', 'https:'].includes(url.protocol) || url.username || url.password) {
        return null;
    }

    let normalized = url.toString();

    if (url.hostname.endsWith('bhphoto.com')) {
        normalized = normalized
            .replace('/multiple_images/thumbnails/', '/multiple_images/images500x500/')
            .replace('/images/smallimages/', '/images/images500x500/');
    }

    const pathSize = Number.parseInt(normalized.match(/images(\d+)x\d+/i)?.[1] || '0', 10);
    const knownNonImageExtension = /\.(?:svg|gif|ico|pdf|html?|json|xml)(?:$|[?#])/i;
    const nonPhotoMediaMarker = /(?:^|[\/_.?&=-])(?:video|poster|spin|360(?:view|degree)?)(?:[\/_.?&=-]|$)/i;

    if ((pathSize > 0 && pathSize < 200)
        || knownNonImageExtension.test(normalized)
        || nonPhotoMediaMarker.test(normalized)
        || /(?:logo|icon|sprite|badge|avatar|tracking|pixel|oldiemessage|\/images\/fb\/|favicon|social)/i.test(normalized)) {
        return null;
    }

    return normalized;
};

const PRODUCT_SECTION_SEGMENT = /^(?:specification|specifications|specs|overview|details|features|gallery|media|images?|photos?|product-images?|product-media)$/i;
const GALLERY_SECTION_SEGMENT = /^(?:gallery|media|images?|photos?|product-images?|product-media)$/i;

const comparableHost = (hostname) => String(hostname || '').toLowerCase().replace(/^www\./, '');
const pathSegments = (pathname) => String(pathname || '')
    .split('/')
    .map((segment) => {
        try {
            return decodeURIComponent(segment).trim().toLowerCase();
        } catch {
            return segment.trim().toLowerCase();
        }
    })
    .filter(Boolean);
const withoutProductSection = (segments) => PRODUCT_SECTION_SEGMENT.test(segments.at(-1) || '')
    ? segments.slice(0, -1)
    : segments;

// A gallery recipe may click a same-product tab that changes the pathname
// (for example /Specification -> /Gallery). Exact-path navigation remains
// allowed, while a changed path must stay on the same host, explicitly target
// a gallery/media section, and preserve a non-trivial product-root path. This
// keeps generic category/search links out without baking in any site name.
export const isAllowedProductNavigation = (sourceRawUrl, targetRawUrl) => {
    let source;
    let target;

    try {
        source = new URL(sourceRawUrl);
        target = new URL(targetRawUrl, source);
    } catch {
        return false;
    }

    if (!['http:', 'https:'].includes(target.protocol)
        || comparableHost(source.hostname) !== comparableHost(target.hostname)) {
        return false;
    }

    const sourcePath = source.pathname.replace(/\/+$/, '') || '/';
    const targetPath = target.pathname.replace(/\/+$/, '') || '/';

    if (sourcePath.toLowerCase() === targetPath.toLowerCase()) {
        return true;
    }

    const sourceSegments = pathSegments(sourcePath);
    const targetSegments = pathSegments(targetPath);

    if (!GALLERY_SECTION_SEGMENT.test(targetSegments.at(-1) || '')) {
        return false;
    }

    const sourceRoot = withoutProductSection(sourceSegments);
    const targetRoot = withoutProductSection(targetSegments);

    return sourceRoot.length >= 2
        && sourceRoot.length === targetRoot.length
        && sourceRoot.every((segment, index) => segment === targetRoot[index]);
};

// Shopify encodes size as a filename suffix, not a query param - e.g.
// "photo_180x.png", "photo_600x600.jpg", "photo_grande.jpg",
// "photo_1920x@2x.png". The generic width/height query-param stripping below
// never sees this, so two renditions of the exact same photo (a gallery
// thumbnail and its full-size original) get different keys and both get
// kept as if they were distinct photos. isShopifyCdnUrl() covers both the
// cdn.shopify.com domain and every merchant's own domain proxying through
// "/cdn/shop/files|products/" (the vast majority of Shopify stores use a
// custom domain, not the raw myshopify.com one).
const SHOPIFY_SIZE_SUFFIX = /_(?:\d+x\d*|pico|icon|thumb|small|compact|medium|large|grande|original|master)(?:@\dx)?(?=\.[a-z0-9]+(?:$|\?))/i;

export const isShopifyCdnUrl = (url) => url.hostname.endsWith('shopify.com')
    || /\/cdn\/shop\/(?:files|products)\//i.test(url.pathname);

export const imageAssetKey = (rawUrl) => {
    const url = new URL(rawUrl);

    if (url.hostname.endsWith('bhphoto.com')) {
        return 'bh:'+(url.pathname.split('/').pop()?.toLowerCase() || '');
    }

    if (url.hostname.endsWith('media-amazon.com')) {
        return 'amazon:'+url.pathname.replace(/\._[^/]+(?=\.[^./]+$)/, '').toLowerCase();
    }

    // ASUS serves size variants of one photo as a trailing //w48, //w64, //w96,
    // or //w184 path segment (not a query param) - same photo, same key
    // regardless of which size was linked. Mirrors the PHP-side
    // ProductImageStorage::normalizeCandidateUrl() ASUS rule.
    if (url.hostname.endsWith('dlcdnwebimgs.asus.com')) {
        return 'asus:'+url.hostname.toLowerCase()
            +url.pathname.toLowerCase().replace(/\/\/w(?:48|64|96|184)$/i, '');
    }

    if (url.pathname.includes('/is/image/')) {
        url.search = '';
        url.hash = '';

        return 'dynamic-image:'+url.hostname.toLowerCase()+url.pathname.toLowerCase();
    }

    if (isShopifyCdnUrl(url)) {
        url.pathname = url.pathname.replace(SHOPIFY_SIZE_SUFFIX, '');
    }

    for (const key of ['width', 'height', 'w', 'h', 'quality', 'q', 'fit']) {
        url.searchParams.delete(key);
    }

    return url.toString();
};

export const urlQualityScore = (rawUrl) => {
    const url = new URL(rawUrl);
    const pathSize = Number.parseInt(url.pathname.match(/images(\d+)x\d+/i)?.[1] || '0', 10);
    const querySize = Math.max(
        Number.parseInt(url.searchParams.get('width') || url.searchParams.get('w') || '0', 10),
        Number.parseInt(url.searchParams.get('height') || url.searchParams.get('h') || '0', 10),
    );
    const amazonSize = Number.parseInt(url.pathname.match(/\._(?:AC_)?S(?:L|X|Y)(\d+)/i)?.[1] || '0', 10);
    const shopifySize = isShopifyCdnUrl(url)
        ? Number.parseInt(url.pathname.match(/_(\d+)x\d*(?:@\dx)?(?=\.[a-z0-9]+$)/i)?.[1] || '0', 10)
        : 0;

    return Math.max(pathSize, querySize, amazonSize, shopifySize);
};

// Many catalog CDNs (Magento-style stores, Next.js image optimizers, etc.)
// expose a resizable image through a width/height query parameter. A
// thumbnail caught at e.g. width=140 fails the minimum-side check even
// though the exact same asset is available full-size at the same path -
// bumping the parameter before giving up recovers it instead of discarding
// a real, distinct product photo just because of the size it happened to
// be observed at.
export const UPSCALED_SIZE_PARAM_VALUE = 1600;

export const withUpscaledSizeParam = (rawUrl) => {
    let url;

    try {
        url = new URL(rawUrl);
    } catch {
        return null;
    }

    const sizeKeys = ['width', 'w', 'height', 'h'];
    let changed = false;

    for (const key of sizeKeys) {
        if (!url.searchParams.has(key)) {
            continue;
        }

        const current = Number.parseInt(url.searchParams.get(key), 10);

        if (Number.isFinite(current) && current > 0 && current < UPSCALED_SIZE_PARAM_VALUE) {
            url.searchParams.set(key, String(UPSCALED_SIZE_PARAM_VALUE));
            changed = true;
        }
    }

    // Shopify has no upscale query param to bump - stripping the filename
    // size suffix instead asks the CDN for the original, unmodified master
    // asset (mirrors the Scene7 "omit the modifier entirely" case above).
    if (isShopifyCdnUrl(url)) {
        const stripped = url.pathname.replace(SHOPIFY_SIZE_SUFFIX, '');

        if (stripped !== url.pathname) {
            url.pathname = stripped;
            changed = true;
        }
    }

    return changed ? url.toString() : null;
};

export const galleryProbeMinimumSide = ({
    minimumSide,
    confirmedMinimumSide,
    galleryPresent,
    expectedCount,
    observedCount,
}) => {
    const configured = Math.max(100, Number.parseInt(minimumSide || '600', 10));
    const fallback = Math.max(100, Number.parseInt(confirmedMinimumSide || '400', 10));
    const expected = Math.max(0, Number.parseInt(expectedCount || '0', 10));
    const observed = Math.max(0, Number.parseInt(observedCount || '0', 10));
    const reachedGallery = galleryPresent === true && expected >= 2 && observed >= expected;

    return reachedGallery ? Math.min(configured, fallback) : configured;
};
