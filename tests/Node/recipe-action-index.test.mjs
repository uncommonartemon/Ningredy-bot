import { test } from 'node:test';
import assert from 'node:assert/strict';
import { chromium } from 'playwright-core';
import {
    resolveRecipeActionTargetIndex,
    recipeActionRequestedIndex,
    normalizeRecipeActions,
} from '../../scripts/product-gallery-utils.mjs';

/**
 * Real production case, 2026-09-14 (techbuy.com.au): a recipe action needed
 * a broad selector's 46th match. The old contract treated index as a
 * COUNT to clamp (schema max 20, then normalizeRecipeActions() clamping
 * again to 20, then the click loop clamping a third time to
 * currentCount - 1) - three separate places silently retargeting a
 * specific, named element onto a different one, never reporting the
 * mismatch. index is an ADDRESS, not a count: this file proves the fixed
 * contract end to end with a real browser and the real exported functions
 * extract-product-gallery.mjs itself calls (resolveRecipeActionTargetIndex(),
 * normalizeRecipeActions()) - not a hand-copied stand-in, and not only the
 * pure-function boundary the previous round stopped at.
 */

test('resolveRecipeActionTargetIndex returns the requested index unchanged when that many elements exist', () => {
    assert.equal(resolveRecipeActionTargetIndex(46, 50), 46);
    assert.equal(resolveRecipeActionTargetIndex(0, 1), 0);
    assert.equal(resolveRecipeActionTargetIndex(99, 100), 99);
});

test('resolveRecipeActionTargetIndex returns null - never a substitute index - when the element does not exist', () => {
    assert.equal(resolveRecipeActionTargetIndex(46, 10), null);
    assert.equal(resolveRecipeActionTargetIndex(10, 10), null, 'matchCount is a count, so the last valid index is matchCount - 1');
    assert.equal(resolveRecipeActionTargetIndex(-1, 10), null);
    assert.equal(resolveRecipeActionTargetIndex(0, 0), null, 'zero elements means nothing to click, not index 0');
});

/**
 * GPT audit, 2026-09-14: -1, "bad" and 1.5 were not real addresses, but
 * parseInt()-based parsing turned each into one anyway (0, 0 and 1
 * respectively) instead of being rejected - the exact same "repair a
 * value instead of reporting it" shape as the 46-to-20 clamp this whole
 * chain was fixed for, just triggered by malformed input instead of a
 * legitimate-but-large one. None of these are "close enough" to index 0
 * or 1; they are not valid addresses at all.
 */
test('resolveRecipeActionTargetIndex rejects malformed input instead of coercing it into a nearby valid index', () => {
    assert.equal(resolveRecipeActionTargetIndex(-1, 10), null, 'a negative number is not an address, not "clamp to 0"');
    assert.equal(resolveRecipeActionTargetIndex('bad', 10), null, 'unparseable text is not an address, not "default to 0"');
    assert.equal(resolveRecipeActionTargetIndex(1.5, 10), null, 'a fraction is not an address, not "truncate to 1"');
    assert.equal(resolveRecipeActionTargetIndex(Number.NaN, 10), null);
    assert.equal(resolveRecipeActionTargetIndex(undefined, 10), null);
});

test('resolveRecipeActionTargetIndex still accepts a clean digit string, not only a native integer', () => {
    assert.equal(resolveRecipeActionTargetIndex('46', 50), 46);
    assert.equal(resolveRecipeActionTargetIndex(' 46 ', 50), 46);
});

test('normalizeRecipeActions no longer collapses a legitimately high index onto a different element', () => {
    const [action] = normalizeRecipeActions([{
        kind: 'click',
        selector: 'a',
        index: 46,
        limit: 1,
        wait_after_ms: 200,
        purpose: 'Open the 47th link, not the 21st.',
    }]);

    assert.equal(action.index, 46);
});

test('normalizeRecipeActions drops an action with a malformed index instead of repairing it to 0', () => {
    const actions = normalizeRecipeActions([
        { kind: 'click', selector: 'a', index: -1, limit: 1, wait_after_ms: 200, purpose: 'negative' },
        { kind: 'click', selector: 'b', index: 'bad', limit: 1, wait_after_ms: 200, purpose: 'not a number' },
        { kind: 'click', selector: 'c', index: 1.5, limit: 1, wait_after_ms: 200, purpose: 'a fraction' },
        { kind: 'click', selector: 'd', index: 3, limit: 1, wait_after_ms: 200, purpose: 'a real, valid index' },
    ]);

    assert.deepEqual(
        actions.map((action) => action.selector),
        ['d'],
        'only the action with a genuinely valid index should survive - the other three must be dropped, not repaired',
    );
});

/**
 * Regression, 2026-09-14: fixing the single-click index contract above
 * broke repeated presses of a single control (one "next" arrow). click_each
 * unconditionally added repetition to index, so a lone arrow's second press
 * asked for index 1 - which never existed on a page with exactly one
 * match - and the walk ended after a single frame. A real slider needs the
 * SAME element addressed on every press; only an actual strip of several
 * distinct elements should have repetition counted into the address.
 */
test('recipeActionRequestedIndex keeps addressing the same sole control across repetitions', () => {
    const action = { kind: 'click_each', index: 0 };

    for (let repetition = 0; repetition < 6; repetition++) {
        assert.equal(
            recipeActionRequestedIndex(action, repetition, 1),
            0,
            `repetition ${repetition} against a single-match control must still request index 0`,
        );
    }
});

test('recipeActionRequestedIndex still walks a genuine multi-element strip one new element per repetition', () => {
    const action = { kind: 'click_each', index: 0 };

    for (let repetition = 0; repetition < 5; repetition++) {
        assert.equal(recipeActionRequestedIndex(action, repetition, 5), repetition);
    }
});

test('recipeActionRequestedIndex never adds repetition for a plain click', () => {
    const action = { kind: 'click', index: 3 };

    assert.equal(recipeActionRequestedIndex(action, 0, 10), 3);
    assert.equal(recipeActionRequestedIndex(action, 4, 10), 3);
});

test('index 46 passes through the real chain and clicks exactly element 46, not a substitute', async (t) => {
    const browser = await launch();
    if (!browser) {
        t.skip('No Chromium available on this machine.');
        return;
    }

    try {
        const page = await browser.newPage();
        // 50 identical, indistinguishable links - the exact shape that
        // broke it: a broad selector with far more than 20/40 matches, no
        // per-element identifying attribute to fall back on.
        const links = Array.from({ length: 50 }, (_, i) => `<a href="#" data-slot="${i}">Link</a>`).join('');
        await page.setContent(`<div id="gallery">${links}</div>`);

        await page.evaluate(() => {
            document.querySelectorAll('#gallery a').forEach((el) => {
                el.addEventListener('click', () => el.setAttribute('data-was-clicked', '1'));
            });
        });

        const requestedIndex = 46;
        const matchCount = await page.locator('#gallery a').count();
        const targetIndex = resolveRecipeActionTargetIndex(requestedIndex, matchCount);
        assert.equal(targetIndex, 46, 'fixture sanity check: 46 must actually exist among 50 matches');

        await page.locator('#gallery a').nth(targetIndex).click();

        const actuallyClicked = await page.evaluate(() =>
            [...document.querySelectorAll('#gallery a[data-was-clicked]')].map((el) => el.dataset.slot));

        assert.deepEqual(actuallyClicked, ['46'], 'exactly and only element 46 must register the click');
    } finally {
        await browser.close();
    }
});

test('a requested index with no matching element never falls back to clicking the last available one', async (t) => {
    const browser = await launch();
    if (!browser) {
        t.skip('No Chromium available on this machine.');
        return;
    }

    try {
        const page = await browser.newPage();
        const links = Array.from({ length: 5 }, (_, i) => `<a href="#" data-slot="${i}">Link</a>`).join('');
        await page.setContent(`<div id="gallery">${links}</div>`);
        await page.evaluate(() => {
            document.querySelectorAll('#gallery a').forEach((el) => {
                el.addEventListener('click', () => el.setAttribute('data-was-clicked', '1'));
            });
        });

        const matchCount = await page.locator('#gallery a').count();
        assert.equal(matchCount, 5, 'fixture sanity check');

        const targetIndex = resolveRecipeActionTargetIndex(10, matchCount);

        assert.equal(targetIndex, null, 'index 10 does not exist among 5 elements - must not resolve to element 4');

        // The real script's own contract on a null result: no click at
        // all, an action_trace entry instead (index_out_of_range: true,
        // requested_index, selector_match_count) - see
        // extract-product-gallery.mjs's own action loop. Proven here at
        // the level this suite can reach without a publicly reachable
        // fixture server for the CLI script's own SSRF gate: nothing on
        // the real page registers a click when the caller correctly obeys
        // a null resolution.
        if (targetIndex !== null) {
            await page.locator('#gallery a').nth(targetIndex).click();
        }

        const anyClicked = await page.evaluate(() =>
            document.querySelectorAll('#gallery a[data-was-clicked]').length);

        assert.equal(anyClicked, 0, 'no element - least of all a "closest available" substitute - may register a click');
    } finally {
        await browser.close();
    }
});

/**
 * Scope, precisely: this proves the click-resolution step of the action
 * loop - recipeActionRequestedIndex() + resolveRecipeActionTargetIndex(),
 * driven through a loop mirroring the real guard conditions - keeps
 * landing a click on a repeatedly-pressed single control across all of
 * its repetitions, instead of resolving to a nonexistent index after the
 * first (the reported regression). It reads back a plain counter as proof
 * each click actually registered.
 *
 * It does NOT exercise real asset/photo collection, and it does NOT
 * exercise end-of-slider detection (the distinctCollectedAssets()/
 * barren-press patience logic inside extract-product-gallery.mjs's own
 * action loop, which is not exported and out of scope here) - a fixed
 * limit of 5 stands in for whatever a real recipe's limit would be, and
 * this test only checks that all 5 presses land, not that a real slider's
 * natural end is detected correctly.
 */
test('a repeated single control keeps landing its click on every repetition, not only the first', async (t) => {
    const browser = await launch();
    if (!browser) {
        t.skip('No Chromium available on this machine.');
        return;
    }

    try {
        const page = await browser.newPage();
        // One control throughout - the selector always matches exactly
        // one element (the arrow itself) - with a plain counter standing
        // in for whatever state a real click would advance.
        await page.setContent(`
            <button id="next" data-presses="0">Next</button>
        `);
        await page.evaluate(() => {
            document.querySelector('#next').addEventListener('click', (event) => {
                const next = Number.parseInt(event.currentTarget.dataset.presses, 10) + 1;
                event.currentTarget.dataset.presses = String(next);
            });
        });

        const action = { kind: 'click_each', index: 0, limit: 5 };
        const locator = page.locator('#next');
        const pressCountAfterEachClick = [];

        // Mirrors extract-product-gallery.mjs's own action loop shape
        // (currentCount re-read every repetition, the same two guard
        // conditions, the same two real functions for indexing) closely
        // enough that a regression in either function is caught here, not
        // only by calling them directly with hand-picked numbers.
        for (let repetition = 0; repetition < action.limit; repetition++) {
            const currentCount = await locator.count().catch(() => 0);
            if (currentCount < 1) break;
            if (currentCount > 1 && repetition >= currentCount) break;

            const requestedIndex = recipeActionRequestedIndex(action, repetition, currentCount);
            const targetIndex = resolveRecipeActionTargetIndex(requestedIndex, currentCount);
            if (targetIndex === null) break;

            await locator.nth(targetIndex).click();
            pressCountAfterEachClick.push(await page.evaluate(() => document.querySelector('#next').dataset.presses));
        }

        assert.deepEqual(
            pressCountAfterEachClick,
            ['1', '2', '3', '4', '5'],
            'all five repetitions must land a click on the same control, not stop resolving after the first',
        );
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
