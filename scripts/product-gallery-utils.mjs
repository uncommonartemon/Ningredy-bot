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

// Mirrors ProductGalleryRecipeResultValidator: the largest after_each_limit the
// recipe schema accepts, so a zoom already asking for the maximum is judged the
// same way on both sides.
const AFTER_EACH_LIMIT_CEILING = 20;

// Safety ceilings, never targets. A recipe says how to walk a gallery and
// nothing about its size, so traversal ends when the gallery stops yielding
// new photographs; these only stop a page that would otherwise spin for ever.
// Hitting one means the result is incomplete, not that it is finished.
export const TRAVERSAL_CEILING = 40;

/**
 * A number learned during training is a floor for safety, never a target.
 *
 * Three of them still worked as targets: a recipe trained on a laptop with
 * seven photographs capped the next laptop's thirteen-thumbnail strip at seven,
 * stopped its carousel seven presses in, and cut short a zoom ladder the page
 * was still climbing - the last of which the runner already noticed and
 * recorded as after_each_truncated before stopping anyway.
 *
 * Zero keeps its meaning: the agent saying a control should not be used at all
 * is structural, not a count. Anything above zero means "at least this many",
 * and what stops the walk is running out of controls or the page ceasing to
 * change.
 */
export const traversalCeiling = (declared, fallback = TRAVERSAL_CEILING) => {
    const value = Number.isInteger(declared) ? declared : fallback;

    return value <= 0 ? 0 : Math.max(value, TRAVERSAL_CEILING);
};

// Presses in a row that add no photograph before a single control is called
// exhausted. More than one because a slide can legitimately give nothing: a
// video, a repeated frame, an image still loading.
export const TRAVERSAL_PATIENCE = 3;

/**
 * What the page publishes about the product it is showing.
 *
 * Read from the markup shops write for search engines and price comparison,
 * which is the same everywhere and in no language. Identifiers are kept apart
 * by their kind: a sku, an mpn and a gtin live in different namespaces, and a
 * value found under one of them proves nothing about a value found under
 * another.
 *
 * Anything the page does not publish stays absent. An earlier version resolved
 * a missing canonical through new URL('', location.href), which returns the
 * current address - so every page without one reported its own path as its
 * canonical, and /product?id=A matched /product?id=B. Absence must survive as
 * absence, because the whole point of this is to know when we do not know.
 */
export const readProductIdentityInPage = () => {
    const attr = (selector, name) => {
        const value = document.querySelector(selector)?.getAttribute(name);

        return typeof value === 'string' && value.trim() !== '' ? value.trim() : null;
    };
    const path = (raw) => {
        if (!raw) {
            return null;
        }

        try {
            const url = new URL(raw, location.href);
            if (!['http:', 'https:'].includes(url.protocol)) {
                return null;
            }
            // Query parameters can select the product or its configuration.
            // Preserve host and case too: a path alone is not an identity.
            return url.origin + (url.pathname.replace(/\/+$/, '') || '/') + url.search;
        } catch {
            return null;
        }
    };
    const products = [];

    for (const node of document.querySelectorAll('script[type="application/ld+json"]')) {
        try {
            const parsed = JSON.parse(node.textContent || '');
            const queue = Array.isArray(parsed) ? [...parsed] : [parsed];

            while (queue.length && products.length < 8) {
                const item = queue.shift();

                if (!item || typeof item !== 'object') {
                    continue;
                }

                if (Array.isArray(item['@graph'])) {
                    queue.push(...item['@graph']);
                }

                const types = [].concat(item['@type'] || []).map((type) => String(type).toLowerCase());

                if (types.includes('product')) {
                    products.push(item);
                }
            }
        } catch {
            // A shop with malformed JSON-LD simply offers no evidence here.
        }
    }

    const firstString = (values) => values
        .map((value) => (typeof value === 'string' || typeof value === 'number' ? String(value).trim() : ''))
        .find((value) => value !== '') || null;
    const identifiers = {};

    for (const key of ['sku', 'mpn', 'productID', 'gtin13', 'gtin12', 'gtin8', 'gtin']) {
        const value = firstString(products.map((item) => item[key]));

        if (value !== null) {
            identifiers[key] = value.toLowerCase();
        }
    }

    const name = firstString(products.map((item) => item.name)) || attr('meta[property="og:title"]', 'content');

    return {
        canonical: path(attr('link[rel="canonical"]', 'href')),
        og_url: path(attr('meta[property="og:url"]', 'content')),
        identifiers,
        name: name === null ? null : name.slice(0, 200).toLowerCase(),
    };
};

/**
 * Whether two pages are the same product, judged on what each published.
 *
 * Three answers, and the third one matters most: true, false, and null for "no
 * comparable evidence". Null is not a soft yes. A caller that turns it into one
 * has rebuilt the thing this replaced - a rule that guesses identity from the
 * shape of a URL, where /store/laptops/model-a and /store/laptops/model-b look
 * exactly as related as /product/spec and /product/gallery do.
 *
 * Identifiers are compared within their own kind only. A name is deliberately
 * asymmetric: two different names are two different products, but one name is
 * shared by every configuration of a laptop, so an equal name proves nothing
 * and yields null rather than true.
 */
export const sameProductIdentity = (expected, landed) => {
    if (!expected || !landed) {
        return null;
    }

    const mine = expected.identifiers || {};
    const theirs = landed.identifiers || {};

    const comparable = Object.keys(mine).filter((key) => mine[key] && theirs[key]);
    if (comparable.length > 0) {
        // A shared family identifier must not conceal a conflicting SKU/GTIN.
        return comparable.every((key) => mine[key] === theirs[key]);
    }

    for (const key of ['canonical', 'og_url']) {
        if (expected[key] && landed[key]) {
            // OpenGraph commonly describes the current tab, not a canonical
            // product identity. Different tab URLs are inconclusive, not a
            // conflict. Typed identifiers above still reject a different SKU.
            if (key === 'og_url' && expected[key] !== landed[key]) continue;
            return expected[key] === landed[key];
        }
    }

    // Enough to rule out, never enough to confirm.
    if (expected.name && landed.name && expected.name !== landed.name) {
        return false;
    }

    return null;
};

/**
 * Whether one Playwright request (from page.route()'s callback) should be
 * refused because it is a main-frame document navigation - the page itself
 * moving to a different address, the very first load included - to a host
 * other than confineHost. Applies to nothing else: a falsy confineHost (the
 * default - opt-in only), a non-navigation request (images, scripts,
 * stylesheets, XHR/fetch, fonts - a CDN on a different host from the page
 * is completely normal), or a navigation inside a sub-frame/iframe all
 * return false, unaffected. Blocked before the request is ever sent, not
 * detected afterward from wherever the page ended up - the one property
 * that actually stops allowed-host -> other-host -> back-to-allowed-host,
 * three requests none of which a start-vs-end comparison alone would catch.
 *
 * @param {{isNavigationRequest?: () => boolean, frame?: () => unknown, url: () => string}} request
 * @param {unknown} mainFrame
 * @param {string} confineHost
 */
export const isNavigationRequestOffConfinedHost = (request, mainFrame, confineHost) => {
    if (!confineHost) {
        return false;
    }

    if (typeof request.isNavigationRequest === 'function' && !request.isNavigationRequest()) {
        return false;
    }

    if (typeof request.frame === 'function' && request.frame() !== mainFrame) {
        return false;
    }

    try {
        return new URL(request.url()).hostname.toLowerCase() !== confineHost;
    } catch {
        // Not a parseable http(s) URL - refused, not guessed at.
        return true;
    }
};

// Same generic definition everywhere a page's clickable/expandable controls
// are found - no name/word list, so a "характеристики" tab is found exactly
// as readily as any other.
export const OBSERVED_CONTROL_SELECTOR = 'button, [role="tab"], [role="button"], [aria-expanded], summary, a[href^="#"]';

/**
 * Real, currently-visible clickable controls on the page, each with a
 * selector guaranteed to resolve to that ONE element and nothing else.
 *
 * id/data-testid/aria-controls/name/class are tried in that order, but none
 * of them are unique by construction - three tabs sharing one class
 * ("Описание"/"Характеристики"/"Отзывы" all `<button class="tab-btn">`) used
 * to collapse into one indistinguishable selector, silently dropping every
 * tab but the first found in DOM order from the result and leaving no way
 * to ask for one of the others specifically. Whichever attribute is tried,
 * its match count against THIS document is checked: more than one match
 * appends Playwright's own `>> nth=N` (this element's own index within that
 * match set) so the returned selector always resolves to exactly the
 * element it was read from, shared class or not.
 */
export const captureObservedControlsInPage = () => {
    const isVisible = (element) => {
        const rect = element.getBoundingClientRect();
        const style = getComputedStyle(element);

        return rect.width > 1 && rect.height > 1
            && style.display !== 'none'
            && style.visibility !== 'hidden';
    };
    const uniqueSelector = (candidate, element) => {
        let matches;

        try {
            matches = [...document.querySelectorAll(candidate)];
        } catch {
            return null;
        }

        if (matches.length === 0) {
            return null;
        }

        if (matches.length === 1) {
            return candidate;
        }

        const index = matches.indexOf(element);

        return index === -1 ? null : `${candidate} >> nth=${index}`;
    };
    const stableSelectorFor = (element) => {
        const id = element.getAttribute('id');
        const candidates = [];

        if (id && id.length <= 100) {
            candidates.push(`#${CSS.escape(id)}`);
        }

        for (const name of ['data-testid', 'data-test', 'data-selenium', 'data-qa', 'aria-controls', 'name']) {
            const value = element.getAttribute(name);

            if (value && value.length <= 160) {
                candidates.push(`${element.tagName.toLowerCase()}[${name}=${JSON.stringify(value)}]`);
            }
        }

        const classTokens = [...element.classList]
            .filter((token) => token.length >= 3
                && token.length <= 60
                && !/^\d/.test(token)
                && !/[a-f0-9]{8,}/i.test(token))
            .slice(0, 2);

        if (classTokens.length) {
            candidates.push(element.tagName.toLowerCase() + classTokens.map((token) => `.${CSS.escape(token)}`).join(''));
        }

        for (const candidate of candidates) {
            const resolved = uniqueSelector(candidate, element);

            if (resolved) {
                return resolved;
            }
        }

        return null;
    };
    const seenSelectors = new Set();

    return [...document.querySelectorAll(
        'button, [role="tab"], [role="button"], [aria-expanded], summary, a[href^="#"]',
    )]
        .filter(isVisible)
        .map((element) => ({
            selector: stableSelectorFor(element),
            text: (element.textContent || '').trim().replace(/\s+/g, ' ').slice(0, 120),
        }))
        .filter(({ selector, text }) => {
            if (!selector || !text || seenSelectors.has(selector)) {
                return false;
            }

            seenSelectors.add(selector);

            return true;
        })
        .slice(0, 20);
};

/**
 * The structural candidates revealedContainerTextInPage() considers for one
 * specific clicked element, their current visibility and text - meant
 * to be called once BEFORE the click (via Locator.evaluate()) so its result
 * can be handed to revealedContainerTextInPage() afterward as the baseline
 * to compare against. The snapshot uses DOM node identity, not list order.
 * Only its numeric ID leaves the page; node references and text stay in a
 * WeakMap until the next snapshot replaces it. Both functions run through
 * Locator.evaluate(), which serializes and re-runs a function's own source
 * in the page with no access to anything outside it.
 *
 * @return {number} Page-local snapshot ID, not a list of visibility flags.
 */
export const revealedCandidatesVisibilityInPage = (element) => {
    const isVisible = (node) => {
        if (!node) {
            return false;
        }

        const rect = node.getBoundingClientRect();
        const style = getComputedStyle(node);

        return rect.width > 1 && rect.height > 1
            && style.display !== 'none'
            && style.visibility !== 'hidden';
    };
    const followingSiblings = (node) => {
        const siblings = [];
        let current = node?.nextElementSibling || null;

        while (current) {
            siblings.push(current);
            current = current.nextElementSibling;
        }

        return siblings;
    };
    const controlsId = element.getAttribute('aria-controls');
    const candidates = [
        controlsId ? document.getElementById(controlsId) : null,
        element.closest('[role="tabpanel"], [role="region"]'),
        ...followingSiblings(element),
        ...followingSiblings(element.parentElement),
        element.parentElement,
    ];

    const key = Symbol.for('ningredy.revealed-panel-snapshot');
    const id = (globalThis[key]?.id || 0) + 1;
    const nodes = new WeakMap();
    for (const node of candidates) {
        if (node) nodes.set(node, { visible: isVisible(node), text: (node.innerText || '').trim() });
    }
    // Keep DOM identity locally, not array positions; only an ID crosses evaluate().
    // One snapshot per page, replaced before the next sequential click.
    globalThis[key] = { id, nodes };
    return id;
};

/**
 * The specific area a just-clicked control revealed, read off the element
 * ITSELF (meant for Locator.evaluate(), which hands the already-resolved
 * DOM node to the callback) rather than a selector string re-parsed by
 * document.querySelector() inside the page - the selector
 * captureObservedControlsInPage() returns can be Playwright's own
 * `>> nth=N` chaining syntax when disambiguation was needed, which the
 * DOM's own querySelector does not understand and throws on; passing the
 * element Playwright already resolved sidesteps that entirely.
 *
 * beforeVisibility is revealedCandidatesVisibilityInPage()'s own result for
 * this SAME element, captured immediately BEFORE the click. Required for
 * every candidate past the first two (see explicitLinkCount below) - a
 * confirmed real case: three tabs "Описание"/"Характеристики"/"Отзывы" in a
 * row, opening the middle one. Its own next sibling in document order is
 * not its panel, it is the NEXT TAB'S OWN BUTTON - visible, non-empty, and
 * completely unrelated to this click, since tab buttons are always visible
 * whether just clicked or not. Being visible and non-empty is true of every
 * such candidate permanently. A new node, a visibility transition or a
 * text change is evidence; unchanged furniture is not. aria-controls and an
 * explicit [role="tabpanel"/"region"] ancestor are exempt from that
 * requirement: an author-declared link to the panel already IS the
 * explicit evidence pointed at in the follow-up ("использовать явную связь
 * с панелью либо наблюдаемое раскрытие/изменение после клика" - either
 * counts, not only the second). Missing beforeVisibility entirely (an older
 * caller, or the snapshot itself failed) disables every candidate that
 * would otherwise need it, rather than falling back to the old, disproven
 * "visible and non-empty" guess.
 *
 * Candidates, in order: aria-controls target; nearest [role="tabpanel"]/
 * [role="region"] ancestor - deliberately NOT a bare <section>/<article>,
 * which routinely wraps an entire tab GROUP rather than the one panel just
 * opened; every one of the clicked element's own following siblings, in
 * document order; the same walk over the PARENT's following siblings; and
 * only then the parent outright. DOM identity matches these candidates to
 * the snapshot even when siblings were inserted, removed or reordered.
 */
export const revealedContainerTextInPage = (element, beforeVisibility) => {
    const isVisible = (node) => {
        if (!node) {
            return false;
        }

        const rect = node.getBoundingClientRect();
        const style = getComputedStyle(node);

        return rect.width > 1 && rect.height > 1
            && style.display !== 'none'
            && style.visibility !== 'hidden';
    };
    const followingSiblings = (node) => {
        const siblings = [];
        let current = node?.nextElementSibling || null;

        while (current) {
            siblings.push(current);
            current = current.nextElementSibling;
        }

        return siblings;
    };
    const controlsId = element.getAttribute('aria-controls');
    const candidates = [
        controlsId ? document.getElementById(controlsId) : null,
        element.closest('[role="tabpanel"], [role="region"]'),
        ...followingSiblings(element),
        ...followingSiblings(element.parentElement),
        element.parentElement,
    ];
    // The first two are an explicit, author-declared link to the panel -
    // trusted on visibility alone. Every candidate after them is a
    // structural guess and must have actually changed.
    const explicitLinkCount = 2;
    const snapshot = globalThis[Symbol.for('ningredy.revealed-panel-snapshot')];
    const hasSnapshot = beforeVisibility != null && snapshot?.id === beforeVisibility;

    for (let index = 0; index < candidates.length; index += 1) {
        const candidate = candidates[index];

        if (!isVisible(candidate)) {
            continue;
        }

        const text = (candidate.innerText || '').trim();
        if (index >= explicitLinkCount) {
            if (!hasSnapshot) {
                continue;
            }

            const previous = snapshot.nodes.get(candidate);
            if (previous?.visible && previous.text === text) {
                // Already visible with unchanged text - permanent page
                // furniture (another tab's own button, standing content),
                // not something this click revealed.
                continue;
            }
        }

        if (text !== '') {
            return text;
        }
    }

    return '';
};

/**
 * The page's text, with revealedText (see revealedContainerTextInPage(),
 * computed separately and passed in as a plain string - never a selector)
 * hoisted to the front - not just concatenated, since a long, unrelated
 * description ahead of the revealed area used to push it past the fixed
 * head-truncation every caller applies (specification_text is capped,
 * deliberately, to keep the payload bounded) and the reveal was lost even
 * though the click itself worked. Passing '' (nothing clicked, an ordinary
 * read) is exactly the previous whole-page behaviour.
 */
export const capturePageTextInPage = (revealedText) => {
    return `${document.title}\n${revealedText || ''}\n${document.body?.innerText || ''}`.slice(0, 20_000);
};


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
// A gallery recipe may click a same-product tab that changes the pathname
// (for example /Specification -> /Gallery). This answers one question and no
// other: is the page we would land on still this product?
//
// It used to answer two, and the second one wrecked the first. "Is this a tab
// of the same product" was decided by a list of fourteen English tab names,
// and "is the target the gallery" by a list of six more. A path whose last
// segment was not on the list was a different page. The lists grew one word per
// shop - "sp" for gigabyte, "specification" for msi - and on 2026-09-07 the
// first of them refused this, verbatim from the run's own trace:
//
//   purpose:            "open the same-product Gallery page where the actual
//                        product photo set is exposed"
//   navigation_target:  /us/laptops/rog-strix/rog-strix-scar-18-2025/gallery/
//   navigation_blocked: true
//
// from /us/laptops/rog-strix/rog-strix-scar-18-2025/spec/ - the same product,
// one segment apart. "spec" was not on the list. Three training rounds ended
// as "no material progress" and the shop got no recipe.
//
// No vocabulary can finish either list: bilder, imagenes, galeria, datasheet,
// technische-daten, and whatever the next shop chooses. So there is none here.
//
// What is left is a cheap sieve, and it is important to be honest about what it
// cannot do. A path cannot tell one product from another: /store/laptops/model-a
// and /store/laptops/model-b differ exactly as much as /product/spec and
// /product/gallery do. This function therefore does NOT decide whether a link
// stays on the same product, and must never be extended to pretend it does -
// that pretence is what the tab-name list was.
//
// It refuses only what is wrong regardless of product: another host, and a jump
// two or more levels up, which is a category or the site root rather than
// anything belonging to a product page.
//
// Whether we actually landed on the same product is decided after arriving, by
// what the two pages publish about themselves - canonical, og:url, JSON-LD sku
// and name - in onProductPage(). That is evidence, it works in every language,
// and it is the only thing that can answer the question.
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

    const targetRoot = targetSegments.slice(0, -1);

    // Two or more segments, so a site-wide /gallery cannot pass for a product's.
    // At most one segment of slack, which is the source's own tab, whatever it
    // is called - more than that and the target is an ancestor, not a sibling.
    // A sibling that happens to be a different product passes here on purpose:
    // it is caught on arrival, where there is evidence to catch it with.
    return targetRoot.length >= 2
        && sourceSegments.length >= targetRoot.length
        && sourceSegments.length - targetRoot.length <= 1
        && targetRoot.every((segment, index) => segment === sourceSegments[index]);
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

// An address, not a number to be rescued: -1, "bad" and 1.5 are not "close
// enough" to a real index, they are not an index at all. parseInt() would
// happily turn each into a plausible-looking one (0, 0, 1) by reading
// however many leading digits it can find, which is a substitution with
// extra steps - so this accepts only a value that already, exactly, is a
// non-negative integer (or a string of nothing but digits), and returns
// null for anything else instead of guessing what was meant.
const strictNonNegativeIndex = (value) => {
    if (Number.isInteger(value) && value >= 0) {
        return value;
    }

    if (typeof value === 'string' && /^\d+$/.test(value.trim())) {
        return Number.parseInt(value, 10);
    }

    return null;
};

/**
 * Which specific match of a selector one recipe action should click -
 * index is the ADDRESS of one particular element, not a repeat count, and
 * clamping a requested index down to whatever is available used to click a
 * different, unintended element instead (real case, 2026-09-14:
 * techbuy.com.au asked for a selector's 46th match; clamping clicked its
 * 20th - a control the recipe never actually named).
 *
 * Returns the requested index unchanged when that many matches actually
 * exist, or null when they do not - null means "nothing to click", never
 * "click the closest one instead". The caller must report a null result as
 * the fact it is (requested index vs. matches found), not paper over it
 * with a substitute click. This includes a malformed requestedIndex itself
 * (-1, "bad", 1.5): none of those are a real address either, and are
 * rejected the same way an address that is real but absent from the page
 * would be, never coerced into one that happens to exist.
 */
export const resolveRecipeActionTargetIndex = (requestedIndex, matchCount) => {
    const index = strictNonNegativeIndex(requestedIndex);

    if (index === null || !Number.isInteger(matchCount) || matchCount < 1) {
        return null;
    }

    return index < matchCount ? index : null;
};

/**
 * Which index a single click_each/click repetition should even ask for,
 * before resolveRecipeActionTargetIndex() checks whether it exists. A real
 * strip of distinct elements (currentCount > 1) is walked one new element
 * per repetition, so repetition is added to index. A single control - one
 * "next" arrow, currentCount staying 1 for as long as it stays alone - has
 * nothing to add repetition to: it is the same element on every press, so
 * repetition must NOT change which index is requested.
 *
 * Regression, 2026-09-14: adding repetition unconditionally (regardless of
 * currentCount) asked a lone arrow's second press for index 1, which never
 * existed on a page with exactly one match, ending a real carousel walk
 * after a single frame. currentCount is read fresh on every repetition by
 * the caller, so a control that starts alone and later reveals a real
 * strip correctly switches to walking it.
 */
export const recipeActionRequestedIndex = (action, repetition, currentCount) => (
    action.kind === 'click_each' && currentCount > 1
        ? action.index + repetition
        : action.index
);

// AI can choose the browser sequence, but only through this small,
// deterministic action language. Invalid or over-budget steps disappear
// before Playwright sees them; no JavaScript, typing, form submission or
// arbitrary navigation can enter the runner through a recipe.
export const normalizeRecipeActions = (actions) => (Array.isArray(actions) ? actions : [])
    .slice(0, 12)
    .filter((action) => action && typeof action === 'object'
        && SAFE_RECIPE_ACTION_KINDS.has(action.kind)
        && safeRecipeSelector(action.selector)
        // A bad kind or selector already drops the whole action rather
        // than being coerced into a safe-looking one; index gets the same
        // treatment now instead of the one field still being repaired
        // (-1, "bad" and 1.5 used to quietly become 0, 0 and 1).
        && strictNonNegativeIndex(action.index) !== null)
    .map((action) => {
        const normalized = {
            kind: action.kind,
            selector: action.selector.trim(),
            // index addresses ONE specific element among the selector's
            // matches, not a repeat count - clamping it to 20 used to
            // silently retarget a legitimately higher request (a broad
            // selector's 46th match, a real case) onto a completely
            // different element (its 20th). An upper clamp here would be
            // that exact same bug: whether the requested index actually
            // exists among the page's current matches is resolved at
            // click time (resolveRecipeActionTargetIndex()), which
            // reports a miss rather than substituting another element -
            // a value with no matching element simply resolves to null
            // there, so there is nothing left for a ceiling to guard.
            // A malformed value never reaches here at all: the filter
            // above already dropped the action.
            index: strictNonNegativeIndex(action.index),
            limit: Math.max(1, Math.min(20, Number.parseInt(action.limit || '1', 10) || 1)),
            wait_after_ms: Math.max(50, Math.min(1500, Number.parseInt(action.wait_after_ms || '250', 10) || 250)),
            purpose: typeof action.purpose === 'string' ? action.purpose.slice(0, 200) : '',
            // The second whitelist the plan passes through, and the reason the
            // first one losing a field is so easy to miss: both have to know
            // about it or the browser is handed a step with the condition
            // stripped off, and treats a gate that is simply not up today as a
            // broken selector. Default always, so a legacy recipe is unchanged.
            when: action.when === 'if_present' ? 'if_present' : 'always',
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

        // The gate this step clears is not up on this visit. Reported as its own
        // outcome rather than as a completed click, so the agent reading the
        // trace can tell "nothing to clear" from "cleared it".
        if (traces.some((item) => item.optional_absent === true)) {
            return {
                action_index: actionIndex,
                kind: action.kind,
                selector: action.selector,
                required_clicks: 0,
                completed_clicks: 0,
                selector_match_count: 0,
                complete: true,
                completion: 'absent_not_required',
            };
        }

        // Whether every declared follow-up actually ran, by the same rule the
        // server-side validator applies - a control that stopped changing or
        // disappeared has finished, anything else owes the remaining presses.
        const afterEachComplete = (presses) => {
            if (!action.after_each_selector) {
                return true;
            }

            const afterEachLimit = Math.max(1, action.after_each_limit || 1);

            // The zoom ran out of allowed presses while the image was still
            // growing. The server rejects that and hands the round back, so
            // reporting the step as finished here would tell the agent its plan
            // worked while the same run was being sent back to it.
            if (afterEachLimit < AFTER_EACH_LIMIT_CEILING
                && afterEachTraces.some((item) => item.after_each_truncated === true)) {
                return false;
            }

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
                && primaryTraces.some((item) => item.clicked === true
                    && (item.changed === false || item.traversal_exhausted === true));
            const presses = exhausted ? clicked.length : requiredClicks;
            complete = exhausted || clicked.length >= requiredClicks;
            completion = complete ? 'all_matches_clicked' : 'matches_left_unclicked';

            if (complete && !afterEachComplete(presses)) {
                complete = false;
                completion = 'after_each_incomplete';
            }
        } else if (action.kind === 'click_until_no_change') {
            const exhausted = clicked.some((item) => item.changed === false || item.traversal_exhausted === true);
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

// Where browser-server.mjs advertises the shared browser, and where extraction
// looks for it. One definition so the two can never disagree about the path.
export const browserServerEndpointFile = (projectRoot) => `${projectRoot}/storage/app/browser-server.json`;

/**
 * Consent walls, region pickers and newsletter popups sit on top of the page,
 * so the DOM handed to the agent is whatever the overlay left visible - and the
 * agent then spends a paid round writing a click for a cookie banner instead of
 * reading the gallery. Two site-specific dismissals used to live here (an
 * Amazon element id and an English "Continue shopping" label), which only ever
 * covered the two shops someone had hit.
 *
 * This finds blockers by what they do rather than by who wrote them: fixed or
 * sticky, actually covering the middle of the viewport, and containing no
 * product image - a gallery viewer is itself a dialog, and closing it would
 * throw away the very thing we came for. The affirmative control is matched by
 * label across the languages these shops actually serve.
 *
 * Whatever is dismissed, and whatever refuses to go, is reported to the agent,
 * so it never has to guess whether it is looking at the real page.
 */
/**
 * Spend a couple of seconds on the page the way a person would.
 *
 * Arriving, reading the entire DOM in forty milliseconds and leaving is a
 * behaviour no visitor has, and behaviour is what a shop watches once the
 * headers all look right - it is the signal no fingerprint work can hide.
 *
 * It also does the one thing a gallery needs anyway: frames below the fold are
 * lazy-loaded, and nothing loads them but scrolling past. The page is returned
 * to the top so everything after this sees the document it expected.
 *
 * Bounded and cheap by construction - three or four short steps, never more
 * than about two and a half seconds of a forty-five second budget.
 */
export const settleLikeAReader = async (page) => {
    const steps = 2 + Math.floor(Math.random() * 2);

    for (let step = 0; step < steps; step += 1) {
        await page.mouse.wheel(0, 320 + Math.floor(Math.random() * 520)).catch(() => {});
        await page.waitForTimeout(240 + Math.floor(Math.random() * 380));
    }

    await page.evaluate(() => window.scrollTo({ top: 0 })).catch(() => {});
    await page.waitForTimeout(120);
};

export const OVERLAY_ACCEPT_LABELS = [
    'accept', 'agree', 'allow', 'ok', 'got it', 'understood', 'continue', 'close', 'dismiss', 'no thanks',
    'принять', 'согласен', 'соглашаюсь', 'хорошо', 'закрыть', 'продолжить',
    'souhlas', 'rozumím', 'přijmout', 'zavřít',
    'akzeptieren', 'zustimmen', 'einverstanden', 'schließen',
    'aceptar', 'acepto', 'cerrar',
    'accepter', 'j\'accepte', 'fermer',
    'accetta', 'akkoord', 'accepteren', 'godkänn', 'zaakceptuj', 'zgadzam',
];

export const clearBlockingOverlays = async (target) => {
    const dismissed = [];

    for (let pass = 0; pass < 3; pass++) {
        const found = await target.evaluate((labels) => {
            const visible = (element) => {
                const style = getComputedStyle(element);
                const box = element.getBoundingClientRect();

                return style.visibility !== 'hidden'
                    && style.display !== 'none'
                    && Number.parseFloat(style.opacity || '1') > 0.05
                    && box.width > 40 && box.height > 30;
            };
            const centre = { x: innerWidth / 2, y: innerHeight / 2 };

            for (const element of document.querySelectorAll('div,section,aside,dialog,[role=dialog],[aria-modal=true]')) {
                if (element.dataset.ningredyOverlay || !visible(element)) {
                    continue;
                }

                const style = getComputedStyle(element);
                const box = element.getBoundingClientRect();
                const pinned = ['fixed', 'sticky'].includes(style.position)
                    || element.matches('dialog[open],[role=dialog],[aria-modal=true]');
                const coversCentre = box.left <= centre.x && box.right >= centre.x
                    && box.top <= centre.y && box.bottom >= centre.y;
                const coversMuch = (box.width * box.height) >= (innerWidth * innerHeight) * 0.2;

                // The gallery viewer is a dialog too. Anything holding a real
                // product image is the content, never the obstacle.
                // Any image at all was too strict: consent banners carry a brand
                // logo, and one 32px logo made the whole wall untouchable. What
                // must never be closed is an overlay holding a photograph, so
                // the test is size - a product shot is large, a logo is not.
                const holdsPhotograph = [...element.querySelectorAll('img,picture,source')].some((media) => {
                    const bounds = media.getBoundingClientRect?.() || { width: 0, height: 0 };

                    return Math.min(bounds.width, bounds.height) >= 180;
                });

                if (!pinned || !(coversCentre || coversMuch) || holdsPhotograph) {
                    continue;
                }

                const controls = [...element.querySelectorAll('button,a[role=button],a,input[type=button],input[type=submit],[role=button]')];
                const control = controls.find((candidate) => {
                    const text = `${candidate.innerText || ''} ${candidate.value || ''} ${candidate.getAttribute('aria-label') || ''}`
                        .trim().toLowerCase();

                    // Substring matching turned "Cookie settings" into a match
                    // for "ok" and clicked the wrong button - the one that opens
                    // preferences instead of closing the wall. Labels have to
                    // match whole words.
                    return text !== '' && text.length <= 60 && labels.some((label) => new RegExp(
                        `(?:^|[^\\p{L}])${label.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}(?:[^\\p{L}]|$)`,
                        'u',
                    ).test(text));
                }) || controls.find((candidate) => /close|dismiss|✕|×/i.test(
                    `${candidate.className || ''} ${candidate.getAttribute('aria-label') || ''}`,
                ));

                if (!control) {
                    // Still report it: an overlay nobody can close is exactly
                    // what the agent needs told, not hidden from.
                    element.dataset.ningredyOverlay = 'stuck';

                    continue;
                }

                control.dataset.ningredyOverlayControl = '1';
                element.dataset.ningredyOverlay = 'clearing';

                return {
                    label: (control.innerText || control.getAttribute('aria-label') || '').trim().slice(0, 60),
                    overlay: (element.id ? `#${element.id}` : element.className.toString().split(/\s+/)[0] || 'overlay').slice(0, 60),
                };
            }

            return null;
        }, OVERLAY_ACCEPT_LABELS).catch(() => null);

        if (!found) {
            break;
        }

        const control = target.locator('[data-ningredy-overlay-control="1"]').first();
        // A real click, not a synthetic one: consent frameworks routinely
        // listen for trusted events and ignore anything else.
        await control.click({ timeout: 2_000 }).catch(() => {});
        await target.evaluate(() => {
            document.querySelectorAll('[data-ningredy-overlay-control]')
                .forEach((element) => element.removeAttribute('data-ningredy-overlay-control'));
        }).catch(() => {});
        await target.waitForLoadState('domcontentloaded', { timeout: 8_000 }).catch(() => {});
        dismissed.push(found);
    }

    const stuck = await target.evaluate(() => [...document.querySelectorAll('[data-ningredy-overlay="stuck"]')]
        .map((element) => (element.id ? `#${element.id}` : element.className.toString().split(/\s+/)[0] || 'overlay').slice(0, 60))
        .slice(0, 3)).catch(() => []);

    return { dismissed, still_blocking: stuck };
};
