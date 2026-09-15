import { test } from 'node:test';
import assert from 'node:assert/strict';
import { chromium } from 'playwright-core';
import { isNavigationRequestOffConfinedHost } from '../../scripts/product-gallery-utils.mjs';

/**
 * Point 3 of the latest follow-up: the browser re-read's confinement must
 * block a navigation attempt BEFORE it reaches a different host - not just
 * report, afterward, whichever host the session ended up on - while never
 * blocking ordinary sub-resource loads (images, scripts) from a CDN on a
 * different host from the page itself.
 *
 * Driven with real Playwright Request objects from a live page.route()
 * handler (the exact function extract-product-gallery.mjs itself calls in
 * its own route handler), not hand-modelled request-like objects - a fake
 * object with the right method names would not prove Playwright's actual
 * request.isNavigationRequest()/request.frame() behave the way the
 * function assumes.
 *
 * No real network is used: every request is intercepted and fulfilled
 * locally, so fake hostnames (which do not resolve) work here exactly like
 * real ones would.
 */

test('a main-frame navigation to a different host is aborted before it completes', async (t) => {
    const browser = await launch();
    if (!browser) {
        t.skip('No Chromium available on this machine.');
        return;
    }

    try {
        const page = await browser.newPage();
        const confineHost = 'shop.internal.test';

        await page.route('**/*', async (route) => {
            if (isNavigationRequestOffConfinedHost(route.request(), page.mainFrame(), confineHost)) {
                await route.abort('blockedbyclient');
                return;
            }

            await route.fulfill({ status: 200, contentType: 'text/html', body: '<html><body>Product page</body></html>' });
        });

        await page.goto(`https://${confineHost}/product`);
        assert.equal(page.url(), `https://${confineHost}/product`);

        // A click/script-driven navigation attempt to a foreign host - the
        // exact shape of the gap found: nothing checked a mid-session
        // navigation attempt itself before this fix, only wherever the
        // session ended up afterward.
        await page.evaluate(() => {
            window.location.href = 'https://evil.other.test/hijack';
        }).catch(() => {});
        await page.waitForTimeout(200);

        // A blocked top-level navigation lands Chromium on its own internal
        // error page, not silently back on the original URL - what matters
        // is that the evil host's own content was never reached: the page
        // is neither there nor holding its response.
        assert.notEqual(page.url(), 'https://evil.other.test/hijack');
        assert.ok(!page.url().includes('evil.other.test'), `page must never reach the foreign host, got: ${page.url()}`);
    } finally {
        await browser.close();
    }
});

test('an image request from a different host (a CDN) is never blocked by the confinement', async (t) => {
    const browser = await launch();
    if (!browser) {
        t.skip('No Chromium available on this machine.');
        return;
    }

    try {
        const page = await browser.newPage();
        const confineHost = 'shop.internal.test';
        const imageRequests = [];

        await page.route('**/*', async (route) => {
            const request = route.request();

            if (isNavigationRequestOffConfinedHost(request, page.mainFrame(), confineHost)) {
                await route.abort('blockedbyclient');
                return;
            }

            if (request.url().endsWith('.jpg')) {
                imageRequests.push(request.url());
                // A 1x1 transparent JPEG-ish payload is unnecessary - only
                // whether the request was allowed through matters here.
                await route.fulfill({ status: 200, contentType: 'image/jpeg', body: Buffer.from([0xff, 0xd8, 0xff, 0xd9]) });
                return;
            }

            await route.fulfill({
                status: 200,
                contentType: 'text/html',
                body: '<html><body><img src="https://cdn.other.test/photo.jpg"></body></html>',
            });
        });

        await page.goto(`https://${confineHost}/product`);
        await page.waitForLoadState('networkidle').catch(() => {});

        assert.deepEqual(imageRequests, ['https://cdn.other.test/photo.jpg']);
        assert.equal(page.url(), `https://${confineHost}/product`);
    } finally {
        await browser.close();
    }
});

test('with no confined host, navigation to any host proceeds exactly as before', async (t) => {
    // Backward compatibility for every existing caller (extract(),
    // executeRecipe(), the trainer's own scout()) - none of them pass a
    // confined host, and none of their behaviour may change.
    const browser = await launch();
    if (!browser) {
        t.skip('No Chromium available on this machine.');
        return;
    }

    try {
        const page = await browser.newPage();

        await page.route('**/*', async (route) => {
            if (isNavigationRequestOffConfinedHost(route.request(), page.mainFrame(), '')) {
                await route.abort('blockedbyclient');
                return;
            }

            await route.fulfill({ status: 200, contentType: 'text/html', body: '<html><body>Loaded</body></html>' });
        });

        await page.goto('https://anything.test/page');
        assert.equal(page.url(), 'https://anything.test/page');
    } finally {
        await browser.close();
    }
});

const launch = async () => {
    for (const channel of ['msedge', 'chrome', null]) {
        try {
            return await chromium.launch({ headless: true, ...(channel ? { channel } : {}) });
        } catch {
            // Try the next locally available Chromium.
        }
    }

    return null;
};
