import dns from 'node:dns/promises';
import net from 'node:net';
import { chromium } from 'playwright-core';

const sourceUrl = process.argv[2];
const limit = Math.max(1, Math.min(60, Number.parseInt(process.argv[3] || '20', 10)));

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

const gallerySelectors = [
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

const collectDomImages = async () => page.evaluate((selectors) => {
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

        for (const attribute of [
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
        ]) {
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
        for (const element of document.querySelectorAll(selector)) {
            addElement(element);
            addElement(element.parentElement);
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
}, gallerySelectors);

const gathered = [];
const collect = async () => {
    gathered.push(...await collectDomImages());
};

try {
    await page.goto(sourceUrl, { waitUntil: 'domcontentloaded', timeout: 20_000 });
    await page.waitForLoadState('networkidle', { timeout: 5_000 }).catch(() => {});
    await collect();

    const thumbnails = page.locator(gallerySelectors.slice(0, 10).join(','));
    const thumbnailCount = Math.min(await thumbnails.count(), 20);

    for (let index = 0; index < thumbnailCount; index++) {
        const thumbnail = thumbnails.nth(index);
        await thumbnail.scrollIntoViewIfNeeded({ timeout: 500 }).catch(() => {});
        await thumbnail.click({ force: true, timeout: 700 }).catch(() => {});
        await page.waitForTimeout(80);
        await collect();
    }

    const openMediaButtons = page.locator([
        'button[data-selenium*="media" i]',
        'button[class*="openmedia" i]',
        'button[class*="gallery" i]',
        'button[class*="thumbnail" i]',
        '[role="button"][class*="media" i]',
    ].join(','));
    const openMediaCount = Math.min(await openMediaButtons.count(), 5);

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

    const nextButtons = page.locator([
        'button[aria-label*="next" i]',
        'button[data-selenium*="next" i]',
        'button[class*="next" i]',
    ].join(','));

    if (await nextButtons.count()) {
        for (let index = 0; index < Math.min(limit, 15); index++) {
            await nextButtons.first().click({ force: true, timeout: 700 }).catch(() => {});
            await page.waitForTimeout(100);
            await collect();
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

    return Math.max(pathSize, querySize);
};
const assetKey = (rawUrl) => {
    const url = new URL(rawUrl);

    if (url.hostname.endsWith('bhphoto.com')) {
        return `bh:${url.pathname.split('/').pop()?.toLowerCase()}`;
    }

    for (const key of ['width', 'height', 'w', 'h', 'quality', 'q', 'fit']) {
        url.searchParams.delete(key);
    }

    return url.toString();
};
const domKeys = new Set(domImages.map(assetKey));
const candidates = domImages.length >= 2
    ? allCandidates.filter((candidate) => domKeys.has(assetKey(candidate)))
    : allCandidates;
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

process.stdout.write(JSON.stringify({ images }));
