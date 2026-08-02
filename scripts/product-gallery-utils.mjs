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

export const imageAssetKey = (rawUrl) => {
    const url = new URL(rawUrl);

    if (url.hostname.endsWith('bhphoto.com')) {
        return 'bh:'+(url.pathname.split('/').pop()?.toLowerCase() || '');
    }

    if (url.hostname.endsWith('media-amazon.com')) {
        return 'amazon:'+url.pathname.replace(/\._[^/]+(?=\.[^./]+$)/, '').toLowerCase();
    }

    if (url.pathname.includes('/is/image/')) {
        url.search = '';
        url.hash = '';

        return 'dynamic-image:'+url.hostname.toLowerCase()+url.pathname.toLowerCase();
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

    return Math.max(pathSize, querySize, amazonSize);
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
