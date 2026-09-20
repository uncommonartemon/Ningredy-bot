import test from 'node:test';
import assert from 'node:assert/strict';
import { chromium } from 'playwright-core';
import { GalleryTraversalProgress, readTraversalMediaInPage, traversalMediaState, waitForTraversalMedia, embeddedProductConflicts } from '../../scripts/gallery-traversal.mjs';
import { recipeActionPlanStatus } from '../../scripts/product-gallery-utils.mjs';

const state = (id, ready = true) => ({ signature: id, ready, status: ready ? 'ready' : 'media_wait_timeout' });

test('a viewer address/title is not a product conflict, a contradictory SKU is', () => {
    const product = { canonical: 'https://shop.test/product', name: 'Laptop', identifiers: { sku: 'a' } };
    const viewer = { canonical: 'https://media.test/viewer', name: 'Viewer' };
    assert.equal(embeddedProductConflicts(product, viewer), false);
    assert.equal(embeddedProductConflicts(product, { ...viewer, identifiers: { sku: 'b' } }), true);
});

test('known thumbnails do not stop traversal while originals of the same assets arrive', () => {
    const thumbs = Array.from({ length: 7 }, (_, i) => `https://cdn.shopify.com/s/files/a/products/${i}.jpg?width=120`);
    const progress = new GalleryTraversalProgress(state('0'), thumbs);
    for (let i = 1; i < 7; i++) {
        assert.equal(progress.advance(state(String(i)), [...thumbs, thumbs[i].replace('120', '1600')]).stop, false);
    }
    assert.deepEqual(progress.advance(state('0'), thumbs), { stop: true, complete: true, reason: 'returned_to_first_frame' });
});

test('unchanged frames, loading and new renditions are not fabricated end-of-gallery', () => {
    const progress = new GalleryTraversalProgress(state('A'), []);
    for (let i = 0; i < 5; i++) assert.equal(progress.advance(state('A'), [`rendition-${i}`]).stop, false);
    assert.equal(progress.advance(state('B', false), []).complete, false);
    assert.equal(new GalleryTraversalProgress(state('A')).advance(state('A'), [], { disabled: true }).complete, true);
    const stuck = new GalleryTraversalProgress(state('A'));
    stuck.advance(state('A'), []); stuck.advance(state('A'), []);
    assert.deepEqual(stuck.advance(state('A'), []), { stop: true, complete: false, reason: 'no_observable_progress' });
});

test('new explicit incomplete trace overrides the old enough-clicks and unchanged rules', () => {
    const actions = [{ kind: 'click_each', selector: '#next', index: 0, limit: 1 }];
    const trace = [{ action: 'click_each', action_index: 0, clicked: true, changed: false,
        selector_match_count: 1, traversal_complete: false, traversal_stop_reason: 'media_wait_timeout' }];
    const result = recipeActionPlanStatus({ actions, actionTrace: trace });
    assert.equal(result.complete, false);
});

test('real circular gallery waits for delayed originals and is reusable on 3 and 7 frames', async () => {
    const browser = await chromium.launch({ headless: true });
    try {
        for (const count of [3, 7]) {
            const page = await browser.newPage();
            const svg = '<svg xmlns="http://www.w3.org/2000/svg" width="1600" height="1000"><rect width="1600" height="1000" fill="red"/></svg>';
            await page.route('https://gallery.test/**', async (route) => {
                await new Promise((resolve) => setTimeout(resolve, 220));
                await route.fulfill({ contentType: 'image/svg+xml', body: svg });
            });
            await page.setContent(`<img id="active" src="https://gallery.test/0.svg"><span id="pos">0</span>
                <button id="next">Next</button><button id="zoom">Zoom</button>
                <script>let n=0; next.onclick=()=>{n=(n+1)%${count}; active.src='https://gallery.test/'+n+'.svg';pos.textContent=n};
                zoom.onclick=()=>{active.src='https://gallery.test/'+n+'.svg?original=1'};</script>`);
            const read = () => page.evaluate(readTraversalMediaInPage, { selectors: ['#active'], positionSelector: '#pos' });
            const settle = () => waitForTraversalMedia(read, { deadline: Date.now() + 2500, settleMs: 25 });
            await page.locator('#zoom').click();
            const first = await settle();
            assert.equal(first.status, 'ready');
            const urls = [(await read()).images[0].url];
            const progress = new GalleryTraversalProgress(first, urls);
            let outcome;
            for (let i = 0; i < count + 2; i++) {
                await page.locator('#next').click();
                await page.locator('#zoom').click();
                const ready = await settle();
                assert.equal(ready.status, 'ready');
                urls.push((await read()).images[0].url);
                outcome = progress.advance(ready, urls);
                if (outcome.stop) break;
            }
            assert.equal(outcome.complete, true);
            assert.equal(new Set(urls).size, count);
            assert.ok(urls.every((url) => url.includes('original=1')));
            await page.close();
        }
    } finally { await browser.close(); }
});

test('a stalled image produces an explicit timeout, never completion', async () => {
    const result = await waitForTraversalMedia(async () => ({ images: [{ url: 'x', ready: false }], busy: true }),
        { deadline: Date.now() + 60, pollMs: 10 });
    assert.equal(result.status, 'media_wait_timeout');
    assert.equal(result.ready, false);
});
