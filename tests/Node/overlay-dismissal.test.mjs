import test from 'node:test';
import assert from 'node:assert/strict';
import { chromium } from 'playwright-core';
import { clearBlockingOverlays } from '../../scripts/product-gallery-utils.mjs';

// A real browser with no network: setContent gives the exact document each case
// needs, so these assert behaviour rather than a guess about how some shop is
// built today.
const withPage = async (html, assertions) => {
    let browser = null;

    for (const channel of ['msedge', 'chrome', null]) {
        try {
            browser = await chromium.launch({ headless: true, ...(channel ? { channel } : {}) });
            break;
        } catch {
            // Try the next locally available Chromium channel.
        }
    }

    if (!browser) {
        return;
    }

    const page = await browser.newPage();

    try {
        await page.setContent(html);
        await assertions(page);
    } finally {
        await browser.close();
    }
};

const overlay = (inner) => `<!doctype html><html><body>
    <main><h1>Product</h1><img id="hero" src="data:image/gif;base64,R0lGODlhAQABAAAAACw=" width="600" height="600"></main>
    <div id="consent" style="position:fixed;inset:0;background:#fff;z-index:9999">${inner}</div>
</body></html>`;

test('a consent wall is dismissed before the page is read', async () => {
    await withPage(overlay('<p>We use cookies</p><button id="ok">Accept all</button>'), async (page) => {
        const result = await clearBlockingOverlays(page);

        assert.equal(result.dismissed.length, 1);
        assert.match(result.dismissed[0].label, /Accept/i);
        assert.deepEqual(result.still_blocking, []);
    });
});

test('a consent wall in another language is dismissed too', async () => {
    // These shops are European; an English-only label list would leave every
    // one of them behind its own banner.
    await withPage(overlay('<p>Cookies</p><button>Akzeptieren</button>'), async (page) => {
        const result = await clearBlockingOverlays(page);

        assert.equal(result.dismissed.length, 1);
    });
});

test('a gallery viewer is never mistaken for an obstacle', async () => {
    // The expanded gallery is a full-screen dialog with a close button - the
    // one overlay that must survive, because it holds what we came for.
    await withPage(`<!doctype html><html><body>
        <dialog open id="viewer" style="position:fixed;inset:0;z-index:9999">
            <button aria-label="Close">x</button>
            <img src="data:image/gif;base64,R0lGODlhAQABAAAAACw=" width="900" height="900">
        </dialog>
    </body></html>`, async (page) => {
        const result = await clearBlockingOverlays(page);

        assert.deepEqual(result.dismissed, []);
        assert.deepEqual(result.still_blocking, []);
    });
});

test('an overlay with no way out is reported instead of hidden', async () => {
    // Silence here would leave the agent planning a recipe for a document it
    // cannot actually see.
    await withPage(overlay('<p>Subscribe to our newsletter</p>'), async (page) => {
        const result = await clearBlockingOverlays(page);

        assert.deepEqual(result.dismissed, []);
        assert.equal(result.still_blocking.length, 1);
    });
});

test('ordinary page furniture is left alone', async () => {
    // A sticky header is pinned and visible but blocks nothing; treating it as
    // an obstacle would have the runner clicking through the site menu.
    await withPage(`<!doctype html><html><body>
        <header style="position:sticky;top:0;height:60px"><a href="/menu">Menu</a></header>
        <main><img src="data:image/gif;base64,R0lGODlhAQABAAAAACw=" width="600" height="600"></main>
    </body></html>`, async (page) => {
        const result = await clearBlockingOverlays(page);

        assert.deepEqual(result.dismissed, []);
        assert.deepEqual(result.still_blocking, []);
    });
});
