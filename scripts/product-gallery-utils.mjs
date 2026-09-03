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

// Product pages commonly contain several image carousels. Only the primary
// product-media surface is a gallery; recommendations, sponsored products,
// reviews and accessory rails are separate commercial/content blocks even
// when their CSS says "carousel" or "slider". Keep this semantic rule shared
// by the scout, browser runner and tests instead of teaching site names.
export const EXCLUDED_GALLERY_CONTEXT_PATTERN_SOURCE = String.raw`(?:^|[\\s_:/-])(?:recommend(?:ed|ation|ations)?|related|similar|sponsored|cross[\\s_-]?sell|upsell|frequently[\\s_-]?bought|also[\\s_-]?(?:bought|viewed)|customers?[\\s_-]?(?:also|buy|bought|viewed)|other[\\s_-]?(?:items?|products?)|add[\\s_-]?ons?|review|ratings?|customer[\\s_-]?(?:image|photo|media)|community|inspiration)(?:[\\s_:/-]|$)`;

export const galleryContextLooksExcluded = (signal) => new RegExp(
    EXCLUDED_GALLERY_CONTEXT_PATTERN_SOURCE.replaceAll('\\\\', '\\'),
    'i',
).test(String(signal || '').replace(/([a-z])([A-Z])/g, '$1 $2'));

const PRODUCT_SECTION_SEGMENT = /^(?:sp|specification|specifications|specs|overview|details|features|gallery|media|images?|photos?|product-images?|product-media)$/i;
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
const RENDITION_DIRECTORY = /\/(?:thumb(?:nail)?s?|small|medium|large|xlarge|xxlarge|original)\/(?=[^/]+$)/i;
// A bare "WxH" or "Nw" path segment (BigCommerce Stencil:
// /stencil/1280x1280/products/.../file.jpg vs /stencil/640w/... same file -
// a real case that reached production undeduped) is a size bucket, not part
// of the asset identity, wherever it sits in the path - unlike
// RENDITION_DIRECTORY above it is rarely adjacent to the filename. Mirrors
// ProductImageStorage::imageAssetKey()/candidateUrlQualityScore() - keep
// both in sync.
const SIZE_SEGMENT = /\/(?:[a-z][a-z_-]*)?\d{2,5}(?:x\d{2,5}|w)\//i;
// Some commerce CDNs keep one immutable UUID per physical photo and append a
// rendition marker to that same UUID (real examples: _720 and _sea).
// The bytes and canvas can differ enough for perceptual hashing to miss the
// duplicate, but the stable UUID proves that both URLs are one gallery frame.
const UUID_PHYSICAL_ASSET = /([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})(?:_(?:\d{2,5}|sea))?\.(?:jpe?g|png|webp|gif|avif)$/i;

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

    url.pathname = url.pathname.replace(UUID_PHYSICAL_ASSET, '$1.__image__');
    url.pathname = url.pathname.replace(SIZE_SEGMENT, '/__rendition__/');

    // Many commerce galleries keep the same filename under rendition
    // directories such as /large/ and /xlarge/. They are one physical frame,
    // not two slider photos. Keep the real URL for probing, but collapse its
    // asset key so the highest-quality rendition wins.
    url.pathname = url.pathname.replace(RENDITION_DIRECTORY, '/__rendition__/');

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
    const renditionSize = ({
        thumb: 100,
        thumbnail: 100,
        thumbnails: 100,
        small: 300,
        medium: 600,
        large: 1000,
        xlarge: 1600,
        xxlarge: 2200,
        original: 3000,
    })[url.pathname.match(/\/(thumb(?:nail)?s?|small|medium|large|xlarge|xxlarge|original)\/(?=[^/]+$)/i)?.[1]?.toLowerCase()] || 0;
    const uuidRendition = url.pathname.match(/_[a-z0-9]+(?=\.[a-z0-9]+$)/i)?.[0]?.slice(1).toLowerCase();
    const uuidRenditionSize = /^\d{2,5}$/.test(uuidRendition || '')
        ? Number.parseInt(uuidRendition, 10)
        : (uuidRendition === 'sea' ? 2000 : 0);
    const sizeSegmentMatch = url.pathname.match(/\/(?:[a-z][a-z_-]*)?(\d{2,5})(?:x(\d{2,5})|w)\//i);
    const sizeSegmentSize = sizeSegmentMatch
        ? Math.max(Number.parseInt(sizeSegmentMatch[1], 10), Number.parseInt(sizeSegmentMatch[2] || '0', 10))
        : 0;

    return Math.max(pathSize, querySize, amazonSize, shopifySize, renditionSize, uuidRenditionSize, sizeSegmentSize);
};

// Probe one best rendition from every physical gallery frame before spending
// the finite probe budget on second/third size variants of earlier frames.
// Shopify srcsets can expose a dozen URLs per photo; preserving raw DOM order
// otherwise lets the first five photos consume the whole probe budget while
// later gallery frames are never checked.
export const prioritizeCandidateRenditions = (candidates) => {
    const groups = new Map();

    for (const candidate of candidates) {
        let key;

        try {
            key = imageAssetKey(candidate);
        } catch {
            continue;
        }

        const group = groups.get(key) || [];

        if (!group.includes(candidate)) {
            group.push(candidate);
            groups.set(key, group);
        }
    }

    const rankedGroups = [...groups.values()].map((group) => group.sort(
        (left, right) => urlQualityScore(right) - urlQualityScore(left),
    ));
    const prioritized = [];

    for (let rendition = 0; rankedGroups.some((group) => rendition < group.length); rendition++) {
        for (const group of rankedGroups) {
            if (rendition < group.length) {
                prioritized.push(group[rendition]);
            }
        }
    }

    return prioritized;
};

export const galleryCollectionTarget = (limit, expectedCount) => {
    const safeLimit = Math.max(1, Number.parseInt(limit || 1, 10));
    const safeExpected = Math.max(0, Number.parseInt(expectedCount || 0, 10));

    return safeExpected > 0 ? Math.min(safeLimit, safeExpected) : safeLimit;
};

export const recipeActionOpensGallery = (action) => Boolean(action)
    && /(?:open|expand|full.?screen|lightbox|zoom|viewer|view.?all)/i.test(action.purpose || '');

export const recipeActionTraversesGallery = (action) => Boolean(action)
    && (action.kind !== 'click'
        || /(?:thumbnail|next|previous|arrow|visit|traverse|each|all images)/i.test(action.purpose || ''));

export const imageDimensionsMeetMinimum = ({
    width,
    height,
    minimumWidth,
    minimumHeight,
}) => {
    const imageWidth = Math.max(0, Number.parseInt(width || '0', 10));
    const imageHeight = Math.max(0, Number.parseInt(height || '0', 10));
    const requiredWidth = Math.max(100, Number.parseInt(minimumWidth || '700', 10));
    const requiredHeight = Math.max(0, Number.parseInt(minimumHeight ?? '0', 10));

    return imageWidth >= requiredWidth && imageHeight >= requiredHeight;
};

const SAFE_RECIPE_ACTION_KINDS = new Set([
    'click',
    'click_each',
    'click_until_no_change',
]);

const safeRecipeSelector = (selector) => typeof selector === 'string'
    && selector.trim() !== ''
    && selector.length <= 300
    && !/(?:javascript:|https?:|file:|xpath|script\b|iframe\b)/i.test(selector);

// AI can choose the browser sequence, but only through this small,
// deterministic action language. Invalid or over-budget steps disappear
// before Playwright sees them; no JavaScript, typing, form submission or
// arbitrary navigation can enter the runner through a recipe.
export const normalizeRecipeActions = (actions) => (Array.isArray(actions) ? actions : [])
    .slice(0, 12)
    .filter((action) => action && typeof action === 'object'
        && SAFE_RECIPE_ACTION_KINDS.has(action.kind)
        && safeRecipeSelector(action.selector))
    .map((action) => {
        const normalized = {
            kind: action.kind,
            selector: action.selector.trim(),
            index: Math.max(0, Math.min(20, Number.parseInt(action.index || '0', 10) || 0)),
            limit: Math.max(1, Math.min(20, Number.parseInt(action.limit || '1', 10) || 1)),
            wait_after_ms: Math.max(50, Math.min(1500, Number.parseInt(action.wait_after_ms || '250', 10) || 250)),
            purpose: typeof action.purpose === 'string' ? action.purpose.slice(0, 200) : '',
        };
        // A plain click carries a follow-up too. The opening click is what puts
        // the first frame on screen, and with the zoom control attachable only
        // to the traversal that follows it, that frame stayed at the viewer's
        // default size while every frame reached by an arrow was enlarged - one
        // gallery, two resolutions.
        const afterEachSelector = ['click', 'click_each'].includes(action.kind)
            && safeRecipeSelector(action.after_each_selector)
            ? action.after_each_selector.trim()
            : null;

        if (afterEachSelector) {
            normalized.after_each_selector = afterEachSelector;
            normalized.after_each_limit = Math.max(
                1,
                Math.min(20, Number.parseInt(action.after_each_limit || '1', 10) || 1),
            );
            normalized.after_each_wait_after_ms = Math.max(
                50,
                Math.min(1500, Number.parseInt(action.after_each_wait_after_ms || '250', 10) || 250),
            );
        }

        return normalized;
    });

// URL count is deliberately absent from this contract. An AI recipe is a
// mandatory browser program: every declared step must execute according to
// its action semantics before the recipe can be published or reused.
export const recipeActionPlanStatus = ({ actions, actionTrace }) => {
    const safeActions = normalizeRecipeActions(actions);
    const safeTrace = Array.isArray(actionTrace) ? actionTrace : [];
    const steps = safeActions.map((action, actionIndex) => {
        const traces = safeTrace.filter((item) => item
            && typeof item === 'object'
            && item.action === action.kind
            && Number.parseInt(item.action_index, 10) === actionIndex);
        const primaryTraces = traces.filter((item) => item.after_each !== true);
        const clicked = primaryTraces.filter((item) => item.clicked === true);
        const afterEachTraces = traces.filter((item) => item.after_each === true);
        const selectorMatches = traces.reduce(
            (maximum, item) => Math.max(maximum, Number.parseInt(item.selector_match_count || '0', 10) || 0),
            0,
        );
        let requiredClicks = 1;
        let complete = false;
        let completion = 'not_executed';

        // Whether every declared follow-up actually ran, by the same rule the
        // server-side validator applies - a control that stopped changing or
        // disappeared has finished, anything else owes the remaining presses.
        const afterEachComplete = (presses) => {
            if (!action.after_each_selector) {
                return true;
            }

            const afterEachLimit = Math.max(1, action.after_each_limit || 1);

            for (let repetition = 0; repetition < presses; repetition++) {
                const ofThisPress = afterEachTraces.filter(
                    (item) => Number.parseInt(item.parent_repetition, 10) === repetition,
                );
                const followups = ofThisPress.filter((item) => item.clicked === true);
                const exhausted = followups.some((item) => item.changed === false)
                    || (followups.length > 0 && ofThisPress.some((item) => item.selector_missing === true));

                if (!exhausted && followups.length < afterEachLimit) {
                    return false;
                }
            }

            return true;
        };

        if (action.kind === 'click') {
            const openerWorked = !recipeActionOpensGallery(action)
                || clicked.some((item) => item.changed === true || item.expanded_gallery_visible_after === true);
            complete = clicked.length >= 1 && openerWorked;
            completion = complete
                ? 'clicked'
                : (clicked.length ? 'gallery_did_not_open' : 'not_clicked');

            if (complete && !afterEachComplete(1)) {
                complete = false;
                completion = 'after_each_incomplete';
            }
        } else if (action.kind === 'click_each') {
            // Same split as the server-side validator: several matched controls
            // are walked one each and can never need more clicks than they have
            // elements, while a single control is a next arrow re-pressed limit
            // times. Capping that one at the match count reported a one-click
            // traversal as 'all_matches_clicked' - telling the training agent
            // its plan had worked while the validator was rejecting the very
            // same run for stopping early.
            requiredClicks = selectorMatches > 1
                ? Math.min(action.limit, selectorMatches)
                : action.limit;
            const exhausted = selectorMatches <= 1
                && primaryTraces.some((item) => item.clicked === true && item.changed === false);
            const presses = exhausted ? clicked.length : requiredClicks;
            complete = exhausted || clicked.length >= requiredClicks;
            completion = complete ? 'all_matches_clicked' : 'matches_left_unclicked';

            if (complete && !afterEachComplete(presses)) {
                complete = false;
                completion = 'after_each_incomplete';
            }
        } else if (action.kind === 'click_until_no_change') {
            const exhausted = clicked.some((item) => item.changed === false);
            complete = exhausted || clicked.length >= action.limit;
            requiredClicks = exhausted ? clicked.length : action.limit;
            completion = complete
                ? (exhausted ? 'no_change_reached' : 'limit_reached')
                : 'traversal_interrupted';
        }

        return {
            action_index: actionIndex,
            kind: action.kind,
            selector: action.selector,
            required_clicks: requiredClicks,
            completed_clicks: clicked.length,
            selector_match_count: selectorMatches,
            complete,
            completion,
        };
    });

    return {
        required: safeActions.length > 0,
        complete: steps.every((step) => step.complete),
        total_actions: steps.length,
        completed_actions: steps.filter((step) => step.complete).length,
        steps,
    };
};

// A detached or covered thumbnail must not abort traversal of every remaining
// thumbnail. Its failed click stays in the trace, so validation still rejects
// an incomplete plan and the next AI round receives exact feedback.
//
// matchCount separates the two shapes a click_each can take. With several
// matching elements every repetition targets a different one, so an unchanged
// click says nothing about the next. With exactly one match - the usual shape
// of a next/prev arrow - every repetition re-presses the very same control, so
// once it stops responding the remaining repetitions provably cannot help.
export const recipeActionShouldStop = ({ kind, clicked, changed, matchCount }) => {
    if (!clicked) {
        return kind !== 'click_each';
    }

    if (kind === 'click_until_no_change') {
        return changed !== true;
    }

    return kind === 'click_each' && matchCount === 1 && changed !== true;
};
