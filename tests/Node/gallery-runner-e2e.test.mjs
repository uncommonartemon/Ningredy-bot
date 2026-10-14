import test from 'node:test';
import assert from 'node:assert/strict';
import { execFile } from 'node:child_process';
import { promisify } from 'node:util';
import { mkdtemp, realpath, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join, dirname, basename } from 'node:path';
import { fileURLToPath } from 'node:url';
const exec = promisify(execFile);
const script = fileURLToPath(new URL('../../scripts/extract-product-gallery.mjs', import.meta.url));
const preload = new URL('./fixtures/gallery-runner-preload.mjs', import.meta.url).href;

for (const [mode, count] of [['shadow-consent', 3], ['blocked-overlay', 3], ['slider', 3], ['slider', 7], ['frame', 7], ['static', 7], ['static-digest', 4], ['static-digest', 7], ['finite', 7], ['growing', 7], ['broken', 7], ['nested', 3], ['observe', 3], ['opened-frame', 7], ['relative-frame', 7], ['tab-title', 7], ['tab-inline-title', 7], ['tab-known-sku', 7], ['tab-other-sku', 7]]) test(`actual CLI: ${mode}, ${count} frames`, { timeout: 90000 }, async () => {
    const work = await mkdtemp(join(tmpdir(), 'ningredy-gallery-e2e-'));
    const frame_selectors = mode === 'nested' ? ['#viewer', '#inner'] : ['frame', 'opened-frame', 'relative-frame'].includes(mode) ? ['#viewer'] : [];
    const readOnly = ['static', 'static-digest', 'relative-frame'].includes(mode);
    const action = (kind, selector) => ({ kind, selector, index: 0, limit: 1, wait_after_ms: 100,
        when: 'always', purpose: 'product media', frame_selectors });
    const recipe = { gallery_present: true, content_confirmed_product: true, frame_selectors,
        active_image_selector: '#active', position_selector: '#position', gallery_scope_selector: '#media',
        actions: readOnly ? [] : [...(mode.startsWith('tab-') ? [action('click', '#gallery-tab')] : []), ...(mode === 'opened-frame' ? [{ ...action('click', '#launch'), frame_selectors: [] }] : []),
            ...(mode === 'observe' ? [action('scroll_into_view', '#active'), action('hover', '#active')] : []), action('click', '#zoom'),
            { ...action('click_each', mode === 'growing' ? '#strip .item' : '#go'), after_each_selector: '#zoom', after_each_limit: 1, after_each_wait_after_ms: 100 }],
        collect_selectors: ['#media img'], attributes: ['src', 'data-full'], pre_click_selectors: [], open_selectors: [],
        thumbnail_selectors: [], next_selectors: [], exclude_selectors: [], max_thumbnail_clicks: 0, max_next_clicks: 0 };
    try {
        const sourceUrl = mode.startsWith('tab-') ? 'https://8.8.8.8/shop/model/specification' : 'https://8.8.8.8/product';
        const { stdout } = await exec(process.execPath, ['--import', preload, script, sourceUrl, '10'], {
            cwd: work, timeout: 80000, maxBuffer: 5_000_000,
            env: { ...process.env, FIXTURE_GALLERY_MODE: mode, FIXTURE_GALLERY_COUNT: String(count), PRODUCT_GALLERY_RECIPE: JSON.stringify(recipe),
                PRODUCT_IMAGE_BROWSER_PROFILE: 'false', PRODUCT_IMAGE_SHARED_BROWSER: 'false', PRODUCT_IMAGE_BROWSER_HEADLESS: 'true',
                PRODUCT_GALLERY_SCOUT_ONLY: '0', PRODUCT_GALLERY_DEADLINE_MS: '60000', PRODUCT_GALLERY_DOM_WAIT_MS: '1000',
                PRODUCT_GALLERY_MINIMUM_WIDTH: '700', PRODUCT_GALLERY_MINIMUM_HEIGHT: '0', PRODUCT_GALLERY_TRANSFER_DIR: '' },
        });
        const result = JSON.parse(stdout);
        if (mode === 'blocked-overlay') {
            assert.ok(result.action_trace.some(item => item.clicked === false && item.interaction_error?.includes('intercepts pointer events')));
            assert.ok(!result.action_trace.some(item => item.clicked === true), 'A forced click on the covering banner must never be reported as a gallery click.');
            assert.equal(result.diagnostics.action_plan.complete, false);
            return;
        }
        if (mode === 'tab-other-sku') {
            assert.equal(result.images.length, 0, 'A real conflicting SKU must still prevent collecting the gallery.');
            assert.ok(result.action_trace.some((item) => item.navigated_away === true));
            return;
        }
        if (mode.startsWith('tab-')) {
            assert.equal(result.diagnostics.identity_unconfirmed, ['tab-title', 'tab-inline-title'].includes(mode));
            assert.ok(!result.action_trace.some((item) => item.navigated_away === true));
        }
        if (mode === 'broken') {
            assert.equal(result.diagnostics.partial, true);
            assert.equal(result.diagnostics.interruption_reason, 'image_load_failed');
            assert.equal(result.images.length, count - 1, 'One failed file must not prevent collecting later frames.');
            assert.ok(result.action_trace.some((item) => item.media_status === 'image_load_failed'));
            return;
        }
        assert.equal(result.images?.length, count, JSON.stringify({ trace: result.action_trace, diagnostics: result.diagnostics }));
        if (mode === 'static-digest') {
            assert.ok(result.images.every((url) => new URL(url).searchParams.get('x') === '1200'));
            assert.equal(new Set(result.images.map(url => new URL(url).pathname)).size, count);
        } else assert.ok(result.images.every((url) => url.includes('1600x1200')));
        if (mode === 'observe') {
            assert.ok(result.action_trace.some((item) => item.action === 'hover' && item.clicked));
            assert.ok(result.action_trace.some((item) => item.action === 'scroll_into_view' && item.clicked));
        }
        if (mode === 'relative-frame') assert.ok(result.images.every((url) => url.includes('/media-assets/1600x1200/')));
        if (readOnly) assert.equal(result.action_trace.filter((item) => item.clicked).length, 0);
        else assert.ok(result.action_trace.some((item) => item.traversal_complete === true), JSON.stringify(result.action_trace));
    } finally {
        const resolved = await realpath(work);
        if (dirname(resolved).toLowerCase() === (await realpath(tmpdir())).toLowerCase()
            && basename(resolved).startsWith('ningredy-gallery-e2e-')) {
            // Windows can retain Chromium handles briefly after child exit.
            // Only this validated test directory is eligible for retry.
            await rm(resolved, { recursive: true, force: true, maxRetries: 5, retryDelay: 100 });
        }
    }
});
