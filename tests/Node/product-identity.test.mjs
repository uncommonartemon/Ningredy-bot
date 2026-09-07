import { test } from 'node:test';
import assert from 'node:assert/strict';
import { chromium } from 'playwright-core';
import { readProductIdentityInPage, sameProductIdentity } from '../../scripts/product-gallery-utils.mjs';

/**
 * The check that replaced a list of English tab names. Its first version went
 * in on my word that it worked, and a review found three ways it said "same
 * product" without evidence. Each one is a test here now.
 */
test('an identifier both pages carry settles it, either way', () => {
    assert.equal(
        sameProductIdentity(
            { identifiers: { sku: 'nx-jnqek-003' } },
            { identifiers: { sku: 'nx-jnqek-003' } },
        ),
        true,
    );
    // The case a path rule cannot see: one segment apart, different laptops.
    assert.equal(
        sameProductIdentity(
            { identifiers: { sku: 'model-a' }, canonical: '/store/laptops/model-a' },
            { identifiers: { sku: 'model-b' }, canonical: '/store/laptops/model-b' },
        ),
        false,
    );
});

test('identifiers are compared within their own kind', () => {
    // sku, mpn and gtin are different namespaces. A value found under one says
    // nothing about a value found under another, and treating them as one field
    // manufactures both matches and mismatches.
    assert.equal(
        sameProductIdentity({ identifiers: { sku: 'x1' } }, { identifiers: { mpn: 'x1' } }),
        null,
        'Nothing comparable was published, so there is no verdict.',
    );
    assert.equal(
        sameProductIdentity(
            { identifiers: { sku: 'a', mpn: 'shared' } },
            { identifiers: { mpn: 'shared' } },
        ),
        true,
    );
});

test('a shared identifier cannot hide a conflicting identifier regardless of field order', () => {
    for (const identifiers of [
        { mpn: 'shared-family', sku: 'sku-a' },
        { sku: 'sku-a', mpn: 'shared-family' },
    ]) {
        assert.equal(sameProductIdentity(
            { identifiers },
            { identifiers: { mpn: 'shared-family', sku: 'sku-b' } },
        ), false);
    }
});

test('canonical evidence preserves product queries, host and path case', async (t) => {
    const browser = await launch();
    if (!browser) {
        t.skip('No Chromium available on this machine.');
        return;
    }
    try {
        const page = await browser.newPage();
        const read = async (url) => {
            await page.setContent('<html><head></head><body></body></html>');
            await page.evaluate((href) => {
                const link = document.createElement('link');
                link.rel = 'canonical';
                link.href = href;
                document.head.appendChild(link);
            }, url);
            return await page.evaluate(readProductIdentityInPage);
        };
        const first = await read('https://shop.example/Product?id=A');
        for (const url of [
            'https://shop.example/Product?id=B',
            'https://other.example/Product?id=A',
            'https://shop.example/product?id=A',
        ]) {
            assert.equal(sameProductIdentity(first, await read(url)), false, url);
        }
        assert.equal(sameProductIdentity(first, await read('https://shop.example/Product/?id=A#gallery')), true);
    } finally {
        await browser.close();
    }
});

test('an equal name is not proof, an unequal one is', () => {
    // Every configuration of a laptop shares its name, so equality proves
    // nothing - the previous version accepted it and a test locked that in.
    assert.equal(sameProductIdentity({ name: 'thinkpad x1' }, { name: 'thinkpad x1' }), null);
    assert.equal(sameProductIdentity({ name: 'thinkpad x1' }, { name: 'thinkpad x13' }), false);
});

test('no comparable evidence is not a verdict', () => {
    assert.equal(sameProductIdentity(null, { identifiers: { sku: 'a' } }), null);
    assert.equal(sameProductIdentity({ identifiers: { sku: 'a' } }, null), null);
    assert.equal(sameProductIdentity({}, {}), null);
    assert.equal(sameProductIdentity({ identifiers: { sku: 'a' } }, { canonical: '/p/one' }), null);
});

test('what a page does not publish stays absent', async (t) => {
    const browser = await launch();

    if (!browser) {
        t.skip('No Chromium available on this machine.');

        return;
    }

    try {
        const page = await browser.newPage();

        // Two different products on a shop that publishes no metadata at all.
        // An earlier version resolved the missing canonical through
        // new URL('', location.href) and reported each page's own path, so
        // these two matched. Absence has to survive as absence.
        await page.goto('https://example.com/product?id=A').catch(() => {});
        await page.setContent('<html><head><title>A</title></head><body></body></html>');
        const first = await page.evaluate(readProductIdentityInPage);

        assert.equal(first.canonical, null);
        assert.equal(first.og_url, null);
        assert.deepEqual(first.identifiers, {});

        await page.setContent('<html><head><title>B</title></head><body></body></html>');

        assert.equal(
            sameProductIdentity(first, await page.evaluate(readProductIdentityInPage)),
            null,
            'Two pages that published nothing are not thereby the same product.',
        );
    } finally {
        await browser.close();
    }
});

test('the reader takes its evidence off a real page', async (t) => {
    const browser = await launch();

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
                    {"@graph":[{"@type":"BreadcrumbList"},{"@type":["Product","Thing"],"sku":"SKU-9","mpn":"MPN-9","name":"ThinkPad X1"}]}
                </script>
            </head><body></body></html>
        `);

        const identity = await page.evaluate(readProductIdentityInPage);

        assert.equal(identity.identifiers.sku, 'sku-9', 'A Product nested in @graph is still the product.');
        assert.equal(identity.identifiers.mpn, 'mpn-9', 'Kinds are kept apart.');
        assert.equal(identity.name, 'thinkpad x1');
        assert.equal(identity.canonical, 'https://shop.example/p/laptop-9', 'Preserves the host, normalizes only trailing slashes.');

        // The gallery tab of that same product, publishing less than the page
        // it belongs to - which is normal, and enough.
        await page.setContent(`
            <html><head>
                <link rel="canonical" href="https://shop.example/p/laptop-9">
            </head><body></body></html>
        `);

        assert.equal(sameProductIdentity(identity, await page.evaluate(readProductIdentityInPage)), true);

        // The neighbouring laptop, same template, one segment away.
        await page.setContent(`
            <html><head>
                <link rel="canonical" href="https://shop.example/p/laptop-10">
                <script type="application/ld+json">{"@type":"Product","sku":"SKU-10"}</script>
            </head><body></body></html>
        `);

        assert.equal(sameProductIdentity(identity, await page.evaluate(readProductIdentityInPage)), false);
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
