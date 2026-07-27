import dns from 'node:dns/promises';
import net from 'node:net';
import { chromium } from 'playwright-core';

const sourceUrl = process.argv[2];
const limit = Math.max(1, Math.min(60, Number.parseInt(process.argv[3] || '20', 10)));
const scoutOnly = process.env.PRODUCT_GALLERY_SCOUT_ONLY === '1';
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
    const addElement = (element) => {
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

    for (const selector of selectors) {
        try {
            for (const element of document.querySelectorAll(selector)) {
                addElement(element);
                addElement(element.parentElement);
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
}, { selectors: gallerySelectors, extraAttributes: recipeAttributes });

const gathered = [];
const priorityImages = [];
let learnedRecipe = {};
let scout = {};
const collect = async () => {
    gathered.push(...await collectDomImages());
    priorityImages.push(...await page.locator('[data-old-hires]').evaluateAll(
        (elements) => elements.map((element) => element.getAttribute('data-old-hires')).filter(Boolean),
    ).catch(() => []));
};

try {
    await page.goto(sourceUrl, { waitUntil: 'domcontentloaded', timeout: 20_000 });
    await page.waitForLoadState('networkidle', { timeout: 5_000 }).catch(() => {});

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
        await page.waitForLoadState('networkidle', { timeout: 5_000 }).catch(() => {});
    }

    await page.locator('#sp-cc-accept').click({ timeout: 500 }).catch(() => {});

    for (const selector of preClickSelectors) {
        const control = page.locator(selector).first();

        if (await control.count().catch(() => 0)) {
            await control.click({ force: true, timeout: 1_000 }).catch(() => {});
            await page.waitForTimeout(recipeNumber('wait_after_click_ms', 150, 1000));
        }
    }

    scout = await page.evaluate(() => {
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
                    if (/^on/i.test(attribute.name) || ['style', 'nonce', 'integrity'].includes(attribute.name)) {
                        node.removeAttribute(attribute.name);
                    }
                }
            }

            for (const node of clone.querySelectorAll('script,style,noscript,iframe,object,embed,form,input,textarea')) {
                node.remove();
            }

            return clone.outerHTML.replace(/\s+/g, ' ').slice(0, 1000);
        };

        return {
            final_url: location.href,
            title: document.title.slice(0, 500),
            fragments: candidates.map(sanitize).filter(Boolean).slice(0, 18),
        };
    });

    await collect();

    const selectorCounts = {};
    for (const selector of [...new Set([...gallerySelectors, ...openSelectors, ...nextSelectors])]) {
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

    for (let index = 0; index < thumbnailCount; index++) {
        const thumbnail = thumbnails.nth(index);
        await thumbnail.scrollIntoViewIfNeeded({ timeout: 500 }).catch(() => {});
        await thumbnail.click({ force: true, timeout: 700 }).catch(() => {});
        await page.waitForTimeout(recipeNumber('wait_after_click_ms', 100, 1000));
        await collect();
    }

    const openMediaButtons = openSelectors.length ? page.locator(openSelectors.join(',')) : null;
    const openMediaCount = openMediaButtons ? Math.min(await openMediaButtons.count(), 5) : 0;

    for (let index = 0; index < openMediaCount; index++) {
        const button = openMediaButtons.nth(index);
        const text = (await button.innerText({ timeout: 300 }).catch(() => '')).trim();
        const marker = `${await button.getAttribute('class') || ''} ${await button.getAttribute('data-selenium') || ''}`;

        if (!/^\+\s*\d+$/.test(text) && !/(open.?media|gallery|see.?more|view.?all)/i.test(`${text} ${marker}`)) {
            continue;
        }

        await button.click({ force: true, timeout: 700 }).catch(() => {});
        await page.waitForTimeout(250);
        await collect();
    }

    const nextButtons = nextSelectors.length ? page.locator(nextSelectors.join(',')) : null;

    if (nextButtons && await nextButtons.count()) {
        for (let index = 0; index < Math.min(limit, recipeNumber('max_next_clicks', 15, 15)); index++) {
            await nextButtons.first().click({ force: true, timeout: 700 }).catch(() => {});
            await page.waitForTimeout(100);
            await collect();
        }
    }
    }
} finally {
    await Promise.allSettled([...pendingPayloads]);
    await browser.close();
}

const normalize = (rawUrl) => {
    let url;

    try {
        url = new URL(String(rawUrl).replaceAll('&amp;', '&'), sourceUrl);
    } catch {
        return null;
    }

    if (!['http:', 'https:'].includes(url.protocol)) {
        return null;
    }

    let normalized = url.toString();

    if (url.hostname.endsWith('bhphoto.com')) {
        normalized = normalized
            .replace('/multiple_images/thumbnails/', '/multiple_images/images500x500/')
            .replace('/images/smallimages/', '/images/images500x500/');
    }

    const pathSize = Number.parseInt(normalized.match(/images(\d+)x\d+/i)?.[1] || '0', 10);

    if (!/\.(?:jpe?g|png|webp)(?:$|[/?#])/i.test(normalized)
        || (pathSize > 0 && pathSize < 200)
        || /\.(svg|gif|ico)(?:$|[?#])/i.test(normalized)
        || /(?:logo|icon|sprite|badge|avatar|tracking|pixel|oldiemessage|\/images\/fb\/|favicon|social)/i.test(normalized)) {
        return null;
    }

    return normalized;
};

const domImages = gathered.map(normalize).filter(Boolean);
const priorityDomImages = [...new Set(priorityImages.map(normalize).filter(Boolean))];
const requestedImages = networkImages.map(normalize).filter(Boolean);
const embeddedImages = payloadImages.map(normalize).filter(Boolean);
const allCandidates = [...new Set([...domImages, ...embeddedImages, ...requestedImages])];
const hasDirectBhImages = allCandidates.some((url) => new URL(url).hostname === 'static.bhphoto.com');
const qualityScore = (rawUrl) => {
    const url = new URL(rawUrl);
    const pathSize = Number.parseInt(url.pathname.match(/images(\d+)x\d+/i)?.[1] || '0', 10);
    const querySize = Math.max(
        Number.parseInt(url.searchParams.get('width') || url.searchParams.get('w') || '0', 10),
        Number.parseInt(url.searchParams.get('height') || url.searchParams.get('h') || '0', 10),
    );
    const amazonSize = Number.parseInt(url.pathname.match(/\._(?:AC_)?S(?:L|X|Y)(\d+)/i)?.[1] || '0', 10);

    return Math.max(pathSize, querySize, amazonSize);
};
const assetKey = (rawUrl) => {
    const url = new URL(rawUrl);

    if (url.hostname.endsWith('bhphoto.com')) {
        return `bh:${url.pathname.split('/').pop()?.toLowerCase()}`;
    }

    if (url.hostname.endsWith('media-amazon.com')) {
        return `amazon:${url.pathname.replace(/\._[^/]+(?=\.[^./]+$)/, '').toLowerCase()}`;
    }

    for (const key of ['width', 'height', 'w', 'h', 'quality', 'q', 'fit']) {
        url.searchParams.delete(key);
    }

    return url.toString();
};
const domKeys = new Set(domImages.map(assetKey));
const candidates = priorityDomImages.length >= 2
    ? priorityDomImages
    : (domImages.length >= 2
        ? allCandidates.filter((candidate) => domKeys.has(assetKey(candidate)))
        : allCandidates);
const bestImages = new Map();

for (const candidate of candidates) {
    if (hasDirectBhImages && candidate.includes('bhphotovideo.com/cdn-cgi/image/')) {
        continue;
    }

    const key = assetKey(candidate);
    const existing = bestImages.get(key);

    if (!existing || qualityScore(candidate) > qualityScore(existing)) {
        bestImages.set(key, candidate);
    }
}

const images = [...bestImages.values()].slice(0, limit);

process.stdout.write(JSON.stringify({
    images,
    scout,
    learned_recipe: learnedRecipe,
    diagnostics: {
        dom_candidates: domImages.length,
        payload_candidates: embeddedImages.length,
        network_candidates: requestedImages.length,
        unique_candidates: allCandidates.length,
    },
}));
