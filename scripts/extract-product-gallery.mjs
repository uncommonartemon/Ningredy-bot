import dns from 'node:dns/promises';
import net from 'node:net';
import { writeFile } from 'node:fs/promises';
import { join } from 'node:path';
import { chromium } from 'playwright-core';
import {
    EXCLUDED_GALLERY_CONTEXT_PATTERN_SOURCE,
    galleryCollectionTarget,
    imageAssetKey,
    isAllowedProductNavigation,
    normalizeImageCandidate,
    normalizeRecipeActions,
    prioritizeCandidateRenditions,
    recipeActionOpensGallery,
    recipeActionPlanStatus,
    recipeActionShouldStop,
    recipeActionTraversesGallery,
    urlQualityScore,
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
const minimumWidth = Math.max(100, Math.min(4000, Number.parseInt(
    process.env.PRODUCT_GALLERY_MINIMUM_WIDTH || '700',
    10,
)));
const minimumHeight = Math.max(0, Math.min(4000, Number.parseInt(
    process.env.PRODUCT_GALLERY_MINIMUM_HEIGHT ?? '0',
    10,
)));
// PHP kills this process at its own timeout, and on Windows that kill is a
const transferDirectory = String(process.env.PRODUCT_GALLERY_TRANSFER_DIR || '').trim();
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
const recipeExcludeSelectors = recipeSelectors('exclude_selectors');
const recipeActions = normalizeRecipeActions(recipe.actions);

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

let launchedChannel = null;

for (const channel of [process.env.PRODUCT_IMAGE_BROWSER_CHANNEL || 'msedge', 'chrome', null]) {
    try {
        browser = await chromium.launch({
            // Headless is detectable at the browser level regardless of what the
            // headers claim, so a machine with a display can trade visibility
            // for reach on WAF-protected sites.
            headless: process.env.PRODUCT_IMAGE_BROWSER_HEADLESS !== 'false',
            args: ['--disable-blink-features=AutomationControlled'],
            ...(channel ? { channel } : {}),
        });
        launchedChannel = channel;
        break;
    } catch {
        // Try the next locally available Chromium channel.
    }
}

if (!browser) {
    process.stderr.write('No compatible Chromium browser is installed.\n');
    process.exit(3);
}

// A real Edge/Chrome install already sends a legitimate User-Agent, and its
// Client Hints (sec-ch-ua, navigator.userAgentData) report the true build. An
// override claiming a version that install does not have contradicts those
// hints, which is a stronger bot signal than the honest header would be - the
// previous default claimed Chrome/Edg 150 while no such build exists. Only the
// bundled Chromium needs a rewrite, because its own agent says HeadlessChrome.
const overrideUserAgent = process.env.PRODUCT_IMAGE_BROWSER_USER_AGENT
    || (launchedChannel === null
        ? 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36'
        : null);
const context = await browser.newContext({
    viewport: { width: 1440, height: 1100 },
    locale: 'en-US',
    // A locale without a matching timezone is its own small inconsistency.
    timezoneId: process.env.PRODUCT_IMAGE_BROWSER_TIMEZONE || 'America/New_York',
    ...(overrideUserAgent ? { userAgent: overrideUserAgent } : {}),
});
await context.addInitScript(() => {
    Object.defineProperty(navigator, 'webdriver', { get: () => undefined });
});
const page = await context.newPage();
const networkImages = [];
const payloadImages = [];
const pendingPayloads = new Set();
const gathered = [];
const excludedGalleryContexts = [];
const collectionErrors = [];
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
            action_plan: recipeActionPlanStatus({ actions: recipeActions, actionTrace }),
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
const expandedGalleryContainers = [
    `[role='dialog']`,
    `[aria-modal='true']`,
    'dialog[open]',
    `[class*='lightbox' i]`,
    `[class*='fullscreen' i]`,
    `[class*='media-viewer' i]`,
    `[class*='image-viewer' i]`,
    `[class*='zoom' i]`,
];
const expandedGallerySelectors = expandedGalleryContainers.flatMap((container) => [
    `${container} img`,
    `${container} picture`,
    `${container} source`,
    `${container} [data-old-hires]`,
    `${container} [data-zoom-image]`,
    `${container} [data-large_image]`,
    `${container} [data-full]`,
    `${container} [data-full-src]`,
]);
const mainGalleryImageSelectors = [
    `[data-selenium*='mainimage' i]`,
    `[data-selenium*='main-image' i]`,
    `img[itemprop='image']`,
    `[class*='product-image' i] img`,
    `[class*='product-media' i] img`,
    `[class*='gallery' i] img`,
    `[class*='slider' i] img`,
    `[class*='carousel' i] img`,
    `[class*='swiper' i] img`,
];
const strictRecipe = Object.keys(recipe).length > 0 && !scoutOnly;
const preClickSelectors = recipeActions.length > 0
    ? []
    : [...new Set(recipeSelectors('pre_click_selectors'))];
const gallerySelectors = [...new Set([
    ...recipeSelectors('collect_selectors'),
    ...(strictRecipe ? [] : [...expandedGallerySelectors, ...genericGallerySelectors]),
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
    ...(strictRecipe ? [] : genericGallerySelectors),
    ...gallerySelectors,
    ...thumbnailSelectors,
    ...recipeActions.flatMap((action) => [action.selector, action.after_each_selector].filter(Boolean)),
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
const readGalleryStateOnce = () => page.evaluate(({
    selectors,
    expectedFromRecipe,
    excludedContextPatternSource,
}) => {
    const excludedContextPattern = new RegExp(excludedContextPatternSource.replaceAll('\\\\', '\\'), 'i');
    const semanticContext = (element) => {
        const parts = [];
        let current = element;

        for (let depth = 0; current && depth < 8; depth++, current = current.parentElement) {
            for (const attribute of [
                'id', 'class', 'role', 'aria-label', 'title', 'data-testid',
                'data-component-type', 'data-feature-name', 'data-cel-widget',
            ]) {
                const value = current.getAttribute?.(attribute);
                if (value) parts.push(value);
            }

            if (current.matches?.('section,aside,[role="region"],[role="dialog"],dialog')) {
                const heading = current.querySelector?.('h1,h2,h3,h4,[role="heading"]');
                if (heading?.textContent) parts.push(heading.textContent.slice(0, 240));
            }
        }

        return parts.join(' ').replace(/([a-z])([A-Z])/g, '$1 $2');
    };
    const excludedContext = (element) => excludedContextPattern.test(semanticContext(element));
    const safeElements = (selector) => {
        try {
            return [...document.querySelectorAll(selector)].filter((element) => !excludedContext(element));
        } catch {
            return [];
        }
    };
    const safeCount = (selector) => {
        try {
            return safeElements(selector).length;
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
            return safeElements(selector).filter((node) => {
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
    const dataImageValues = new Set(safeElements([
        '[class*="gallery" i] [data-image]',
        '[class*="swiper" i] .swiper-slide[data-image]',
        '[class*="product-media" i] [data-image]',
    ].join(','))
        .map((node) => node.getAttribute('data-image'))
        .filter(Boolean));
    const dataImageCount = dataImageValues.size;
    const declaredImageCount = Math.max(0, ...safeElements('[data-image-count]')
        .filter((node) => {
            const context = semanticContext(node);
            return /gallery|swiper|product[\s_-]*(?:image|media)/i.test(context);
        })
        .map((node) => {
            const value = Number.parseInt(node.getAttribute('data-image-count') || '', 10);

            return Number.isInteger(value) && value >= 2 && value <= 20 ? value : 0;
        }));
    let explicitCount = 0;
    const imageOrdinals = new Set();
    const nonImageOrdinals = new Set();
    const evidence = [];
    const evidenceNodes = new Set();

    for (const selector of selectors) {
        try {
            for (const element of safeElements(selector)) {
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
    const observedCount = Math.min(20, Math.max(
        explicitImageCount, thumbnailCount, dataImageCount, declaredImageCount,
    ));
    const targetCount = Math.min(20, Math.max(observedCount, expectedFromRecipe || 0));
    const signature = JSON.stringify({
        selectorCounts,
        thumbnailCount,
        dataImageCount,
        declaredImageCount,
        explicitCount,
        explicitImageCount,
    });

    return {
        selector_counts: selectorCounts,
        selector_max_count: selectorMax,
        thumbnail_count: thumbnailCount,
        data_image_count: dataImageCount,
        declared_image_count: declaredImageCount,
        explicit_image_count: explicitImageCount,
        observed_count: observedCount,
        target_count: targetCount,
        evidence,
        signature,
    };
}, {
    selectors: galleryStateSelectors,
    expectedFromRecipe: recipeNumber('expected_image_count', 0, 20),
    excludedContextPatternSource: EXCLUDED_GALLERY_CONTEXT_PATTERN_SOURCE,
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

const collectDomImages = async () => page.evaluate(({
    selectors,
    extraAttributes,
    includePageFallbacks,
    excludedContextPatternSource,
    excludeSelectors,
}) => {
    const excludedContextPattern = new RegExp(excludedContextPatternSource.replaceAll('\\\\', '\\'), 'i');
    // Recipe exclusions are element-exact. Using closest(selector) here lets
    // one broad model-chosen selector erase an entire wanted subtree.
    const matchesRecipeExclusion = (element) => {
        for (const selector of excludeSelectors) {
            try {
                if (element?.matches?.(selector)) {
                    return true;
                }
            } catch {
                // Invalid exclusions fail open and remain visible in diagnostics.
            }
        }

        return false;
    };
    const semanticContext = (element) => {
        const parts = [];
        let current = element;

        for (let depth = 0; current && depth < 8; depth++, current = current.parentElement) {
            for (const attribute of [
                'id', 'class', 'role', 'aria-label', 'title', 'data-testid',
                'data-component-type', 'data-feature-name', 'data-cel-widget',
            ]) {
                const value = current.getAttribute?.(attribute);
                if (value) parts.push(value);
            }

            if (current.matches?.('section,aside,[role="region"],[role="dialog"],dialog')) {
                const heading = current.querySelector?.('h1,h2,h3,h4,[role="heading"]');
                if (heading?.textContent) parts.push(heading.textContent.slice(0, 240));
            }
        }

        return parts.join(' ').replace(/([a-z])([A-Z])/g, '$1 $2');
    };
    const excludedContext = (element) => excludedContextPattern.test(semanticContext(element));
    const urls = [];
    const excludedContexts = [];
    const add = (value) => {
        if (typeof value === 'string' && value.trim() !== '') {
            urls.push(value.trim());
        }
    };
    const selectedElements = [];
    let excludedByRecipe = 0;
    let exclusionGuardTriggered = false;
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
        const mediaItem = element.closest?.('video,[data-type],[data-media-type],li,figure,[role="option"]');
        const mediaItemMarker = mediaItem ? [
            mediaItem.getAttribute?.('class'),
            mediaItem.getAttribute?.('data-type'),
            mediaItem.getAttribute?.('data-media-type'),
            mediaItem.getAttribute?.('aria-label'),
            mediaItem.getAttribute?.('title'),
        ].filter(Boolean).join(' ') : '';


        return element.matches?.('video,[data-type="video"],[data-media-type="video"]')
            || mediaItem?.matches?.('video,[data-type="video"],[data-media-type="video"],[class*="video" i],[class*="360" i]')
            || /(?:^|\W)(?:video|360)(?:\W|$)/i.test(`${marker} ${mediaItemMarker}`);
    };
    const addNode = (element, applyRecipeExclusions = true) => {
        if (!element) {
            return;
        }
        if (applyRecipeExclusions && matchesRecipeExclusion(element)) {
            excludedByRecipe += 1;
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
            // A srcset is a comma-separated list, not one URL. It is parsed
            // immediately below; treating the complete value as a scalar URL
            // produced malformed source URLs containing every rendition.
            if (['srcset', 'data-srcset'].includes(attribute.toLowerCase())) {
                continue;
            }

            add(element.getAttribute?.(attribute));
        }

        add(element.currentSrc);
        addSrcset(element.getAttribute?.('srcset'));
        addSrcset(element.getAttribute?.('data-srcset'));

        const background = getComputedStyle(element).backgroundImage;

        for (const match of background.matchAll(/url\((['"]?)(.*?)\1\)/g)) {
            add(match[2]);
        }

        // Same reasoning as srcset/data-src above, applied to an inline
        // onclick image-swap handler (real case: onclick="window.change
        // MainImage('.../02.png', this)") - domain-agnostic: any quoted,
        // image-extension-ending string inside onclick is a candidate,
        // regardless of the handler function's name.
        const onclick = element.getAttribute?.('onclick');

        if (typeof onclick === 'string') {
            for (const match of onclick.matchAll(/['"]([^'"\s]+\.(?:jpe?g|png|webp|gif|avif)(?:\?[^'"\s]*)?)['"]/gi)) {
                add(match[1]);
            }
        }

        add(element.closest?.('a[href]')?.href);
    };
    const addElement = (element, applyRecipeExclusions = true) => {
        if (applyRecipeExclusions && matchesRecipeExclusion(element)) {
            excludedByRecipe += 1;
            return;
        }

        if (excludedContext(element)) {
            excludedContexts.push(semanticContext(element).slice(0, 700));
            return;
        }

        if (isNonPhotoMedia(element)) {
            return;
        }

        addNode(element, applyRecipeExclusions);
        addNode(element?.parentElement, applyRecipeExclusions);

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
            if (applyRecipeExclusions && matchesRecipeExclusion(child)) {
                excludedByRecipe += 1;
                continue;
            }

            addNode(child, applyRecipeExclusions);
            addNode(child.parentElement, applyRecipeExclusions);
        }
    };

    for (const selector of selectors) {
        try {
            for (const element of document.querySelectorAll(selector)) {
                selectedElements.push(element);
                addElement(element);
            }
        } catch {
            // Ignore an invalid selector instead of aborting the entire recipe.
        }
    }
    // If exclusions erased every candidate, retry only the already matched
    // collect elements without recipe exclusions. Semantic/video guards stay.
    if (urls.length === 0 && selectedElements.length > 0 && excludedByRecipe > 0) {
        exclusionGuardTriggered = true;
        selectedElements.forEach((element) => addElement(element, false));
    }


    if (includePageFallbacks) {
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

    }

    return {
        urls,
        excluded_contexts: excludedContexts,
        excluded_by_recipe: excludedByRecipe,
        exclusion_guard_triggered: exclusionGuardTriggered,
    };
}, {
    selectors: [...new Set([...gallerySelectors, ...thumbnailSelectors])],
    extraAttributes: recipeAttributes,
    includePageFallbacks: !strictRecipe,
    excludedContextPatternSource: EXCLUDED_GALLERY_CONTEXT_PATTERN_SOURCE,
    excludeSelectors: recipeExcludeSelectors,
});

const collectionTarget = galleryCollectionTarget(limit, recipeNumber('expected_image_count', 0, 20));
const enoughCollected = () => new Set(
    gathered
        .map((url) => normalizeImageCandidate(url, sourceUrl))
        .filter(Boolean)
        .map(imageAssetKey),
).size >= collectionTarget;
const collect = async () => {
    const collection = await collectDomImages().catch((error) => {
        collectionErrors.push(String(error?.message || error || 'unknown collection error').slice(0, 1000));
        return { urls: [], excluded_contexts: [] };
    });
    gathered.push(...(collection.urls || []));
    excludedGalleryContexts.push(...(collection.excluded_contexts || []));
    if (collection.exclusion_guard_triggered) {
        collectionErrors.push('exclude_selectors removed every collected URL; retried exact collect matches without recipe exclusions');
    }
    if (!strictRecipe) {
    priorityImages.push(...await page.locator('[data-old-hires]').evaluateAll(
        (elements) => elements
            .filter((element) => !element.closest('video,[data-type="video"],[data-media-type="video"],[class*="video" i],[class*="360" i]'))
            .map((element) => element.getAttribute('data-old-hires'))
            .filter(Boolean),
    ).catch(() => []));
    }
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

    const actionWaitMs = Number.isInteger(meta.wait_after_ms)
        ? Math.max(50, Math.min(1500, meta.wait_after_ms))
        : recipeNumber('wait_after_click_ms', 250, 3000);
    const deadline = Date.now() + Math.max(
        500,
        Math.min(6000, actionWaitMs * 4),
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

let expandedGalleryAttempted = false;
const expandedGalleryVisible = async () => page.locator(expandedGalleryContainers.join(',')).evaluateAll((elements) => {
    const viewportArea = Math.max(1, innerWidth * innerHeight);

    return elements.some((element) => {
        const rect = element.getBoundingClientRect();
        const style = getComputedStyle(element);
        const visible = rect.width > 2
            && rect.height > 2
            && style.display !== 'none'
            && style.visibility !== 'hidden'
            && Number.parseFloat(style.opacity || '1') > 0;
        const modalSignal = element.matches(`[role='dialog'],[aria-modal='true'],dialog[open]`)
            || ['fixed', 'sticky'].includes(style.position)
            || (rect.width * rect.height) / viewportArea >= 0.35;

        return visible && modalSignal && Boolean(element.querySelector('img,picture,source'));
    });
}).catch(() => false);
const actionOpensExpandedGallery = (action) => openSelectors.includes(action.selector)
    || recipeActionOpensGallery(action);
const actionTraversesGallery = recipeActionTraversesGallery;
const attemptExpandedGallery = async (skipExplicitSelectors = false) => {
    if (expandedGalleryAttempted || leftProductPage || outOfTime()) {
        return expandedGalleryVisible();
    }

    expandedGalleryAttempted = true;

    if (recipe.gallery_present !== true || recipeNumber('expected_image_count', 0, 20) < 2) {
        return false;
    }

    if (await expandedGalleryVisible()) {
        await collect();
        return true;
    }

    if (!skipExplicitSelectors) {
        for (const selector of openSelectors) {
            const controls = page.locator(selector);
            const count = Math.min(await controls.count().catch(() => 0), 5);

            for (let index = 0; index < count && !leftProductPage && !outOfTime(); index++) {
                const control = controls.nth(index);

                if (!await control.isVisible().catch(() => false)) {
                    continue;
                }

                await control.scrollIntoViewIfNeeded({ timeout: 700 }).catch(() => {});
                const clicked = await clickAndWaitForGalleryChange(control, {
                    phase: 'open_expanded_gallery',
                    selector,
                    index,
                });
                await collect();
                const trace = actionTrace.at(-1) || {};

                if (clicked && (trace.changed === true || await expandedGalleryVisible())) {
                    return true;
                }
            }
        }
    }

    const scoutMediaControls = (Array.isArray(scout.action_candidates) ? scout.action_candidates : [])
        .filter((candidate) => candidate
            && candidate.contains_image === true
            && candidate.in_viewport === true
            && typeof candidate.selector === 'string'
            && candidate.selector !== ''
            && !candidate.href
            && Number(candidate.rect?.width || 0) >= 160
            && Number(candidate.rect?.height || 0) >= 160
            && !/(?:buy|cart|checkout|account|sign.?in|wishlist|share|review|recommend|related)/i.test(
                `${candidate.text || ''} ${candidate.aria_label || ''} ${candidate.title || ''}`,
            ))
        .sort((left, right) => (
            Number(right.rect?.width || 0) * Number(right.rect?.height || 0)
        ) - (
            Number(left.rect?.width || 0) * Number(left.rect?.height || 0)
        ));

    for (const candidate of scoutMediaControls.slice(0, 3)) {
        const controls = page.locator(candidate.selector);
        const count = await controls.count().catch(() => 0);

        if (count < 1) {
            continue;
        }

        const index = Math.min(Math.max(0, Number(candidate.selector_index || 0)), count - 1);
        const control = controls.nth(index);

        if (!await control.isVisible().catch(() => false)) {
            continue;
        }

        await control.scrollIntoViewIfNeeded({ timeout: 700 }).catch(() => {});
        const clicked = await clickAndWaitForGalleryChange(control, {
            phase: 'open_scout_media_control',
            selector: candidate.selector,
            index,
        });
        await collect();
        const trace = actionTrace.at(-1) || {};

        if (clicked && (trace.changed === true || await expandedGalleryVisible())) {
            return true;
        }
    }

    const mainImages = page.locator(mainGalleryImageSelectors.join(','));
    const bestIndex = await mainImages.evaluateAll((elements) => {
        let selected = -1;
        let selectedScore = 0;

        elements.forEach((element, index) => {
            const rect = element.getBoundingClientRect();
            const style = getComputedStyle(element);
            const excluded = element.closest([
                `[class*='thumbnail' i]`, `[class*='thumbs' i]`, `[role='option']`,
                `[aria-label*='thumbnail' i]`, `[class*='recommend' i]`, `[class*='related' i]`,
                `[class*='review' i]`, `[class*='video' i]`, `[class*='360' i]`,
            ].join(','));
            const visible = rect.width >= 160
                && rect.height >= 160
                && style.display !== 'none'
                && style.visibility !== 'hidden'
                && Number.parseFloat(style.opacity || '1') > 0;
            const naturalArea = Math.max(0, element.naturalWidth || 0) * Math.max(0, element.naturalHeight || 0);
            const score = rect.width * rect.height + Math.min(naturalArea, 4_000_000) / 10;

            if (!excluded && visible && score > selectedScore) {
                selected = index;
                selectedScore = score;
            }
        });

        return selected;
    }).catch(() => -1);

    if (bestIndex < 0) {
        actionTrace.push({
            phase: 'open_main_image_fallback',
            clicked: false,
            changed: false,
            selector_missing: true,
        });
        return false;
    }

    const mainImage = mainImages.nth(bestIndex);
    await mainImage.scrollIntoViewIfNeeded({ timeout: 700 }).catch(() => {});
    const clicked = await clickAndWaitForGalleryChange(mainImage, {
        phase: 'open_main_image_fallback',
        selector: mainGalleryImageSelectors.join(','),
        index: bestIndex,
    });
    await collect();
    const trace = actionTrace.at(-1) || {};

    return clicked && (trace.changed === true || await expandedGalleryVisible());
};

const captureInteractionScout = async (scopeToMedia = false) => page.evaluate(({ excludedContextPatternSource, scopeToMedia }) => {
    const excludedContextPattern = new RegExp(excludedContextPatternSource.replaceAll('\\\\', '\\'), 'i');
    const semanticContext = (element) => {
        const parts = [];
        let current = element;

        for (let depth = 0; current && depth < 8; depth++, current = current.parentElement) {
            for (const attribute of [
                'id', 'class', 'role', 'aria-label', 'title', 'data-testid',
                'data-component-type', 'data-feature-name', 'data-cel-widget',
            ]) {
                const value = current.getAttribute?.(attribute);
                if (value) parts.push(value);
            }

            if (current.matches?.('section,aside,[role="region"],[role="dialog"],dialog')) {
                const heading = current.querySelector?.('h1,h2,h3,h4,[role="heading"]');
                if (heading?.textContent) parts.push(heading.textContent.slice(0, 240));
            }
        }

        return parts.join(' ').replace(/([a-z])([A-Z])/g, '$1 $2');
    };
    const excludedContext = (element) => excludedContextPattern.test(semanticContext(element));
    const sanitize = (element) => {
        const clone = element.cloneNode(true);

        for (const node of [clone, ...clone.querySelectorAll('*')]) {
            for (const attribute of [...node.attributes]) {
                if (/^on/i.test(attribute.name) || ['style', 'nonce', 'integrity', 'srcset', 'data-srcset', 'data-bgset'].includes(attribute.name)) {
                    node.removeAttribute(attribute.name);
                }
            }
        }

        // svg is never a gallery photo source in this pipeline (product
        // photos are always <img src>/network requests, never inline paths)
        // but a single star-rating or icon svg can be thousands of
        // characters of path/gradient data - enough on its own to consume
        // the whole per-fragment budget below before any of the actually
        // useful text (captions, price, SKU) is reached.
        for (const node of clone.querySelectorAll('script,style,noscript,iframe,object,embed,form,input,textarea,svg')) {
            node.remove();
        }

        return clone.outerHTML.replace(/\s+/g, ' ').slice(0, 1600);
    };
    const visible = (element) => {
        const rect = element.getBoundingClientRect();
        const style = getComputedStyle(element);

        return rect.width > 1 && rect.height > 1
            && style.display !== 'none'
            && style.visibility !== 'hidden';
    };
    const inViewport = (element) => {
        const rect = element.getBoundingClientRect();

        return visible(element)
            && rect.bottom > 0
            && rect.right > 0
            && rect.top < innerHeight
            && rect.left < innerWidth;
    };
    const attributeSelector = (element, name, value) =>
        `${element.tagName.toLowerCase()}[${name}=${JSON.stringify(value)}]`;
    const selectorFor = (element) => {
        const id = element.getAttribute('id');

        if (id && id.length <= 100) {
            const selector = `#${CSS.escape(id)}`;

            if (document.querySelectorAll(selector).length === 1) {
                return selector;
            }
        }

        for (const name of ['data-testid', 'data-test', 'data-selenium', 'data-qa', 'aria-label', 'name']) {
            const value = element.getAttribute(name);

            if (!value || value.length > 160) {
                continue;
            }

            const selector = attributeSelector(element, name, value);

            try {
                if (document.querySelectorAll(selector).length > 0) {
                    return selector;
                }
            } catch {
                // Try the next stable attribute.
            }
        }

        const classTokens = [...element.classList]
            .filter((token) => token.length >= 3
                && token.length <= 60
                && !/^\d/.test(token)
                && !/[a-f0-9]{8,}/i.test(token))
            .slice(0, 2);

        if (classTokens.length) {
            return element.tagName.toLowerCase()+classTokens.map((token) => `.${CSS.escape(token)}`).join('');
        }

        return element.tagName.toLowerCase();
    };
    const rectFor = (element) => {
        const rect = element.getBoundingClientRect();

        return {
            x: Math.round(rect.x),
            y: Math.round(rect.y),
            width: Math.round(rect.width),
            height: Math.round(rect.height),
        };
    };
    const mediaContainerSelector = [
        '[class*=gallery i]', '[class*=thumbnail i]', '[class*=slider i]',
        '[class*=carousel i]', '[class*=swiper i]', '[class*=zoom i]',
        '[class*=product-image i]', '[class*=product-media i]', '[data-selenium*=media i]',
    ].join(',');
    // A later round already knows an interaction happened - scoping to the
    // confirmed media container instead of re-scanning the whole page again
    // (nav, footer, unrelated recommendation carousels that merely match
    // one of the same loose keywords) keeps the growing per-round payload
    // from ballooning (a real case: round 2's page snapshot grew from
    // ~55KB to ~79KB instead of shrinking, because the newly opened
    // viewer's controls were added on top of everything the first round
    // already matched, not scoped down to it). Only applied when at least
    // one match exists - an empty scoped set almost certainly means the
    // heuristic container selector missed the real one on this page, not
    // that nothing is there, so falling back to the unscoped list is safer
    // than silently hiding the gallery from the next round's reasoning.
    const scopeFilter = (list, isWithinMedia) => {
        if (!scopeToMedia) {
            return list;
        }

        const scoped = list.filter(isWithinMedia);

        return scoped.length > 0 ? scoped : list;
    };
    const candidates = scopeFilter(
        [...document.querySelectorAll([
            '[data-old-hires]', '[data-zoom-image]', '[data-large_image]', '[data-full]', '[itemprop=image]',
            '[class*=gallery i]', '[class*=thumbnail i]', '[class*=slider i]',
            '[class*=carousel i]', '[class*=swiper i]', '[class*=zoom i]',
            '[class*=product-image i]', '[class*=product-media i]', '[data-selenium*=media i]',
            'button[aria-label*=next i]', 'button[aria-label*=image i]',
        ].join(','))].filter((element) => !excludedContext(element)),
        (element) => Boolean(element.closest(mediaContainerSelector)),
    ).slice(0, 70);
    const interactiveControlElements = [...document.querySelectorAll('button,a,[role=button]')]
        .filter((element) => /\b(gallery|media|image|photo|thumbnail|carousel|slider|zoom|next|more)\b/i.test([
            element.getAttribute('aria-label'),
             element.getAttribute('title'),
             element.getAttribute('class'),
             element.getAttribute('data-selenium'),
             element.getAttribute('href'),
             element.textContent,
        ].filter(Boolean).join(' ')))
        .filter((element) => !excludedContext(element));
    const interactiveControls = scopeFilter(
        interactiveControlElements,
        (element) => Boolean(element.closest(mediaContainerSelector)),
    ).slice(0, 50)
        .map(sanitize)
        .filter(Boolean);
    const actionCandidateObjects = [...document.querySelectorAll('button,a,[role=button],summary')]
        .filter(visible)
        .filter((element) => !excludedContext(element))
        .map((element, documentIndex) => {
            const selector = selectorFor(element);
            const signal = [
                element.getAttribute('aria-label'),
                element.getAttribute('title'),
                element.getAttribute('class'),
                element.getAttribute('data-selenium'),
                element.getAttribute('href'),
                element.textContent,
            ].filter(Boolean).join(' ');
            const withinMedia = Boolean(element.closest(mediaContainerSelector));
            const containsImage = Boolean(element.querySelector('img,picture'));
            let selectorCount = 0;
            let selectorIndex = 0;

            try {
                const selectorMatches = [...document.querySelectorAll(selector)];
                selectorCount = selectorMatches.length;
                selectorIndex = Math.max(0, selectorMatches.indexOf(element));
            } catch {
                // The AI still receives the element, but knows the selector is unusable.
            }

            return {
                document_index: documentIndex,
                selector,
                selector_match_count: selectorCount,
                selector_index: selectorIndex,
                tag: element.tagName.toLowerCase(),
                role: element.getAttribute('role') || null,
                text: (element.textContent || '').replace(/\s+/g, ' ').trim().slice(0, 180),
                aria_label: (element.getAttribute('aria-label') || '').slice(0, 180),
                title: (element.getAttribute('title') || '').slice(0, 180),
                href: (element.getAttribute('href') || '').slice(0, 500),
                disabled: element.matches(':disabled,[aria-disabled=true]'),
                in_viewport: inViewport(element),
                within_media: withinMedia,
                contains_image: containsImage,
                rect: rectFor(element),
                relevance: (withinMedia ? 4 : 0)
                    + (containsImage ? 2 : 0)
                    + (inViewport(element) ? 1 : 0)
                    + (/\b(gallery|media|image|photo|thumbnail|carousel|slider|zoom|next|more)\b/i.test(signal) ? 5 : 0),
            };
        });
    const actionCandidates = scopeFilter(actionCandidateObjects, (candidate) => candidate.within_media)
        .sort((left, right) => right.relevance - left.relevance || left.document_index - right.document_index)
        .slice(0, 80)
        .map(({ relevance, ...candidate }) => candidate);
    const imageCandidateObjects = [...document.images].filter((element) => !excludedContext(element))
        .map((element, documentIndex) => {
            const dataAttributes = Object.fromEntries(
                [...element.attributes]
                    .filter((attribute) => /^data-/i.test(attribute.name)
                        && /(src|image|zoom|large|full|hires|original)/i.test(attribute.name))
                    .slice(0, 10)
                    .map((attribute) => [attribute.name, attribute.value.slice(0, 500)]),
            );
            const parentControl = element.closest('button,a,[role=button]');
            // A thumbnail button whose full-resolution URL is already a
            // quoted literal inside its own onclick (real case: onclick=
            // "window.changeMainImage('.../02.png', this)") needs no click
            // at all - but the agent can only notice that if this URL is
            // actually part of what it is shown, same reasoning as
            // data_attributes below.
            const parentControlOnclick = (parentControl?.getAttribute('onclick') || '').slice(0, 500) || null;

            return {
                document_index: documentIndex,
                selector: selectorFor(element),
                src: (element.getAttribute('src') || '').slice(0, 500),
                current_src: (element.currentSrc || '').slice(0, 500),
                alt: (element.getAttribute('alt') || '').slice(0, 180),
                natural_width: element.naturalWidth || 0,
                natural_height: element.naturalHeight || 0,
                rendered: rectFor(element),
                visible: visible(element),
                in_viewport: inViewport(element),
                within_media: Boolean(element.closest(mediaContainerSelector)),
                parent_control_selector: parentControl ? selectorFor(parentControl) : null,
                parent_control_onclick: parentControlOnclick,
                data_attributes: dataAttributes,
            };
        });
    const imageCandidates = scopeFilter(imageCandidateObjects, (candidate) => candidate.within_media)
        .sort((left, right) =>
            Number(right.within_media) - Number(left.within_media)
            || (right.natural_width * right.natural_height) - (left.natural_width * left.natural_height))
        .slice(0, 50);

    return {
        final_url: location.href,
        title: document.title.slice(0, 500),
        fragments: candidates.map(sanitize).filter(Boolean).slice(0, 32),
        interactive_controls: interactiveControls,
        action_candidates: actionCandidates,
        image_candidates: imageCandidates,
        page_geometry: {
            viewport_width: innerWidth,
            viewport_height: innerHeight,
            document_width: document.documentElement.scrollWidth,
            document_height: document.documentElement.scrollHeight,
            visible_dialogs: [...document.querySelectorAll('[role=dialog],dialog[open]')].filter(visible).length,
        },
    };
}, { excludedContextPatternSource: EXCLUDED_GALLERY_CONTEXT_PATTERN_SOURCE, scopeToMedia });

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

    scout = await captureInteractionScout();
    const accessState = await page.evaluate((httpStatus) => {
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

        return {
            http_status: httpStatus,
            access_gate: accessGateReason !== null,
            access_gate_reason: accessGateReason,
            rate_limited: httpStatus === 429,
        };
    }, navigationStatus);
    scout = { ...scout, ...accessState };
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
        ...recipeActions.flatMap((action) => [action.selector, action.after_each_selector].filter(Boolean)),
    ])]) {
        selectorCounts[selector] = await page.locator(selector).count().catch(() => 0);
    }
    learnedRecipe = {
        collect_selectors: gallerySelectors.filter((selector) => selectorCounts[selector] > 0).slice(0, 12),
        thumbnail_selectors: thumbnailSelectors.filter((selector) => selectorCounts[selector] > 1).slice(0, 8),
        open_selectors: openSelectors.filter((selector) => selectorCounts[selector] > 0).slice(0, 5),
        next_selectors: nextSelectors.filter((selector) => selectorCounts[selector] > 0).slice(0, 5),
        actions: recipeActions.filter((action) => selectorCounts[action.selector] > 0),
    };

    if (!scoutOnly) {
    if (recipeActions.length > 0) {
        for (let actionIndex = 0; actionIndex < recipeActions.length && !leftProductPage && !outOfTime(); actionIndex++) {
            const action = recipeActions[actionIndex];
            const priorViewerOpenAction = recipeActions
                .slice(0, actionIndex)
                .some(actionOpensExpandedGallery);

            if (!expandedGalleryAttempted && priorViewerOpenAction && actionTraversesGallery(action)) {
                await attemptExpandedGallery(priorViewerOpenAction);
            }

            const locator = page.locator(action.selector);
            const matched = await locator.count().catch(() => 0);

            if (matched < 1) {
                actionTrace.push({
                    action: action.kind,
                    phase: 'ai_action',
                    selector: action.selector,
                    action_index: actionIndex,
                    purpose: action.purpose,
                    clicked: false,
                    changed: false,
                    selector_missing: true,
                    selector_match_count: 0,
                });
                continue;
            }

            // limit is how many times the recipe asked for this control to be
            // pressed, not how many elements the selector happens to match.
            // Capping it at the match count silently broke every single-element
            // traversal control: a "next" arrow is one element on virtually any
            // site, so "advance three frames" executed exactly one click and the
            // remaining frames were never revealed (observed live on a B&H modal
            // recipe that declared 4 frames and returned 3). targetIndex below
            // already clamps to the last available element, so a multi-element
            // selector still walks its elements while a single control is simply
            // re-pressed.
            const repetitions = action.kind === 'click' ? 1 : action.limit;

            for (let repetition = 0; repetition < repetitions && !leftProductPage && !outOfTime(); repetition++) {
                const currentCount = await locator.count().catch(() => 0);

                if (currentCount < 1) {
                    break;
                }

                const targetIndex = action.kind === 'click_each'
                    ? Math.min(action.index + repetition, currentCount - 1)
                    : Math.min(action.index, currentCount - 1);
                const target = locator.nth(targetIndex);
                const controlSafety = await target.evaluate((element, { purpose, excludedContextPatternSource }) => {
                    const signal = [
                        element.getAttribute('id'),
                        element.getAttribute('class'),
                        element.getAttribute('aria-label'),
                        element.getAttribute('title'),
                        element.getAttribute('data-selenium'),
                        element.getAttribute('href'),
                        element.textContent,
                    ].filter(Boolean).join(' ');
                    const contextParts = [];
                    let current = element;
                    for (let depth = 0; current && depth < 8; depth++, current = current.parentElement) {
                        for (const attribute of [
                            'id', 'class', 'role', 'aria-label', 'title', 'data-testid',
                            'data-component-type', 'data-feature-name', 'data-cel-widget',
                        ]) {
                            const value = current.getAttribute?.(attribute);
                            if (value) contextParts.push(value);
                        }

                        if (current.matches?.('section,aside,[role="region"],[role="dialog"],dialog')) {
                            const heading = current.querySelector?.('h1,h2,h3,h4,[role="heading"]');
                            if (heading?.textContent) contextParts.push(heading.textContent.slice(0, 240));
                        }
                    }
                    const contextSignal = contextParts.join(' ').replace(/([a-z])([A-Z])/g, '$1 $2');
                    const excludedContext = new RegExp(
                        excludedContextPatternSource.replaceAll('\\\\', '\\'),
                        'i',
                    ).test(contextSignal);
                    const forbidden = excludedContext || /(buy|add.?to.?cart|checkout|place.?order|account|sign.?in|log.?in|wishlist|share|review|compare|subscribe|submit)/i.test(signal);
                    const mediaContainer = element.closest([
                        '[class*=gallery i]', '[class*=thumbnail i]', '[class*=slider i]',
                        '[class*=carousel i]', '[class*=swiper i]', '[class*=zoom i]',
                        '[class*=product-image i]', '[class*=product-media i]', '[data-selenium*=media i]',
                    ].join(','));
                    const dialogWithImages = element.closest('[role=dialog],dialog')?.querySelector('img,picture');
                    const mediaSignal = /\b(gallery|media|image|photo|thumbnail|carousel|slider|zoom|next|prev|previous|more|view.?all|continue.?shopping|cookie)\b/i.test(signal);
                    const consentSignal = /(?:cookie|consent|continue.?shopping)/i.test(purpose || '')
                        && /(?:accept|agree|allow|essential|continue|cookie|consent)/i.test(signal);

                    return {
                        safe: !forbidden && Boolean(
                            consentSignal
                            || mediaContainer
                            || dialogWithImages
                            || element.querySelector('img,picture')
                            || mediaSignal
                        ),
                        forbidden,
                        excluded_context: excludedContext,
                        media_signal: mediaSignal,
                        consent_signal: consentSignal,
                    };
                }, {
                    purpose: action.purpose,
                    excludedContextPatternSource: EXCLUDED_GALLERY_CONTEXT_PATTERN_SOURCE,
                }).catch(() => ({ safe: false }));

                if (controlSafety.safe !== true) {
                    actionTrace.push({
                        action: action.kind,
                        phase: 'ai_action',
                        selector: action.selector,
                        index: targetIndex,
                        action_index: actionIndex,
                        repetition,
                        purpose: action.purpose,
                        clicked: false,
                        changed: false,
                        unsafe_control: true,
                        control_safety: controlSafety,
                        selector_match_count: currentCount,
                    });
                    break;
                }

                await target.scrollIntoViewIfNeeded({ timeout: 700 }).catch(() => {});
                const clicked = await clickAndWaitForGalleryChange(target, {
                    action: action.kind,
                    phase: 'ai_action',
                    selector: action.selector,
                    index: targetIndex,
                    action_index: actionIndex,
                    repetition,
                    purpose: action.purpose,
                    wait_after_ms: action.wait_after_ms,
                    selector_match_count: currentCount,
                });
                await collect();
                const trace = actionTrace.at(-1) || {};
                trace.expanded_gallery_visible_after = await expandedGalleryVisible();

                if (clicked && action.kind === 'click_each' && action.after_each_selector) {
                    const followupLocator = page.locator(action.after_each_selector);
                    const followupLimit = Math.max(1, action.after_each_limit || 1);

                    for (let followupRepetition = 0; followupRepetition < followupLimit && !leftProductPage && !outOfTime(); followupRepetition++) {
                        const followupCount = await followupLocator.count().catch(() => 0);

                        if (followupCount < 1) {
                            actionTrace.push({
                                action: action.kind,
                                phase: 'ai_action_followup',
                                selector: action.after_each_selector,
                                action_index: actionIndex,
                                parent_repetition: repetition,
                                followup_repetition: followupRepetition,
                                purpose: action.purpose,
                                clicked: false,
                                changed: false,
                                selector_missing: true,
                                selector_match_count: 0,
                                after_each: true,
                            });
                            break;
                        }

                        const followupTarget = followupLocator.first();
                        const followupSafety = await followupTarget.evaluate((element) => {
                            const signal = [
                                element.id, element.className, element.getAttribute('aria-label'),
                                element.getAttribute('title'), element.getAttribute('data-selenium'),
                                element.getAttribute('href'), element.textContent,
                            ].filter(Boolean).join(' ');
                            const forbidden = /(buy|add.?to.?cart|checkout|place.?order|account|sign.?in|log.?in|wishlist|share|review|compare|subscribe|submit)/i.test(signal);
                            const media = element.closest('[class*=gallery i],[class*=media i],[class*=image i],[class*=zoom i],[data-selenium*=media i],[data-selenium*=zoom i]');

                            return { safe: !forbidden && Boolean(media || /zoom|enlarge|magnif|maximi[sz]e/i.test(signal)) };
                        }).catch(() => ({ safe: false }));
                        if (followupSafety.safe !== true) {
                            actionTrace.push({
                                action: action.kind, phase: 'ai_action_followup',
                                selector: action.after_each_selector, action_index: actionIndex,
                                parent_repetition: repetition, followup_repetition: followupRepetition,
                                clicked: false, changed: false, unsafe_control: true,
                                selector_match_count: followupCount, after_each: true,
                            });
                            break;
                        }
                        await followupTarget.scrollIntoViewIfNeeded({ timeout: 700 }).catch(() => {});
                        const followupClicked = await clickAndWaitForGalleryChange(followupTarget, {
                            action: action.kind,
                            phase: 'ai_action_followup',
                            selector: action.after_each_selector,
                            index: 0,
                            action_index: actionIndex,
                            repetition: followupRepetition,
                            parent_repetition: repetition,
                            followup_repetition: followupRepetition,
                            purpose: action.purpose,
                            wait_after_ms: action.after_each_wait_after_ms,
                            selector_match_count: followupCount,
                            after_each: true,
                        });
                        await collect();
                        const followupTrace = actionTrace.at(-1) || {};
                        followupTrace.after_each = true;
                        followupTrace.parent_repetition = repetition;
                        followupTrace.followup_repetition = followupRepetition;
                        followupTrace.expanded_gallery_visible_after = await expandedGalleryVisible();

                        if (!followupClicked || followupTrace.changed !== true) {
                            break;
                        }
                    }
                }

                if (recipeActionShouldStop({
                    kind: action.kind,
                    clicked,
                    changed: trace.changed,
                    matchCount: currentCount,
                })) {
                    break;
                }
            }
        }
    } else {
        await attemptExpandedGallery();
        await collect();
        const thumbnails = thumbnailSelectors.length ? page.locator(thumbnailSelectors.join(',')) : null;
    const thumbnailCount = thumbnails
        ? Math.min(await thumbnails.count(), recipeNumber('max_thumbnail_clicks', 20, 20))
        : 0;

    let thumbnailClicks = 0;
    for (let index = 0; index < thumbnailCount && !leftProductPage && !outOfTime(); index++) {
        const thumbnail = thumbnails.nth(index);
        await thumbnail.scrollIntoViewIfNeeded({ timeout: 500 }).catch(() => {});
        const clicked = await clickAndWaitForGalleryChange(thumbnail, {
            phase: 'thumbnail',
            selector: thumbnailSelectors.join(','),
            index,
        });
        await collect();
        thumbnailClicks += clicked ? 1 : 0;
    }

    const nextButtons = nextSelectors.length ? page.locator(nextSelectors.join(',')) : null;
    const seenNextSignatures = new Set([await collectionSignature()]);

    if (nextButtons && await nextButtons.count()) {
        for (let index = 0; index < recipeNumber('max_next_clicks', 15, 15) && !leftProductPage && !outOfTime(); index++) {
            const clicked = await clickAndWaitForGalleryChange(nextButtons.first(), {
                phase: 'next',
                selector: nextSelectors.join(','),
                index,
            });
            await collect();
            const trace = actionTrace.at(-1) || {};
            const signature = await collectionSignature();
            const repeatedState = seenNextSignatures.has(signature);
            seenNextSignatures.add(signature);

            if (!clicked || trace.changed !== true || repeatedState) {
                break;
            }
        }
    }
    }

    if (!outOfTime()) {
        if (!enoughCollected()) {
            galleryReadiness = await waitForStableGallery();
            await collect();
        }
        postInteractionScout = await captureInteractionScout(true).catch(() => ({}));
        await collect();
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
        'li[class*="video" i],figure[class*="video" i],[role="option"][class*="video" i],button[class*="video" i]',
        'li[class*="360" i],figure[class*="360" i],[role="option"][class*="360" i],button[class*="360" i]',
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
const actionPlanStatus = recipeActionPlanStatus({ actions: recipeActions, actionTrace });
const expectedGalleryCount = recipeNumber('expected_image_count', 0, 20);
const distinctDomAssetCount = new Set(domImages.map(imageAssetKey)).size;
const structurallyCompletedRecipe = strictRecipe
    && recipe.gallery_present === true
    && expectedGalleryCount >= 2
    && distinctDomAssetCount >= expectedGalleryCount
    && actionPlanStatus.required === true
    && actionPlanStatus.complete === true;
const galleryGoalReached = structurallyCompletedRecipe;
const hasDirectBhImages = allCandidates.some((url) => new URL(url).hostname === 'static.bhphoto.com');
const domKeys = new Set(domImages.map(imageAssetKey));
const candidates = strictRecipe
    ? [...new Set(domImages)]
    : (priorityDomImages.length >= 2
        ? priorityDomImages
        : (domImages.length >= 2
            ? allCandidates.filter((candidate) => domKeys.has(imageAssetKey(candidate)))
            : allCandidates));
const probeImage = async (candidate) => {
    if (!await publicHttpUrl(candidate)) {
        return { ok: false, url: candidate, reason: 'non_public_url' };
    }

    return page.evaluate(({ url, timeout, minWidth, minHeight }) => new Promise((resolve) => {
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
            const ok = width >= minWidth && height >= minHeight;

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
        minWidth: minimumWidth,
        minHeight: minimumHeight,
    }).catch(() => ({ ok: false, url: candidate, reason: 'probe_failed' }));
};
const candidatesToProbe = prioritizeCandidateRenditions(candidates)
    .slice(0, Math.max(30, limit * 3));
const probes = [];

if (!scoutOnly) {
    for (let index = 0; index < candidatesToProbe.length && !outOfTime(); index += 4) {
        probes.push(...await Promise.all(candidatesToProbe.slice(index, index + 4).map(probeImage)));
    }
}

const bestImages = new Map();
const validationFailures = [];

for (const probe of probes) {
    if (!probe.ok) {
        validationFailures.push({ url: probe.url, reason: probe.reason });
        continue;
    }

    // Keep the exact URL that the browser fetched and decoded.
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
        bestImages.set(key, {
            url: candidate,
            score,
            width: probe.width || 0,
            height: probe.height || 0,
            source: domKeys.has(key) ? 'recipe_dom' : 'network_or_payload',
        });
    }
}

const images = [...bestImages.values()].map((item) => item.url).slice(0, limit);
const transferredImages = [];
const transferFailures = [];

// Reuse the same BrowserContext (cookies, referer and anti-bot session) that
// opened the gallery. PHP may be unable to download these protected CDN URLs
// independently, so validated bytes are handed off through private temp files.
if (!scoutOnly && transferDirectory !== '') {
    const selected = [...bestImages.values()].slice(0, limit);

    for (let index = 0; index < selected.length; index += 4) {
        const batch = selected.slice(index, index + 4);
        const results = await Promise.all(batch.map(async (item, batchIndex) => {
            const ordinal = index + batchIndex;

            try {
                if (!await publicHttpUrl(item.url)) return null;
                const response = await context.request.get(item.url, {
                    failOnStatusCode: false,
                    timeout: 2500,
                    headers: { referer: page.url() },
                });
                if (!response.ok()) throw new Error(`HTTP ${response.status()}`);
                const body = await response.body();
                if (body.length === 0 || body.length > 8_388_608) throw new Error('invalid_transfer_size');
                const path = join(transferDirectory, `image-${ordinal}.bin`);
                await writeFile(path, body);

                return { url: item.url, final_url: response.url(), path, bytes: body.length };
            } catch (error) {
                transferFailures.push({ url: item.url, reason: String(error?.message || error).slice(0, 200) });
                return null;
            }
        }));
        transferredImages.push(...results.filter(Boolean));
    }
}
await browser.close();

process.stdout.write(JSON.stringify({
    images,
    transferred_images: transferredImages,
    scout,
    post_interaction_scout: postInteractionScout,
    action_trace: actionTrace,
    learned_recipe: learnedRecipe,
    diagnostics: {
        dom_candidates: domImages.length,
        raw_dom_candidates: gathered.length,
        raw_dom_samples: [...new Set(gathered)].slice(0, 20),
        strict_recipe: strictRecipe,
        excluded_gallery_contexts: [...new Set(excludedGalleryContexts)].slice(0, 20),
        payload_candidates: embeddedImages.length,
        network_candidates: requestedImages.length,
        unique_candidates: allCandidates.length,
        distinct_dom_assets: new Set(domImages.map(imageAssetKey)).size,
        collection_errors: [...new Set(collectionErrors)].slice(0, 10),
        distinct_candidate_assets: new Set(candidates.map(imageAssetKey)).size,
        probed_candidates: probes.length,
        validated_candidates: images.length,
        validated_image_evidence: [...bestImages.values()].slice(0, limit).map(({ url, width, height, source }) => ({
            url,
            width,
            height,
            source,
        })),
        rejected_candidates: validationFailures.slice(0, 20),
        observed_gallery_count: galleryReadiness.observed_count || 0,
        gallery_readiness: {
            thumbnail_count: galleryReadiness.thumbnail_count || 0,
            data_image_count: galleryReadiness.data_image_count || 0,
            declared_image_count: galleryReadiness.declared_image_count || 0,
            explicit_image_count: galleryReadiness.explicit_image_count || 0,
            evidence: galleryReadiness.evidence || [],
        },
        gallery_waited_ms: galleryReadiness.waited_ms || 0,
        gallery_stable_samples: galleryReadiness.stable_samples || 0,
        gallery_wait_timed_out: galleryReadiness.timed_out || false,
        gallery_goal_reached: galleryGoalReached,
        effective_minimum_width: minimumWidth,
        effective_minimum_height: minimumHeight,
        stopped_early: outOfTime(),
        action_plan: actionPlanStatus,
        browser_transfer_failures: transferFailures.slice(0, 20),
    },
}));
