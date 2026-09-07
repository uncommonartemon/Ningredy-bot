import { test } from 'node:test';
import assert from 'node:assert/strict';
import { chromium } from 'playwright-core';
import { readProductIdentityInPage, sameProductIdentity } from '../../scripts/product-gallery-utils.mjs';

/**
 * The check that replaced a list of English tab names. It went in untested and
 * an earlier attempt at the same repair let /store/laptops/model-a reach
 * /store/laptops/model-b, which a path rule cannot tell apart from
 * /product/spec reaching /product/gallery. This is the evidence that it does.
 */
test('an id both pages carry settles it, either way', () => {
    assert.equal(
        sameProductIdentity({ sku: 'NX-JNQEK-003', name: 'aspire 14' }, { sku: 'NX-JNQEK-003', name: 'aspire 14 gallery' }),
        true,
    );
    // The case the tab-name list was accidentally covering.
    assert.equal(
        sameProductIdentity({ sku: 'MODEL-A', canonical: '/store/laptops/model-a' }, { sku: 'MODEL-B', canonical: '/store/laptops/model-b' }),
        false,
    );
});

test('a canonical or og url answers when there is no id', () => {
    assert.equal(
        sameProductIdentity({ canonical: '/p/laptop-9' }, { canonical: '/p/laptop-9' }),
        true,
        "A product's own gallery tab points back at the product.",
    );
    assert.equal(
        sameProductIdentity({ canonical: '/p/laptop-9' }, { canonical: '/p/laptop-10' }),
        false,
    );
    assert.equal(
        sameProductIdentity({ og_url: '/p/laptop-9' }, { og_url: '/p/laptop-9' }),
        true,
    );
});

test('the strongest available evidence is the one that decides', () => {
    // Names collide across configurations of one laptop, so an id outranks
    // them; a page that only has a name still gets an answer from it.
    assert.equal(
        sameProductIdentity(
            { sku: 'A', canonical: '/p/one', name: 'thinkpad x1' },
            { sku: 'B', canonical: '/p/one', name: 'thinkpad x1' },
        ),
        false,
    );
    assert.equal(sameProductIdentity({ name: 'thinkpad x1' }, { name: 'thinkpad x1' }), true);
    assert.equal(sameProductIdentity({ name: 'thinkpad x1' }, { name: 'thinkpad x13' }), false);
});

test('no comparable evidence is not a verdict', () => {
    // The caller must fall back rather than treat silence as agreement.
    assert.equal(sameProductIdentity(null, { sku: 'A' }), null);
    assert.equal(sameProductIdentity({ sku: 'A' }, null), null);
    assert.equal(sameProductIdentity({}, {}), null);
    assert.equal(sameProductIdentity({ sku: 'A' }, { canonical: '/p/one' }), null);
});

test('the reader takes its evidence off a real page', async (t) => {
    let browser;

    for (const channel of ['msedge', 'chrome', null]) {
        try {
            browser = await chromium.launch({ headless: true, ...(channel ? { channel } : {}) });
            break;
        } catch {
            // Try the next locally available Chromium.
        }
    }

    if (!browser) {
        t.skip('No Chromium available on this machine.');

        return;
    }

    try {
        const page = await browser.newPage();
        await page.setContent(`
            <html><head>
                <link rel="canonical" href="https://shop.example/p/laptop-9/">
                <meta property="og:url" content="https://shop.example/p/laptop-9">
                <script type="application/ld+json">
                    {"@graph":[{"@type":"BreadcrumbList"},{"@type":"Product","sku":"SKU-9","name":"ThinkPad X1"}]}
                </script>
            </head><body></body></html>
        `);

        const identity = await page.evaluate(readProductIdentityInPage);

        assert.equal(identity.sku, 'SKU-9', 'A Product nested in @graph is still the product.');
        assert.equal(identity.name, 'thinkpad x1');
        assert.equal(identity.canonical, '/p/laptop-9', 'Compared as a path, so host and trailing slash cannot split a match.');
        assert.equal(identity.og_url, '/p/laptop-9');

        // A gallery tab of the same product, as shops actually build one.
        await page.setContent(`
            <html><head>
                <link rel="canonical" href="https://shop.example/p/laptop-9">
                <script type="application/ld+json">{"@type":"Product","mpn":"SKU-9"}</script>
            </head><body></body></html>
        `);

        assert.equal(sameProductIdentity(identity, await page.evaluate(readProductIdentityInPage)), true);

        // A page that publishes nothing gives no verdict rather than a wrong one.
        await page.setContent('<html><head></head><body>nothing here</body></html>');
        assert.equal(sameProductIdentity(identity, await page.evaluate(readProductIdentityInPage)), null);
    } finally {
        await browser.close();
    }
});
