import dns from 'node:dns/promises';
import net from 'node:net';
import { chromium } from 'playwright-core';
import {
    galleryProbeMinimumSide,
    imageAssetKey,
    isAllowedProductNavigation,
    normalizeImageCandidate,
    urlQualityScore,
    withUpscaledSizeParam,
} from './product-gallery-utils.mjs';

const sourceUrl = process.argv[2];
const limit = Math.max(1, Math.min(60, Number.parseInt(process.argv[3] || '20', 10)));
const scoutOnly = process.env.PRODUCT_GALLERY_SCOUT_ONLY === '1';
const domWaitMs = Math.max(1000, Math.min(30000, Number.parseInt(
    process.env.PRODUCT_GALLERY_DOM_WAIT_MS || '12000',
    10,
)));
const probeTimeoutMs = Math.max(1000, Math.min(10000, Number.parseInt(
    process.env.PRODUCT_GALLERY_PROBE_TIMEOUT_MS || '5000',
    10,
)));
const minimumSide = Math.max(100, Math.min(2000, Number.parseInt(
    process.env.PRODUCT_GALLERY_MINIMUM_SIDE || '600',
    10,
)));
const confirmedGalleryMinimumSide = Math.max(100, Math.min(2000, Number.parseInt(
    process.env.PRODUCT_GALLERY_CONFIRMED_MINIMUM_SIDE || '400',
    10,
)));
// PHP kills this process at its own timeout, and on Windows that kill is a
// hard TerminateProcess with no chance to run a signal handler - so the only
// reliable way to keep already-gathered photos is to stop interacting and
// probing early enough to serialize the partial result before the kill lands.
const softDeadline = Date.now() + Math.max(10000, Number.parseInt(
    process.env.PRODUCT_GALLERY_DEADLINE_MS || '90000',
    10,
));
const outOfTime = () => Date.now() >= softDeadline;
let recipe = {};

try {
    recipe = JSON.parse(process.env.PRODUCT_GALLERY_RECIPE || '{}');
} catch {
    recipe = {};
}

const recipeSelectors = (key) => Array.isArray(recipe[key])
    ? recipe[key].filter((selector) => typeof selector === 'string'
        && selector.length <= 300
        && !/(?:javascript:|https?:|file:|xpath|script\b|iframe\b)/i.test(selector))
    : [];
const recipeNumber = (key, fallback, max) => Number.isInteger(recipe[key])
    ? Math.max(0, Math.min(max, recipe[key]))
    : fallback;
const recipeAttributes = Array.isArray(recipe.attributes)
    ? recipe.attributes
        .filter((attribute) => typeof attribute === 'string'
            && /^(?:src|href|srcset|data-[a-z0-9_-]+)$/i.test(attribute))
        .slice(0, 12)
    : [];

if (!sourceUrl) {
    process.stderr.write('A product URL is required.\n');
    process.exit(2);
}

const blockedIpv4 = (address) => {
    const parts = address.split('.').map(Number);

    if (parts.length !== 4 || parts.some((part) => !Number.isInteger(part))) {
        return true;
    }

    const [a, b] = parts;

    return a === 0
        || a === 10
        || a === 127
        || (a === 100 && b >= 64 && b <= 127)
        || (a === 169 && b === 254)
        || (a === 172 && b >= 16 && b <= 31)
        || (a === 192 && b === 0)
        || (a === 192 && b === 168)
        || (a === 198 && (b === 18 || b === 19))
        || a >= 224;
};

const blockedIp = (address) => {
    const version = net.isIP(address);

    if (version === 4) {
        return blockedIpv4(address);
    }

    if (version !== 6) {
        return true;
    }

    const normalized = address.toLowerCase();

    if (normalized.startsWith('::ffff:')) {
        return blockedIpv4(normalized.slice(7));
    }

    return normalized === '::'
        || normalized === '::1'
        || normalized.startsWith('fc')
        || normalized.startsWith('fd')
        || normalized.startsWith('fe8')
        || normalized.startsWith('fe9')
        || normalized.startsWith('fea')
        || normalized.startsWith('feb');
};

const hostChecks = new Map();
const publicHttpUrl = async (rawUrl) => {
    let url;

    try {
        url = new URL(rawUrl);
    } catch {
        return false;
    }

    if (!['http:', 'https:'].includes(url.protocol) || url.username || url.password) {
        return false;
    }

    const host = url.hostname.toLowerCase().replace(/^\[|\]$/g, '');

    if (host === 'localhost' || host.endsWith('.local') || host.endsWith('.internal')) {
        return false;
    }

    if (!hostChecks.has(host)) {
        hostChecks.set(host, (async () => {
            if (net.isIP(host)) {
                return !blockedIp(host);
            }

            try {
                const addresses = await dns.lookup(host, { all: true });

                return addresses.length > 0 && addresses.every(({ address }) => !blockedIp(address));
            } catch {
                return false;
            }
        })());
    }

    return hostChecks.get(host);
};

if (!await publicHttpUrl(sourceUrl)) {
    process.stderr.write('The product URL is not public.\n');
    process.exit(2);
}

let browser;

for (const channel of [process.env.PRODUCT_IMAGE_BROWSER_CHANNEL || 'msedge', 'chrome', null]) {
    try {
        browser = await chromium.launch({
            headless: true,
            args: ['--disable-blink-features=AutomationControlled'],
            ...(channel ? { channel } : {}),
        });
        break;
    } catch {
        // Try the next locally available Chromium channel.
    }
}

if (!browser) {
    process.stderr.write('No compatible Chromium browser is installed.\n');
    process.exit(3);
}

const context = await browser.newContext({
    viewport: { width: 1440, height: 1100 },
    locale: 'en-US',
    userAgent: process.env.PRODUCT_IMAGE_BROWSER_USER_AGENT
        || 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36 Edg/150.0.0.0',
});
await context.addInitScript(() => {
    Object.defineProperty(navigator, 'webdriver', { get: () => undefined });
});
const page = await context.newPage();
const networkImages = [];
const payloadImages = [];
const pendingPayloads = new Set();
const gathered = [];
const priorityImages = [];
let learnedRecipe = {};
let scout = {};
let postInteractionScout = {};
const actionTrace = [];
let navigationStatus = null;
let galleryReadiness = {};

// A gallery click can trigger a real page navigation instead of an in-page
// DOM update (a different product's page, a category listing, ...). Nothing
// downstream re-checks the URL, so without this guard the script would
// silently keep collecting - and shipping - photos of a different product.
let productPageUrl = sourceUrl;
let leftProductPage = false;
const onProductPage = () => {
    try {
        return isAllowedProductNavigation(productPageUrl, page.url());
    } catch {
        return false;
    }
};

// Any unhandled error anywhere below (a click triggering a navigation that
// tears down the execution context mid-evaluate, a selector timing out in an
// unexpected way, ...) used to crash the whole process, discarding every
// photo already gathered. The PHP side only needs valid JSON on stdout plus
// exit code 0 to accept a result - even a partial one - so emit whatever was
// collected so far instead of losing it to a raw stack trace.
let crashResultEmitted = false;
const emitCrashResult = (error) => {
    if (crashResultEmitted) {
        return;
    }

    crashResultEmitted = true;

    const partialImages = [...new Set(
        [...gathered, ...priorityImages, ...networkImages, ...payloadImages]
            .map((url) => normalizeImageCandidate(url, sourceUrl))
            .filter(Boolean),
    )].slice(0, limit);

    process.stdout.write(JSON.stringify({
        images: partialImages,
        scout,
        post_interaction_scout: postInteractionScout,
        action_trace: actionTrace,
        learned_recipe: learnedRecipe,
        error: String(error?.stack || error).slice(0, 1000),
        failure_kind: 'browser_crash',
        diagnostics: {
            partial: true,
            dom_candidates: gathered.length,
            network_candidates: networkImages.length,
        },
    }));

    Promise.resolve(browser?.close?.()).catch(() => {}).finally(() => process.exit(0));
};

process.on('uncaughtException', emitCrashResult);
process.on('unhandledRejection', emitCrashResult);
const imageUrlsFromText = (text) => {
    const decoded = text
        .replaceAll('\\u002F', '/')
        .replaceAll('\\/', '/')
        .replaceAll('&amp;', '&');

    return decoded.match(/https?:\/\/[^\s"'<>]+?\.(?:jpe?g|png|webp)(?:\?[^\s"'<>]*)?/gi) || [];
};

await page.route('**/*', async (route) => {
    if (await publicHttpUrl(route.request().url())) {
        await route.continue();
    } else {
        await route.abort('blockedbyclient');
    }
});

page.on('response', (response) => {
    const pending = (async () => {
        const contentType = (await response.headerValue('content-type') || '').toLowerCase();

        if (contentType.startsWith('image/')) {
            networkImages.push(response.url());
            return;
        }

        if (contentType.includes('application/json')) {
            const body = await response.text().catch(() => '');

            if (body.length <= 2_000_000) {
                payloadImages.push(...imageUrlsFromText(body));
            }
        }
    })().finally(() => pendingPayloads.delete(pending));

    pendingPayloads.add(pending);
});

const genericGallerySelectors = [
    '[data-old-hires]',
    '[data-selenium*="thumbnail" i] img',
    '[data-selenium*="mainimage" i]',
    '[data-selenium*="main-image" i]',
    '[class*="thumbnail" i] img',
    '[class*="thumbnail" i] button',
    '[class*="gallery" i] img',
    '[class*="gallery" i] button',
    '[data-selenium*="media" i]',
    '[class*="slider" i] img',
    '[class*="carousel" i] img',
    '[class*="swiper" i] img',
    '[class*="product-image" i] img',
    '[class*="product-media" i] img',
    'img[itemprop="image"]',
];
const strictRecipe = Object.keys(recipe).length > 0 && !scoutOnly;
const preClickSelectors = [...new Set(recipeSelectors('pre_click_selectors'))];
const gallerySelectors = [...new Set([
    ...recipeSelectors('collect_selectors'),
    ...(strictRecipe ? [] : genericGallerySelectors),
])];
const thumbnailSelectors = [...new Set([
    ...recipeSelectors('thumbnail_selectors'),
    ...(strictRecipe ? [] : genericGallerySelectors.slice(0, 10)),
])];
const openSelectors = [...new Set([
    ...recipeSelectors('open_selectors'),
    ...(strictRecipe ? [] : [
    'button[data-selenium*="media" i]',
    'button[class*="openmedia" i]',
    'button[class*="gallery" i]',
    'button[class*="thumbnail" i]',
        '[role="button"][class*="media" i]',
    ]),
])];
const nextSelectors = [...new Set([
    ...recipeSelectors('next_selectors'),
    ...(strictRecipe ? [] : [
        'button[aria-label*="next" i]',
        'button[data-selenium*="next" i]',
        'button[class*="next" i]',
    ]),
])];

const galleryStateSelectors = [...new Set([
    ...genericGallerySelectors,
    ...gallerySelectors,
    ...thumbnailSelectors,
    '[data-type=image][data-image-id]',
    'button[data-type=image]',
    '[class*=thumbnail i] li',
    '[class*=thumbs i] > li',
])];
// A gallery click on some sites triggers a real page navigation instead of an
// in-page DOM update. If that navigation lands while this evaluate is polling,
// Playwright throws "Execution context was destroyed" - an unhandled rejection
// from that would crash the whole Node process mid-run, discarding everything
// already gathered. Recoverable: wait for the new document to settle and
// re-read the (now different) gallery state instead of dying.
const isTornDownContextError = (error) => /execution context was destroyed|context or browser has been closed/i
    .test(String(error?.message ?? error ?? ''));
const readGalleryStateOnce = () => page.evaluate(({ selectors, expectedFromRecipe }) => {
    const safeCount = (selector) => {
        try {
            return document.querySelectorAll(selector).length;
        } catch {
            return 0;
        }
    };
    const selectorCounts = Object.fromEntries(selectors.map((selector) => [selector, safeCount(selector)]));
    const thumbnailStrategies = [
        '[data-type=image][data-image-id]',
        'button[data-type=image]',
        '[class*=thumbnail i] button',
        '[class*=thumbnail i] li',
        '[class*=thumbs i] > li',
        '[data-selenium*=thumbnail i]:not(img)',
    ];
    const imageThumbnailCount = (selector) => {
        try {
            return [...document.querySelectorAll(selector)].filter((node) => {
                const marker = [
                    node.getAttribute('class'),
                    node.getAttribute('data-type'),
                    node.getAttribute('data-selenium'),
                    node.getAttribute('aria-label'),
                    node.getAttribute('title'),
                    node.textContent,
                ].filter(Boolean).join(' ');
                const video = node.matches?.('video,[data-type="video"],[class*="video" i],[class*="360" i]')
                    || node.querySelector?.('video,[data-type="video"],[class*="video" i],[class*="360" i]')
                    || /(?:^|\W)(?:video|360)(?:\W|$)/i.test(marker);

                return !video && (
                    node.matches?.('img,picture,[data-type=image]')
                    || node.querySelector?.('img,picture,[data-type=image]')
                );
            }).length;
        } catch {
            return 0;
        }
    };
    const thumbnailCount = Math.max(0, ...thumbnailStrategies.map(imageThumbnailCount));
    let explicitCount = 0;
    const imageOrdinals = new Set();
    const nonImageOrdinals = new Set();
    const evidence = [];
    const evidenceNodes = new Set();

    for (const selector of selectors) {
        try {
            for (const element of document.querySelectorAll(selector)) {
                evidenceNodes.add(element);

                for (const child of element.querySelectorAll('[alt],[aria-label],[title]')) {
                    evidenceNodes.add(child);
                }
            }
        } catch {
            // Invalid selectors are already excluded from the learned recipe.
        }
    }

    const nodes = [...evidenceNodes].slice(0, 1000);

    for (const node of nodes) {
        const text = [
            node.getAttribute('alt'),
            node.getAttribute('aria-label'),
            node.getAttribute('title'),
            node.textContent,
        ].filter(Boolean).join(' ').slice(0, 500);

        for (const match of text.matchAll(/(\d+)\s*(?:of|\/)\s*(\d+)/gi)) {
            const ordinal = Number.parseInt(match[1], 10);
            const count = Number.parseInt(match[2], 10);
            const mediaMarker = [
                node.getAttribute('class'),
                node.getAttribute('data-type'),
                node.getAttribute('data-selenium'),
                text,
            ].filter(Boolean).join(' ');
            const containsImage = node.matches?.('img,picture')
                || node.querySelector?.('img,picture');
            const containsNonImageMedia = node.matches?.('video,[data-type="video"],[class*="video" i],[class*="360" i]')
                || node.querySelector?.('video,[data-type="video"],[class*="video" i],[class*="360" i]')
                || /(?:^|\W)(?:video|360(?:°|\s*degree)?)(?:\W|$)/i.test(mediaMarker);

            if (ordinal > 0 && ordinal <= count) {
                if (containsNonImageMedia && !containsImage) {
                    nonImageOrdinals.add(ordinal);
                } else if (containsImage) {
                    imageOrdinals.add(ordinal);
                }
            }

            if (count > explicitCount && count <= 100) {
                explicitCount = count;
                evidence.splice(0, evidence.length, match[0]);
            }
        }
    }

    for (const ordinal of nonImageOrdinals) {
        imageOrdinals.delete(ordinal);
    }

    const selectorMax = Math.max(0, ...Object.values(selectorCounts));
    const explicitImageCount = imageOrdinals.size > 0
        ? imageOrdinals.size
        : Math.max(0, explicitCount - nonImageOrdinals.size);
    const observedCount = Math.min(20, Math.max(explicitImageCount, thumbnailCount));
    const targetCount = Math.min(20, Math.max(observedCount, expectedFromRecipe || 0));
    const signature = JSON.stringify({
        selectorCounts,
        thumbnailCount,
        explicitCount,
        explicitImageCount,
    });

    return {
        selector_counts: selectorCounts,
        selector_max_count: selectorMax,
        thumbnail_count: thumbnailCount,
        explicit_image_count: explicitImageCount,
        observed_count: observedCount,
        target_count: targetCount,
        evidence,
        signature,
    };
}, {
    selectors: galleryStateSelectors,
    expectedFromRecipe: recipeNumber('expected_image_count', 0, 20),
});
const readGalleryState = async () => {
    for (let attempt = 0; ; attempt++) {
        try {
            return await readGalleryStateOnce();
        } catch (error) {
            if (attempt >= 2 || !isTornDownContextError(error)) {
                throw error;
            }

            await page.waitForLoadState('domcontentloaded', { timeout: 5000 }).catch(() => {});
            await page.waitForTimeout(200);
        }
    }
};
const waitForStableGallery = async () => {
    const startedAt = Date.now();
    const deadline = startedAt + domWaitMs;
    let previousSignature = null;
    let stableSamples = 0;
    let state = await readGalleryState();
    let lazyScrollTriggered = false;

    while (Date.now() < deadline) {
        if (state.signature === previousSignature) {
            stableSamples++;
        } else {
            previousSignature = state.signature;
            stableSamples = 0;
        }

        const enoughItems = state.observed_count >= Math.max(2, state.target_count);

        if (enoughItems && stableSamples >= 3) {
            break;
        }

        if (!lazyScrollTriggered && Date.now() - startedAt >= 1500 && state.observed_count < 2) {
            lazyScrollTriggered = true;
            await page.evaluate(() => window.scrollTo(0, Math.min(document.body.scrollHeight / 3, 900))).catch(() => {});
        }

        await page.waitForTimeout(250);
        state = await readGalleryState();
    }

    return {
        ...state,
        waited_ms: Date.now() - startedAt,
        stable_samples: stableSamples,
        timed_out: Date.now() >= deadline,
    };
};

const collectDomImages = async () => page.evaluate(({ selectors, extraAttributes }) => {
    const urls = [];
    const add = (value) => {
        if (typeof value === 'string' && value.trim() !== '') {
            urls.push(value.trim());
        }
    };
    const addSrcset = (value) => {
        if (typeof value !== 'string') {
            return;
        }

        for (const item of value.split(',')) {
            add(item.trim().split(/\s+/)[0]);
        }
    };
    const isNonPhotoMedia = (element) => {
        if (!element) {
            return false;
        }

        const marker = [
            element.getAttribute?.('class'),
            element.getAttribute?.('data-type'),
            element.getAttribute?.('data-media-type'),
            element.getAttribute?.('aria-label'),
            element.getAttribute?.('title'),
        ].filter(Boolean).join(' ');

        return element.matches?.('video,[data-type="video"],[data-media-type="video"]')
            || element.closest?.('video,[data-type="video"],[data-media-type="video"],[class*="video" i],[class*="360" i]')
            || /(?:^|\W)(?:video|360(?:\W|$))/i.test(marker);
    };
    const addNode = (element) => {
        if (!element) {
            return;
        }

        for (const attribute of [...new Set([
            ...extraAttributes,
            'data-old-hires',
            'data-zoom-image',
            'data-large_image',
            'data-full',
            'data-full-src',
            'data-hires',
            'data-original',
            'data-lazy-src',
            'data-src',
            'src',
            'href',
        ])]) {
            add(element.getAttribute?.(attribute));
        }

        add(element.currentSrc);
        addSrcset(element.getAttribute?.('srcset'));
        addSrcset(element.getAttribute?.('data-srcset'));

        const background = getComputedStyle(element).backgroundImage;

        for (const match of background.matchAll(/url\((['"]?)(.*?)\1\)/g)) {
            add(match[2]);
        }

        add(element.closest?.('a[href]')?.href);
    };
    const addElement = (element) => {
        if (isNonPhotoMedia(element)) {
            return;
        }

        addNode(element);
        addNode(element?.parentElement);

        for (const child of element?.querySelectorAll?.([
            'img',
            'source',
            '[data-old-hires]',
            '[data-zoom-image]',
            '[data-large_image]',
            '[data-full]',
            '[data-full-src]',
            '[data-hires]',
        ].join(',')) || []) {
            if (isNonPhotoMedia(child)) {
                continue;
            }

            addNode(child);
            addNode(child.parentElement);
        }
    };

    for (const selector of selectors) {
        try {
            for (const element of document.querySelectorAll(selector)) {
                addElement(element);
            }
        } catch {
            // Ignore an invalid selector instead of aborting the entire recipe.
        }
    }

    for (const element of document.querySelectorAll(
        'meta[property="og:image"], meta[property="og:image:url"], meta[property="og:image:secure_url"], '
        + 'meta[name="twitter:image"], meta[itemprop="image"], link[rel="image_src"]',
    )) {
        add(element.getAttribute('content') || element.getAttribute('href'));
    }

    for (const script of document.querySelectorAll('script[type="application/json"], script[type="application/ld+json"]')) {
        const text = (script.textContent || '')
            .replaceAll('\\u002F', '/')
            .replaceAll('\\/', '/')
            .replaceAll('&amp;', '&');

        if (text.length > 2_000_000) {
            continue;
        }

        for (const match of text.matchAll(/https?:\/\/[^\s"'<>]+?\.(?:jpe?g|png|webp)(?:\?[^\s"'<>]*)?/gi)) {
            add(match[0]);
        }
    }

    return urls;
}, {
    selectors: [...new Set([...gallerySelectors, ...thumbnailSelectors])],
    extraAttributes: recipeAttributes,
});

const enoughCollected = () => new Set(
    gathered.map((url) => normalizeImageCandidate(url, sourceUrl)).filter(Boolean),
).size >= Math.max(limit, recipeNumber('expected_image_count', 0, 20));
const collect = async () => {
    gathered.push(...await collectDomImages().catch(() => []));
    priorityImages.push(...await page.locator('[data-old-hires]').evaluateAll(
        (elements) => elements
            .filter((element) => !element.closest('video,[data-type="video"],[data-media-type="video"],[class*="video" i],[class*="360" i]'))
            .map((element) => element.getAttribute('data-old-hires'))
            .filter(Boolean),
    ).catch(() => []));
};
const collectionSignature = async () => page.evaluate(({ selectors, attributes }) => {
    const values = [];

    for (const selector of selectors) {
        try {
            for (const element of document.querySelectorAll(selector)) {
                values.push([
                    selector,
                    element.getAttribute('class') || '',
                    ...attributes.map((attribute) => element.getAttribute(attribute) || ''),
                    element.currentSrc || '',
                ].join('|'));
            }
        } catch {
            // Invalid selectors are ignored by the safe runner.
        }
    }

    return values.join('\n');
}, {
    selectors: gallerySelectors,
    attributes: [...new Set([...recipeAttributes, 'src', 'href', 'srcset'])],
}).catch(() => '');
const clickAndWaitForGalleryChange = async (locator, meta = {}) => {
    const startedAt = Date.now();
    const beforeState = await readGalleryState();
    const beforeSignature = await collectionSignature();
    const beforeNetworkCount = networkImages.length;
    const href = await locator.getAttribute('href').catch(() => null);
    let navigatedByHref = false;

    if (href && !isAllowedProductNavigation(productPageUrl, href)) {
        actionTrace.push({
            ...meta,
            clicked: false,
            changed: false,
            navigation_blocked: true,
            navigation_target: href,
            before_images: beforeState.observed_count || 0,
            after_images: beforeState.observed_count || 0,
            network_delta: 0,
            duration_ms: Date.now() - startedAt,
        });

        return false;
    }
    const navigateAllowedHref = async () => {
        if (!href) {
            return false;
        }

        let targetUrl;

        try {
            targetUrl = new URL(href, page.url()).href;
        } catch {
            return false;
        }

        if (!isAllowedProductNavigation(productPageUrl, targetUrl) || targetUrl === page.url()) {
            return false;
        }

        const previousUrl = page.url();
        const navigation = await page.goto(targetUrl, {
            waitUntil: 'domcontentloaded',
            timeout: 10_000,
        }).catch(() => null);

        await page.waitForLoadState('load', { timeout: 5_000 }).catch(() => {});
        navigatedByHref = Boolean(navigation)
            && page.url() !== previousUrl
            && isAllowedProductNavigation(productPageUrl, page.url());

        return navigatedByHref;
    };
    const clicked = await locator.click({ timeout: 2000 })
        .then(() => true)
        .catch(() => locator.click({ force: true, timeout: 700 }).then(() => true).catch(() => false));

    if (!clicked && !await navigateAllowedHref()) {
        actionTrace.push({
            ...meta,
            clicked: false,
            changed: false,
            before_images: beforeState.observed_count || 0,
            after_images: beforeState.observed_count || 0,
            network_delta: 0,
            duration_ms: Date.now() - startedAt,
        });
        return false;
    }

    // Navigation can also land a beat after the click resolves (still inside
    // the poll below) rather than immediately - readGalleryState/
    // collectionSignature would then read the new page's DOM without
    // throwing, misreported as "the gallery changed". Checked both here and
    // on every poll iteration below for that reason.
    const bailIfNavigatedAway = async () => {
        if (onProductPage()) {
            return false;
        }

        leftProductPage = true;
        await page.goBack({ waitUntil: 'domcontentloaded', timeout: 5000 }).catch(() => {});
        actionTrace.push({
            ...meta,
            clicked: true,
            changed: false,
            navigated_away: true,
            before_images: beforeState.observed_count || 0,
            after_images: beforeState.observed_count || 0,
            network_delta: 0,
            duration_ms: Date.now() - startedAt,
        });

        return true;
    };

    if (await bailIfNavigatedAway()) {
        return false;
    }

    const deadline = Date.now() + Math.max(
        500,
        Math.min(3000, recipeNumber('wait_after_click_ms', 250, 3000) * 4),
    );

    while (Date.now() < deadline) {
        if (await bailIfNavigatedAway()) {
            return false;
        }

        if (networkImages.length > beforeNetworkCount || await collectionSignature() !== beforeSignature) {
            const afterState = await readGalleryState();
            actionTrace.push({
                ...meta,
                clicked: true,
                changed: true,
                navigated_by_href: navigatedByHref,
                before_images: beforeState.observed_count || 0,
                after_images: afterState.observed_count || 0,
                network_delta: Math.max(0, networkImages.length - beforeNetworkCount),
                duration_ms: Date.now() - startedAt,
            });
            return true;
        }

        await page.waitForTimeout(100);
    }

    if (await navigateAllowedHref()) {
        const afterState = await readGalleryState();
        actionTrace.push({
            ...meta,
            clicked,
            changed: true,
            navigated_by_href: true,
            navigation_target: page.url(),
            before_images: beforeState.observed_count || 0,
            after_images: afterState.observed_count || 0,
            network_delta: Math.max(0, networkImages.length - beforeNetworkCount),
            duration_ms: Date.now() - startedAt,
        });

        return true;
    }

    const afterState = await readGalleryState();
    actionTrace.push({
        ...meta,
        clicked: true,
        changed: false,
        before_images: beforeState.observed_count || 0,
        after_images: afterState.observed_count || 0,
        network_delta: Math.max(0, networkImages.length - beforeNetworkCount),
        duration_ms: Date.now() - startedAt,
    });

    return true;
};

const captureInteractionScout = async () => page.evaluate(() => {
    const sanitize = (element) => {
        const clone = element.cloneNode(true);

        for (const node of [clone, ...clone.querySelectorAll('*')]) {
            for (const attribute of [...node.attributes]) {
                if (/^on/i.test(attribute.name) || ['style', 'nonce', 'integrity', 'srcset', 'data-srcset', 'data-bgset'].includes(attribute.name)) {
                    node.removeAttribute(attribute.name);
                }
            }
        }

        for (const node of clone.querySelectorAll('script,style,noscript,iframe,object,embed,form,input,textarea')) {
            node.remove();
        }

        return clone.outerHTML.replace(/\s+/g, ' ').slice(0, 1600);
    };
    const candidates = [...document.querySelectorAll([
        '[data-old-hires]', '[data-zoom-image]', '[data-large_image]', '[data-full]', '[itemprop=image]',
        '[class*=gallery i]', '[class*=thumbnail i]', '[class*=slider i]',
        '[class*=carousel i]', '[class*=swiper i]', '[class*=zoom i]',
        '[class*=product-image i]', '[class*=product-media i]', '[data-selenium*=media i]',
        'button[aria-label*=next i]', 'button[aria-label*=image i]',
    ].join(','))].slice(0, 70);
    const interactiveControls = [...document.querySelectorAll('button,a,[role=button]')]
        .filter((element) => /(gallery|media|image|photo|thumbnail|carousel|slider|zoom|next|more)/i.test([
            element.getAttribute('aria-label'),
             element.getAttribute('title'),
             element.getAttribute('class'),
             element.getAttribute('data-selenium'),
             element.getAttribute('href'),
             element.textContent,
        ].filter(Boolean).join(' ')))
        .slice(0, 50)
        .map(sanitize)
        .filter(Boolean);

    return {
        final_url: location.href,
        title: document.title.slice(0, 500),
        fragments: candidates.map(sanitize).filter(Boolean).slice(0, 32),
        interactive_controls: interactiveControls,
    };
});

try {
    const navigation = await page.goto(sourceUrl, { waitUntil: 'domcontentloaded', timeout: 20_000 });
    navigationStatus = navigation?.status() ?? null;
    await page.waitForLoadState('load', { timeout: 5_000 }).catch(() => {});

    for (let attempt = 0; attempt < 2; attempt++) {
        const continueShopping = page.locator([
            'button:has-text("Continue shopping")',
            'a:has-text("Continue shopping")',
            'input[value*="Continue shopping" i]',
        ].join(',')).first();

        if (!await continueShopping.count()) {
            break;
        }

        await continueShopping.click({ force: true, timeout: 2_000 }).catch(() => {});
        await page.waitForLoadState('domcontentloaded', { timeout: 10_000 }).catch(() => {});
        await page.waitForLoadState('load', { timeout: 5_000 }).catch(() => {});
    }

    // Use the browser's final product URL after redirects/interstitials as the
    // logical root for later same-product Gallery/Media navigation checks.
    productPageUrl = page.url();

    await page.locator('#sp-cc-accept').click({ timeout: 500 }).catch(() => {});

    for (const selector of preClickSelectors) {
        if (leftProductPage) {
            break;
        }

        const control = page.locator(selector).first();

        if (await control.count().catch(() => 0)) {
            await clickAndWaitForGalleryChange(control, { phase: 'pre_click', selector });
        }
    }

    galleryReadiness = await waitForStableGallery();

    scout = await page.evaluate((httpStatus) => {
        const candidates = [...document.querySelectorAll([
            '[data-old-hires]', '[data-zoom-image]', '[data-large_image]', '[itemprop="image"]',
            '[class*="gallery" i]', '[class*="thumbnail" i]', '[class*="slider" i]',
            '[class*="carousel" i]', '[class*="swiper" i]', '[class*="product-image" i]',
            '[class*="product-media" i]', '[data-selenium*="media" i]',
            'button[aria-label*="next" i]', 'button[aria-label*="image" i]',
        ].join(','))].slice(0, 40);
        const sanitize = (element) => {
            const clone = element.cloneNode(true);

            for (const node of [clone, ...clone.querySelectorAll('*')]) {
                for (const attribute of [...node.attributes]) {
                    if (/^on/i.test(attribute.name) || ['style', 'nonce', 'integrity', 'srcset', 'data-srcset', 'data-bgset'].includes(attribute.name)) {
                        node.removeAttribute(attribute.name);
                    }
                }
            }

            for (const node of clone.querySelectorAll('script,style,noscript,iframe,object,embed,form,input,textarea')) {
                node.remove();
            }

            return clone.outerHTML.replace(/\s+/g, ' ').slice(0, 1000);
        };
        const pageText = `${document.title}\n${document.body?.innerText || ''}`.slice(0, 20_000);
        const accessSignals = [
            ['captcha', /captcha|verify you are human|are you a robot|robot or human/i],
            ['waf', /access denied|request blocked|security check|press\s*(?:and|&)\s*hold|cf-chl-/i],
            ['traffic', /unusual traffic|automated requests|temporarily blocked/i],
        ];
        const matchedSignal = accessSignals.find(([, pattern]) => pattern.test(pageText));
        const accessGateReason = httpStatus === 403
            ? 'http_403'
            : (matchedSignal?.[0] || null);
        const interactiveControls = [...document.querySelectorAll('button,a,[role="button"]')]
            .filter((element) => /(gallery|media|image|photo|thumbnail|carousel|slider|zoom|next|more)/i.test([
                element.getAttribute('aria-label'),
                 element.getAttribute('title'),
                 element.getAttribute('class'),
                 element.getAttribute('data-selenium'),
                 element.getAttribute('href'),
                 element.textContent,
            ].filter(Boolean).join(' ')))
            .slice(0, 24)
            .map(sanitize)
            .filter(Boolean);

        return {
            final_url: location.href,
            title: document.title.slice(0, 500),
            http_status: httpStatus,
            access_gate: accessGateReason !== null,
            access_gate_reason: accessGateReason,
            rate_limited: httpStatus === 429,
            fragments: candidates.map(sanitize).filter(Boolean).slice(0, 18),
            interactive_controls: interactiveControls,
        };
    }, navigationStatus);
    scout.gallery_readiness = galleryReadiness;
    scout.observed_gallery_count = galleryReadiness.observed_count || 0;
    scout.expected_count_evidence = galleryReadiness.evidence || [];

    await collect();

    const selectorCounts = {};
    for (const selector of [...new Set([
        ...gallerySelectors,
        ...thumbnailSelectors,
        ...openSelectors,
        ...nextSelectors,
    ])]) {
        selectorCounts[selector] = await page.locator(selector).count().catch(() => 0);
    }
    learnedRecipe = {
        collect_selectors: gallerySelectors.filter((selector) => selectorCounts[selector] > 0).slice(0, 12),
        thumbnail_selectors: thumbnailSelectors.filter((selector) => selectorCounts[selector] > 1).slice(0, 8),
        open_selectors: openSelectors.filter((selector) => selectorCounts[selector] > 0).slice(0, 5),
        next_selectors: nextSelectors.filter((selector) => selectorCounts[selector] > 0).slice(0, 5),
    };

    if (!scoutOnly) {
        const thumbnails = thumbnailSelectors.length ? page.locator(thumbnailSelectors.join(',')) : null;
    const thumbnailCount = thumbnails
        ? Math.min(await thumbnails.count(), recipeNumber('max_thumbnail_clicks', 20, 20))
        : 0;

    for (let index = 0; index < thumbnailCount && !leftProductPage && !outOfTime(); index++) {
        const thumbnail = thumbnails.nth(index);
        await thumbnail.scrollIntoViewIfNeeded({ timeout: 500 }).catch(() => {});
        await clickAndWaitForGalleryChange(thumbnail, {
            phase: 'thumbnail',
            selector: thumbnailSelectors.join(','),
            index,
        });
        await collect();

        if (enoughCollected()) {
            break;
        }
    }

    const openMediaButtons = openSelectors.length ? page.locator(openSelectors.join(',')) : null;
    const openMediaCount = openMediaButtons ? Math.min(await openMediaButtons.count(), 5) : 0;

    for (let index = 0; index < openMediaCount && !leftProductPage && !outOfTime(); index++) {
        const button = openMediaButtons.nth(index);
        const text = (await button.innerText({ timeout: 300 }).catch(() => '')).trim();
        const marker = `${await button.getAttribute('class') || ''} ${await button.getAttribute('data-selenium') || ''}`;

        if (!/^\+\s*\d+$/.test(text) && !/(open.?media|gallery|see.?more|view.?all)/i.test(`${text} ${marker}`)) {
            continue;
        }

        await clickAndWaitForGalleryChange(button, {
            phase: 'open',
            selector: openSelectors.join(','),
            index,
        });
        await collect();

        if (enoughCollected()) {
            break;
        }
    }

    const nextButtons = nextSelectors.length ? page.locator(nextSelectors.join(',')) : null;

    if (nextButtons && await nextButtons.count()) {
        for (let index = 0; index < Math.min(limit, recipeNumber('max_next_clicks', 15, 15)) && !leftProductPage && !outOfTime(); index++) {
            await clickAndWaitForGalleryChange(nextButtons.first(), {
                phase: 'next',
                selector: nextSelectors.join(','),
                index,
            });
            await collect();

            if (enoughCollected()) {
                break;
            }
        }
    }

    if (!outOfTime()) {
        galleryReadiness = await waitForStableGallery();
        postInteractionScout = await captureInteractionScout().catch(() => ({}));
        postInteractionScout.gallery_readiness = galleryReadiness;
        postInteractionScout.observed_gallery_count = galleryReadiness.observed_count || 0;
        postInteractionScout.expected_count_evidence = galleryReadiness.evidence || [];
    }
    }
} finally {
    await Promise.allSettled([...pendingPayloads]);
    if (scout && typeof scout === 'object') {
        scout.network_image_samples = [...new Set([
            ...networkImages,
            ...payloadImages,
        ])].slice(0, 30);
    }
    if (postInteractionScout && typeof postInteractionScout === 'object') {
        postInteractionScout.network_image_samples = [...new Set([
            ...networkImages,
            ...payloadImages,
        ])].slice(0, 50);
    }
}

const excludedNonPhotoUrls = await page.evaluate(() => {
    const urls = [];
    const add = (value) => typeof value === 'string' && value.trim() !== '' && urls.push(value.trim());
    const media = document.querySelectorAll([
        'video',
        '[data-type="video"]',
        '[data-media-type="video"]',
        '[class*="video" i]',
        '[class*="360" i]',
    ].join(','));

    for (const element of media) {
        add(element.getAttribute?.('poster'));

        for (const child of element.querySelectorAll?.('img,source,[src],[data-src],[data-old-hires]') || []) {
            add(child.currentSrc);
            add(child.getAttribute?.('src'));
            add(child.getAttribute?.('data-src'));
            add(child.getAttribute?.('data-old-hires'));
        }
    }

    return urls;
}).catch(() => []);
const normalize = (rawUrl) => normalizeImageCandidate(rawUrl, sourceUrl);
const excludedNonPhotoKeys = new Set(excludedNonPhotoUrls.map(normalize).filter(Boolean).map(imageAssetKey));
const photoOnly = (url) => !excludedNonPhotoKeys.has(imageAssetKey(url));
const domImages = gathered.map(normalize).filter(Boolean).filter(photoOnly);
const priorityDomImages = [...new Set(priorityImages.map(normalize).filter(Boolean).filter(photoOnly))];
const requestedImages = networkImages.map(normalize).filter(Boolean).filter(photoOnly);
const embeddedImages = payloadImages.map(normalize).filter(Boolean).filter(photoOnly);
const allCandidates = [...new Set([...domImages, ...embeddedImages, ...requestedImages])];
const effectiveMinimumSide = galleryProbeMinimumSide({
    minimumSide,
    confirmedMinimumSide: confirmedGalleryMinimumSide,
    galleryPresent: recipe.gallery_present === true,
    expectedCount: recipeNumber('expected_image_count', 0, 20),
    observedCount: galleryReadiness.observed_count || 0,
});
const galleryGoalReached = effectiveMinimumSide < minimumSide;
const hasDirectBhImages = allCandidates.some((url) => new URL(url).hostname === 'static.bhphoto.com');
const domKeys = new Set(domImages.map(imageAssetKey));
const candidates = priorityDomImages.length >= 2
    ? priorityDomImages
    : (domImages.length >= 2
        ? allCandidates.filter((candidate) => domKeys.has(imageAssetKey(candidate)))
        : allCandidates);
const probeImage = async (candidate) => {
    if (!await publicHttpUrl(candidate)) {
        return { ok: false, url: candidate, reason: 'non_public_url' };
    }

    return page.evaluate(({ url, timeout, minSide }) => new Promise((resolve) => {
        const image = new Image();
        const timer = setTimeout(
            () => resolve({ ok: false, url, reason: 'probe_timeout' }),
            timeout,
        );
        const finish = (result) => {
            clearTimeout(timer);
            resolve(result);
        };

        image.onload = () => {
            const width = image.naturalWidth || 0;
            const height = image.naturalHeight || 0;
            const ok = width >= minSide && height >= minSide;

            finish({
                ok,
                url: image.currentSrc || url,
                width,
                height,
                reason: ok ? null : 'dimensions_below_minimum',
            });
        };
        image.onerror = () => finish({ ok: false, url, reason: 'decode_failed' });
        image.src = url;
    }), {
        url: candidate,
        timeout: probeTimeoutMs,
        minSide: effectiveMinimumSide,
    }).catch(() => ({ ok: false, url: candidate, reason: 'probe_failed' }));
};
const candidatesToProbe = candidates.slice(0, Math.max(30, limit * 3));
const probes = [];

if (!scoutOnly) {
    for (let index = 0; index < candidatesToProbe.length && !outOfTime(); index += 4) {
        probes.push(...await Promise.all(candidatesToProbe.slice(index, index + 4).map(probeImage)));
    }

    // A candidate rejected only for being too small may still be the exact
    // same photo available at a larger size through its own width/height
    // query parameter (see withUpscaledSizeParam) - retry those before
    // accepting the loss of a real, distinct product angle.
    const upscaleAttempts = probes
        .filter((probe) => !probe.ok && probe.reason === 'dimensions_below_minimum')
        .map((probe) => withUpscaledSizeParam(probe.url))
        .filter((url) => url !== null)
        .slice(0, 20);

    for (let index = 0; index < upscaleAttempts.length && !outOfTime(); index += 4) {
        probes.push(...await Promise.all(upscaleAttempts.slice(index, index + 4).map(probeImage)));
    }
}

const bestImages = new Map();
const validationFailures = [];

for (const probe of probes) {
    if (!probe.ok) {
        validationFailures.push({ url: probe.url, reason: probe.reason });
        continue;
    }

    const candidate = normalize(probe.url);

    if (!candidate) {
        validationFailures.push({ url: probe.url, reason: 'normalization_failed' });
        continue;
    }

    if (hasDirectBhImages && candidate.includes('bhphotovideo.com/cdn-cgi/image/')) {
        continue;
    }

    const key = imageAssetKey(candidate);
    const existing = bestImages.get(key);
    const score = Math.max(
        urlQualityScore(candidate),
        (probe.width || 0) * (probe.height || 0),
    );

    if (!existing || score > existing.score) {
        bestImages.set(key, { url: candidate, score });
    }
}

const images = [...bestImages.values()].map((item) => item.url).slice(0, limit);
await browser.close();

process.stdout.write(JSON.stringify({
    images,
    scout,
    post_interaction_scout: postInteractionScout,
    action_trace: actionTrace,
    learned_recipe: learnedRecipe,
    diagnostics: {
        dom_candidates: domImages.length,
        payload_candidates: embeddedImages.length,
        network_candidates: requestedImages.length,
        unique_candidates: allCandidates.length,
        probed_candidates: probes.length,
        validated_candidates: images.length,
        rejected_candidates: validationFailures.slice(0, 20),
        observed_gallery_count: galleryReadiness.observed_count || 0,
        gallery_waited_ms: galleryReadiness.waited_ms || 0,
        gallery_stable_samples: galleryReadiness.stable_samples || 0,
        gallery_wait_timed_out: galleryReadiness.timed_out || false,
        gallery_goal_reached: galleryGoalReached,
        effective_minimum_side: effectiveMinimumSide,
        stopped_early: outOfTime(),
    },
}));
