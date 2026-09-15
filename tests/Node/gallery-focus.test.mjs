import { before, after, test } from 'node:test';
import assert from 'node:assert/strict';
import { chromium } from 'playwright-core';
import { captureGalleryScoutInPage } from '../../scripts/gallery-focus.mjs';
import { EXCLUDED_GALLERY_CONTEXT_PATTERN_SOURCE } from '../../scripts/product-gallery-utils.mjs';

let browser;
before(async () => { browser = await chromium.launch({ headless: true }); });
after(async () => { await browser?.close(); });
const args = { excludedContextPatternSource: EXCLUDED_GALLERY_CONTEXT_PATTERN_SOURCE };
const pixel = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=';

test('focused screenshot clip matches the actual selected area after scrolling', async () => fixture(async (page) => {
    await page.locator('#x7').evaluate((node) => { node.style.marginTop = '1600px'; node.style.background = 'red'; });
    await page.locator('#x7').scrollIntoViewIfNeeded();
    const scoped = await capture(page);
    const clip = scoped.observation_focus.screenshot_clip;
    assert.ok(clip);
    const actual = await page.screenshot({ clip });
    const expected = await page.locator('#x7').screenshot();
    assert.deepEqual(actual, expected);
}));

async function fixture(fn) {
    const page = await browser.newPage();
    try {
        await page.setContent(`<style>img{width:100px;height:80px} #x7{width:320px;height:220px}
            button{width:100px;height:30px}</style>
            <div id="x7"><img src="${pixel}"><button id="next">Advance</button></div>
            <div id="other" class="slider"><img src="${pixel}"><button id="foreign">Different set</button></div>
            <footer><button id="footer">Contact</button></footer>`);
        await page.evaluate(captureGalleryScoutInPage, args);
        await fn(page);
    } finally { await page.close(); }
}
const capture = (page, focusSelector = '#x7', extra = {}) =>
    page.evaluate(captureGalleryScoutInPage, { ...args, focusSelector, ...extra });

test('arbitrarily named container is discoverable and focus excludes unrelated sliders/footer', async () => fixture(async (page) => {
    const wide = await capture(page, '');
    assert.ok(wide.container_candidates.some((c) => c.selector === '#x7'));
    assert.ok(wide.action_candidates.some((c) => c.selector === '#footer'));
    const scoped = await capture(page);
    assert.equal(scoped.observation_focus.mode, 'focused');
    assert.deepEqual(scoped.action_candidates.map((c) => c.selector), ['#next']);
    assert.equal(scoped.image_candidates.length, 1);
    assert.ok(scoped.observation_focus.screenshot_clip);
    assert.ok(JSON.stringify(scoped).length < JSON.stringify(wide).length);
}));

test('external dialog and its controls/images remain visible without trusting them as gallery', async () => fixture(async (page) => {
    await page.evaluate((src) => {
        const dialog = document.createElement('dialog');
        dialog.innerHTML = '<img src="' + src + '"><button id="enlarge">Enlarge</button>';
        document.body.append(dialog);
        dialog.showModal();
    }, pixel);
    const scoped = await capture(page);
    assert.ok(scoped.visible_overlays.length);
    assert.ok(scoped.action_candidates.some((c) => c.selector === '#enlarge'));
    assert.equal(scoped.observation_focus.screenshot_clip, null);
    assert.ok(scoped.observation_focus.anomalies.includes('overlay_visible'));
}));

test('plain portal viewer is observed across subsequent captures until it closes', async () => fixture(async (page) => {
    await page.evaluate((src) => {
        const node = document.createElement('div');
        node.id = 'plain';
        node.innerHTML = '<img src="' + src + '"><button id="portal-next">Advance</button>';
        document.body.append(node);
    }, pixel);
    const first = await capture(page);
    assert.ok(first.outside_changed_regions.length);
    assert.ok(first.action_candidates.some((c) => c.selector === '#portal-next'));
    const second = await capture(page);
    assert.ok(second.action_candidates.some((c) => c.selector === '#portal-next'));
    await page.locator('#plain').evaluate((node) => node.remove());
    assert.ok(!(await capture(page)).action_candidates.some((c) => c.selector === '#portal-next'));
}));

test('unlabelled fixed cookie wall is visible outside focus and disables crop', async () => fixture(async (page) => {
    await page.evaluate(() => {
        const wall = document.createElement('div');
        wall.style.cssText = 'position:fixed;inset:0;background:white;z-index:100';
        wall.innerHTML = '<button id="accept">Accept cookies</button>';
        document.body.append(wall);
    });
    const scoped = await capture(page);
    assert.ok(scoped.visible_overlays.some((html) => html.includes('Accept cookies')));
    assert.ok(scoped.action_candidates.some((c) => c.selector === '#accept'));
    assert.equal(scoped.observation_focus.screenshot_clip, null);
}));

for (const [selector, reason] of [['#missing', 'container_missing'], ['div', 'ambiguous_container'], ['[', 'invalid_focus_selector']]) {
    test(reason + ' restores broad observation rather than rejecting the page', async () => fixture(async (page) => {
        const scoped = await capture(page, selector);
        assert.equal(scoped.observation_focus.mode, 'page');
        assert.equal(scoped.observation_focus.reason, reason);
        assert.ok(scoped.action_candidates.some((c) => c.selector === '#footer'));
    }));
}
test('hidden focus and navigation restore broad observation', async () => fixture(async (page) => {
    await page.locator('#x7').evaluate((node) => { node.style.display = 'none'; });
    assert.equal((await capture(page)).observation_focus.reason, 'container_hidden');
    await page.locator('#x7').evaluate((node) => { node.style.display = ''; });
    assert.equal((await capture(page, '#x7', { initialUrl: 'https://shop.example/product' })).observation_focus.reason, 'page_navigated');
}));
test('indexed focus resolves the selected element only', async () => fixture(async (page) => {
    const scoped = await capture(page, 'div >> nth=1');
    assert.equal(scoped.observation_focus.mode, 'focused');
    assert.deepEqual(scoped.action_candidates.map((c) => c.selector), ['#foreign']);
}));
