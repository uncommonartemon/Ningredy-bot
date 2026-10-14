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
const baseRecipe = {
    gallery_present: true, content_confirmed_product: true, frame_selectors: [],
    active_image_selector: '#active', position_selector: '#position', gallery_scope_selector: '#media',
    actions: [], collect_selectors: ['#media img'], attributes: ['src', 'data-full'],
    pre_click_selectors: [], open_selectors: [], thumbnail_selectors: [], next_selectors: [],
    exclude_selectors: [], max_thumbnail_clicks: 0, max_next_clicks: 0,
};
const action = (kind, selector, iteration_mode) => ({
    kind, selector, index: 0, limit: 1, wait_after_ms: 100, when: 'always',
    purpose: 'product media', frame_selectors: [],
    ...(iteration_mode ? { iteration_mode } : {}),
});
const traversalRecipe = (selector, iteration_mode) => ({
    ...baseRecipe,
    actions: [action('click', '#zoom'), {
        ...action('click_each', selector, iteration_mode),
        after_each_selector: '#zoom', after_each_limit: 1, after_each_wait_after_ms: 100,
    }],
});

// Only Node and the production browser CLI. No PHP bootstrap or application DB.
const run = async (recipe, mode = 'static', count = 3) => {
    const work = await mkdtemp(join(tmpdir(), 'ningredy-recipe-contract-'));
    try {
        const { stdout } = await exec(process.execPath, ['--import', preload, script, 'https://8.8.8.8/product', '10'], {
            cwd: work, timeout: 80000, maxBuffer: 5_000_000,
            env: { ...process.env, FIXTURE_GALLERY_MODE: mode, FIXTURE_GALLERY_COUNT: String(count),
                PRODUCT_GALLERY_RECIPE: JSON.stringify(recipe), PRODUCT_IMAGE_BROWSER_PROFILE: 'false',
                PRODUCT_IMAGE_SHARED_BROWSER: 'false', PRODUCT_IMAGE_BROWSER_HEADLESS: 'true',
                PRODUCT_GALLERY_SCOUT_ONLY: '0', PRODUCT_GALLERY_DEADLINE_MS: '60000',
                PRODUCT_GALLERY_DOM_WAIT_MS: '1000', PRODUCT_GALLERY_MINIMUM_WIDTH: '700',
                PRODUCT_GALLERY_MINIMUM_HEIGHT: '0', PRODUCT_GALLERY_TRANSFER_DIR: '' },
        });
        return JSON.parse(stdout);
    } finally {
        const resolved = await realpath(work);
        if (dirname(resolved).toLowerCase() === (await realpath(tmpdir())).toLowerCase()
            && basename(resolved).startsWith('ningredy-recipe-contract-')) {
            await rm(resolved, { recursive: true, force: true, maxRetries: 5, retryDelay: 100 });
        }
    }
};

test('CLI counts duplicate thumbnail rails as five photographs rather than ten', { timeout: 90000 }, async () => {
    const result = await run({ ...baseRecipe, collect_selectors: ['#media > img'] }, 'static-duplicate-thumbnails', 5);
    assert.equal(result.images.length, 5);
    assert.equal(result.diagnostics.gallery_readiness.thumbnail_count, 5);
    assert.equal(result.diagnostics.observed_gallery_count, 5);
    assert.equal(result.diagnostics.partial, false);
});

test('CLI opens a declared canvas gallery without allowing purchase controls', { timeout: 90000 }, async () => {
    const blocked = await run({ ...baseRecipe, actions: [action('click', '#addToCart')] }, 'canvas-fullscreen');
    assert.equal(blocked.images.length, 0);
    assert.ok(blocked.action_trace.some(entry => entry.unsafe_control === true && entry.control_safety.forbidden === true));
    const opened = await run({ ...baseRecipe, actions: [action('click', '#mixedmedia_fullScreenButton')] }, 'canvas-fullscreen');
    assert.equal(opened.images.length, 3);
    assert.ok(opened.action_trace.some(entry => entry.selector === '#mixedmedia_fullScreenButton' && entry.clicked === true));
});

for (const [mode, explicitCount] of [
    ['static-counter-specs', 0], ['static-counter-invalid-ordinal', 0], ['static-counter-zero', 0],
    ['static-counter-unit', 0], ['static-counter-unicode-unit', 0],
    ['static-counter-slash', 8], ['static-counter-of', 8],
]) test('CLI gallery counter distinguishes image ordinals from product specifications: ' + mode, { timeout: 90000 }, async () => {
    const result = await run(baseRecipe, mode, 8);
    const readiness = result.scout.gallery_readiness;
    assert.equal(result.images.length, 8);
    assert.equal(readiness.explicit_image_count, explicitCount, JSON.stringify(readiness));
    assert.equal(readiness.observed_count, 8);
    assert.equal(readiness.target_count, 8);
    assert.equal(result.diagnostics.partial, false);
});

for (const exclude of ['#media', '#media img']) test('CLI never restores excluded photos: ' + exclude, { timeout: 90000 }, async () => {
    const result = await run({ ...baseRecipe, exclude_selectors: [exclude] });
    assert.deepEqual(result.images, []);
    assert.equal(result.diagnostics.raw_dom_candidates, 0);
    assert.deepEqual(result.diagnostics.validated_image_evidence, []);
});

test('CLI nth exclusion removes only the requested descendant', { timeout: 90000 }, async () => {
    const result = await run({ ...baseRecipe, exclude_selectors: ['#media img >> nth=1'] });
    assert.deepEqual(result.images.sort(), ['https://8.8.8.8/1600x1200/0.jpg', 'https://8.8.8.8/1600x1200/2.jpg']);
});

test('CLI collects the exact nth match, without treating a valid address as no photos', { timeout: 90000 }, async () => {
    const result = await run({ ...baseRecipe, collect_selectors: ['#media img >> nth=1'] });
    assert.deepEqual(result.images, ['https://8.8.8.8/1600x1200/1.jpg']);
    assert.deepEqual(result.diagnostics.selector_errors, []);
});

for (const key of ['collect_selectors', 'exclude_selectors']) test('CLI diagnoses malformed ' + key, { timeout: 90000 }, async () => {
    const result = await run({ ...baseRecipe, [key]: ['[broken='] });
    assert.deepEqual(result.images, []);
    assert.equal(result.diagnostics.partial, true);
    assert.equal(result.diagnostics.interruption_reason, 'invalid_recipe_selector');
    assert.ok(result.diagnostics.selector_errors.some((error) => error.selector === '[broken='));
});

test('CLI element traversal transfers unchanged between 1, 7 and 1 thumbnails', { timeout: 240000 }, async () => {
    const recipe = traversalRecipe('#strip .item', 'elements');
    for (const count of [1, 7, 1]) {
        const result = await run(recipe, 'growing', count);
        assert.equal(result.images.length, count);
        assert.equal(result.diagnostics.action_plan.complete, true, JSON.stringify(result.action_trace));
        assert.equal(result.diagnostics.partial, false);
        const clicks = result.action_trace.filter((item) => item.action_index === 1 && !item.after_each && item.clicked);
        assert.equal(clicks.length, count);
        assert.equal(clicks.at(-1).traversal_stop_reason, 'all_current_controls_visited');
    }
});

test('CLI scroll_each transfers unchanged between 4, 9 and 4 lazy frames', { timeout: 240000 }, async () => {
    const recipe = { ...baseRecipe, active_image_selector: '', position_selector: '',
        actions: [action('scroll_each', '#media .lazy-frame', 'elements')] };
    for (const count of [4, 9, 4]) {
        const result = await run(recipe, 'lazy-scroll', count);
        assert.equal(result.images.length, count, JSON.stringify(result.diagnostics));
        assert.equal(result.diagnostics.action_plan.complete, true, JSON.stringify(result.action_trace));
        assert.equal(result.diagnostics.partial, false);
        const steps = result.action_trace.filter((item) => item.action === 'scroll_each' && item.clicked);
        assert.deepEqual(steps.map((item) => item.index), Array.from({ length: count }, (_, i) => i));
        assert.equal(steps.at(-1).traversal_stop_reason, 'all_current_controls_visited');
        assert.ok(result.images.every((url) => url.includes('/1600x1200/')));
    }
});

test('CLI scroll_each follows newly appended elements without clicking them', { timeout: 90000 }, async () => {
    const result = await run({ ...baseRecipe, active_image_selector: '', position_selector: '',
        actions: [action('scroll_each', '#media .lazy-frame', 'elements')] }, 'lazy-scroll-growing', 7);
    assert.equal(result.images.length, 7, JSON.stringify(result.diagnostics));
    assert.equal(result.diagnostics.action_plan.complete, true, JSON.stringify(result.action_trace));
    assert.equal(result.diagnostics.partial, false);
    const steps = result.action_trace.filter((item) => item.action === 'scroll_each' && item.clicked);
    assert.deepEqual(steps.map((item) => item.index), [0, 1, 2, 3, 4, 5, 6]);
    assert.equal(steps.at(-1).traversal_match_count, 7);
});

test('CLI scroll_each keeps other frames when one original cannot load', { timeout: 90000 }, async () => {
    const result = await run({ ...baseRecipe, active_image_selector: '', position_selector: '',
        actions: [action('scroll_each', '#media .lazy-frame', 'elements')] }, 'lazy-scroll-broken', 4);
    assert.equal(result.images.length, 3, JSON.stringify(result.diagnostics));
    assert.equal(result.diagnostics.partial, true);
    assert.equal(result.diagnostics.interruption_reason, 'image_load_failed');
    assert.ok(result.images.some((url) => url.endsWith('/3.jpg')), 'Later frames must still be collected');
});

test('CLI scroll_each clicks its after_each zoom to obtain every original', { timeout: 90000 }, async () => {
    const result = await run({ ...baseRecipe, active_image_selector: '', position_selector: '',
        actions: [{ ...action('scroll_each', '#media .lazy-frame', 'elements'),
            after_each_selector: '#zoom', after_each_limit: 1, after_each_wait_after_ms: 100 }],
    }, 'lazy-scroll-zoom', 3);
    assert.equal(result.images.length, 3, JSON.stringify(result.diagnostics));
    assert.equal(result.diagnostics.action_plan.complete, true, JSON.stringify(result.action_trace));
    assert.equal(result.diagnostics.partial, false);
    assert.equal(result.action_trace.filter((item) => item.after_each && item.clicked).length, 3);
});

test('CLI scroll_each waits for the visited native-lazy image, not future offscreen frames', { timeout: 90000 }, async () => {
    const result = await run({ ...baseRecipe, active_image_selector: '', position_selector: '',
        actions: [action('scroll_each', '#media .lazy-frame', 'elements')] }, 'lazy-scroll-native', 9);
    assert.equal(result.images.length, 9, JSON.stringify(result.diagnostics));
    assert.equal(result.diagnostics.partial, false, JSON.stringify(result.action_trace));
    assert.equal(result.diagnostics.action_plan.complete, true);
    assert.equal(result.action_trace.filter((item) => item.action === 'scroll_each' && item.clicked).length, 9);
    assert.ok(result.action_trace.every((item) => item.media_status !== 'media_wait_timeout'));
});

test('CLI scroll_each diagnoses a shrinking selector list without claiming completion', { timeout: 90000 }, async () => {
    const result = await run({ ...baseRecipe, active_image_selector: '', position_selector: '',
        actions: [action('scroll_each', '#media .lazy-frame', 'elements')] }, 'lazy-scroll-shrinking', 9);
    assert.equal(result.diagnostics.action_plan.complete, false);
    assert.ok(result.action_trace.some((item) => item.traversal_stop_reason === 'traversal_elements_changed'));
    assert.ok(result.images.length > 0, 'Already collected frames survive the incomplete traversal');
});

test('CLI repeat arrow keeps its selected index among several controls and supports nth selectors', { timeout: 90000 }, async () => {
    const result = await run({ ...traversalRecipe('#media button', 'repeat'),
        active_image_selector: '#active >> nth=0', position_selector: '#position >> nth=0',
        gallery_scope_selector: '#media >> nth=0' }, 'slider', 3);
    assert.equal(result.images.length, 3);
    assert.equal(result.diagnostics.action_plan.complete, true, JSON.stringify(result.action_trace));
    assert.equal(result.diagnostics.partial, false);
    assert.ok(result.action_trace.some((item) => item.traversal_stop_reason === 'returned_to_first_frame'));
    assert.ok(result.action_trace.filter((item) => item.action_index === 1 && !item.after_each)
        .every((item) => item.index === 0));
});

test('CLI legacy arrow without iteration_mode still repeats', { timeout: 90000 }, async () => {
    const result = await run(traversalRecipe('#go'), 'slider', 3);
    assert.equal(result.images.length, 3);
    assert.equal(result.diagnostics.action_plan.complete, true);
});

for (const [mode, expectedImages, failures] of [['static-blocked', 0, 3], ['static-partial', 2, 1]]) {
    test(`CLI keeps static gallery load failures distinct from recipe mismatch: ${mode}`, { timeout: 90000 }, async () => {
        const result = await run(baseRecipe, mode);
        assert.equal(result.images.length, expectedImages);
        assert.equal(result.diagnostics.distinct_dom_assets, 3);
        assert.equal(result.diagnostics.partial, true);
        assert.equal(result.diagnostics.interruption_reason, 'image_load_failed');
        const rejected = result.diagnostics.rejected_candidates.filter((item) => item.reason === 'image_load_failed');
        assert.equal(rejected.length, failures);
        assert.ok(rejected.every((item) => item.http_status === 403 && item.content_type.includes('text/html')));
        assert.equal(result.diagnostics.selector_errors.length, 0);
    });
}

test('CLI does not interrupt when another rendition of the same photo loads', { timeout: 90000 }, async () => {
    const result = await run(baseRecipe, 'static-rendition');
    assert.equal(result.images.length, 3);
    assert.equal(result.diagnostics.partial, false);
    assert.equal(result.diagnostics.interruption_reason, null);
    assert.ok(result.diagnostics.rejected_candidates.some((item) => item.http_status === 403));
});

test('CLI keeps genuinely small decoded photographs separate from load failures', { timeout: 90000 }, async () => {
    const result = await run(baseRecipe, 'static-small');
    assert.equal(result.images.length, 0);
    assert.equal(result.diagnostics.partial, false);
    assert.ok(result.diagnostics.rejected_candidates.every((item) => item.reason === 'dimensions_below_minimum'));
});

test('CLI preserves interruption when only a tiny version of the failed original decoded', { timeout: 90000 }, async () => {
    const result = await run(baseRecipe, 'static-small-failed-original');
    assert.equal(result.images.length, 0);
    assert.equal(result.diagnostics.partial, true);
    assert.equal(result.diagnostics.interruption_reason, 'image_load_failed');
    assert.ok(result.diagnostics.rejected_candidates.some((item) => item.reason === 'dimensions_below_minimum'));
    assert.ok(result.diagnostics.rejected_candidates.some((item) => item.reason === 'image_load_failed'));
});
