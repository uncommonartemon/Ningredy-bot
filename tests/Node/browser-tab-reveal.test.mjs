import { test } from 'node:test';
import assert from 'node:assert/strict';
import { chromium } from 'playwright-core';
import {
    capturePageTextInPage,
    captureObservedControlsInPage,
    revealedCandidatesVisibilityInPage,
    revealedContainerTextInPage,
} from '../../scripts/product-gallery-utils.mjs';

/**
 * Point 3 of the follow-up audit, verified with an actual Chromium process
 * AND the real functions extract-product-gallery.mjs itself imports and
 * calls (scripts/product-gallery-utils.mjs) - not a hand-copied stand-in.
 *
 * Several rounds of review found real bugs here already:
 * - Three tabs sharing one class collapsed into one indistinguishable
 *   selector (fixed: captureObservedControlsInPage() appends Playwright's
 *   own `>> nth=N` when a candidate selector matches more than one element).
 * - The revealed area was truncated away behind a long, unrelated
 *   description (fixed: revealedContainerTextInPage() hoists the specific
 *   revealed container ahead of the rest of the page).
 * - The `>> nth=N` selector that fix #1 produces is Playwright-only syntax -
 *   document.querySelector() inside the page does not understand it and
 *   throws, so fix #2's own lookup silently failed whenever disambiguation
 *   had actually been needed (fixed: Locator.evaluate() hands the
 *   already-resolved element to revealedContainerTextInPage() instead of
 *   re-resolving the selector string inside the page).
 * - A shared wrapping `<section>`/`<article>` (a tabs container holding
 *   every panel, not one panel) was mistaken for the revealed panel itself
 *   (fixed: only `[role="tabpanel"]`/`[role="region"]` count as ancestor
 *   evidence, never a bare sectioning tag).
 * - THE ONE THIS FILE'S TESTS DID NOT YET COVER: with three tabs
 *   "Описание"/"Характеристики"/"Отзывы" in a row, opening the MIDDLE one,
 *   the clicked button's own next sibling in document order is not its
 *   panel - it is the NEXT TAB'S OWN BUTTON, which is visible and
 *   non-empty exactly like a real panel would be, and was accepted as one.
 *   Fixed with revealedCandidatesVisibilityInPage(), a snapshot taken
 *   BEFORE the click: a candidate past the first two (aria-controls /
 *   explicit tabpanel-role ancestor) is now only trusted if it transitioned
 *   from not-visible to visible, never merely because it is visible now - a
 *   permanently-visible sibling button never qualifies.
 *
 * extract-product-gallery.mjs's own CLI entry point still cannot be driven
 * directly here: it independently validates its target is a public http(s)
 * address (publicHttpUrl()) before navigating anywhere, by design (defense
 * in depth against SSRF), which correctly refuses file:// and every
 * loopback/private address - there is no publicly reachable test server in
 * this sandbox to point it at instead. What IS driven directly, with a real
 * browser, is the exact exported logic that entry point calls once a page
 * is open.
 */

test('a shared class across three tabs does not collapse them into one indistinguishable selector', async (t) => {
    const browser = await launch();
    if (!browser) {
        t.skip('No Chromium available on this machine.');
        return;
    }

    try {
        const page = await browser.newPage();
        // The exact shape that broke it: three sibling buttons, identical
        // class, no id/data-testid/aria-controls at all.
        await page.setContent(`
            <button class="tab-btn">Описание</button>
            <button class="tab-btn">Характеристики</button>
            <button class="tab-btn">Отзывы</button>
        `);

        const controls = await page.evaluate(captureObservedControlsInPage);

        assert.equal(controls.length, 3, 'all three tabs must be observed, not just the first');
        const byText = Object.fromEntries(controls.map((c) => [c.text, c.selector]));
        assert.ok(byText['Описание']);
        assert.ok(byText['Характеристики']);
        assert.ok(byText['Отзывы']);
        // Every returned selector must resolve to exactly the one element it
        // was read from.
        for (const control of controls) {
            const matchCount = await page.locator(control.selector).count();
            const matchedText = (await page.locator(control.selector).first().textContent() || '').trim();
            assert.equal(matchCount, 1, `selector "${control.selector}" must match exactly one element`);
            assert.equal(matchedText, control.text);
        }
    } finally {
        await browser.close();
    }
});

test('clicking one of several same-class tabs opens that exact tab, not always the first', async (t) => {
    const browser = await launch();
    if (!browser) {
        t.skip('No Chromium available on this machine.');
        return;
    }

    try {
        const page = await browser.newPage();
        await page.setContent(`
            <button class="tab-btn" onclick="document.getElementById('p1').style.display='block'">Описание</button>
            <button class="tab-btn" onclick="document.getElementById('p2').style.display='block'">Характеристики</button>
            <div id="p1" style="display:none">General description text.</div>
            <div id="p2" style="display:none">GPU: RTX 4070. RAM: 64 GB.</div>
        `);

        const controls = await page.evaluate(captureObservedControlsInPage);
        const specsSelector = controls.find((c) => c.text === 'Характеристики')?.selector;
        assert.ok(specsSelector, 'the second, same-class tab must have its own selector');

        await page.locator(specsSelector).click();

        const text = await page.evaluate(() => document.body.innerText);
        assert.match(text, /RTX 4070/);
        assert.match(text, /64 GB/);
    } finally {
        await browser.close();
    }
});

/** Snapshots visibility for `selector`, then clicks it, then returns the
 * revealed text - the same two-phase sequence extract-product-gallery.mjs
 * itself runs (snapshot before clickAndWaitForGalleryChange(), read after). */
const clickAndReadRevealed = async (page, selector) => {
    const locator = page.locator(selector).first();
    const before = await locator.evaluate(revealedCandidatesVisibilityInPage);
    await locator.click();

    return locator.evaluate(revealedContainerTextInPage, before);
};

test('the revealed area survives even behind a description long enough to fill the truncation on its own', async (t) => {
    const browser = await launch();
    if (!browser) {
        t.skip('No Chromium available on this machine.');
        return;
    }

    try {
        const page = await browser.newPage();
        // Longer than the 12,000-character cap the caller applies to its
        // result - the exact way the bug reproduced: a real click
        // succeeding while the revealed text still never reached the
        // agent, because it sat past the truncation point.
        const longDescription = 'Lorem ipsum dolor sit amet. '.repeat(700);
        await page.setContent(`
            <div>${longDescription}</div>
            <button id="tab-specs" onclick="document.getElementById('specs').style.display='block'">Характеристики</button>
            <div id="specs" style="display:none">GPU: RTX 4070 Behind Long Description. RAM: 64 GB.</div>
        `);
        assert.ok(longDescription.length > 12_000, 'fixture sanity check: the description alone must exceed the cap');

        const revealedText = await clickAndReadRevealed(page, '#tab-specs');
        const text = await page.evaluate(capturePageTextInPage, revealedText);

        assert.ok(text.length <= 20_000);
        assert.match(text.slice(0, 12_000), /RTX 4070 Behind Long Description/,
            'the revealed area must be hoisted ahead of the long description, inside the first 12,000 characters a caller keeps');
    } finally {
        await browser.close();
    }
});

test('the disambiguated `>> nth=N` selector from same-class tabs still resolves via Locator.evaluate, not document.querySelector', async (t) => {
    // The regression this round found: captureObservedControlsInPage()'s
    // own disambiguation for same-class controls produces a selector using
    // Playwright's `>> nth=N` chaining syntax, which document.querySelector
    // inside the page does not understand and throws on - exactly the
    // combination (same-class tabs + reading the revealed area afterwards)
    // silently returning nothing.
    const browser = await launch();
    if (!browser) {
        t.skip('No Chromium available on this machine.');
        return;
    }

    try {
        const page = await browser.newPage();
        await page.setContent(`
            <button class="tab-btn" onclick="document.getElementById('p1').style.display='block'">Описание</button>
            <button class="tab-btn" onclick="document.getElementById('p2').style.display='block'">Характеристики</button>
            <div id="p1" style="display:none">General description text.</div>
            <div id="p2" style="display:none">GPU: RTX 4070 Via Disambiguated Selector.</div>
        `);

        const controls = await page.evaluate(captureObservedControlsInPage);
        const specsControl = controls.find((c) => c.text === 'Характеристики');
        assert.ok(specsControl, 'the same-class tab must still be observed');
        assert.match(specsControl.selector, /nth=/, 'fixture sanity check: this selector must actually need disambiguation');

        // document.querySelector must fail on this selector - confirming the
        // fix is not accidentally masking a selector that would have worked
        // anyway.
        const querySelectorThrows = await page.evaluate((sel) => {
            try {
                document.querySelector(sel);

                return false;
            } catch {
                return true;
            }
        }, specsControl.selector);
        assert.equal(querySelectorThrows, true);

        const revealedText = await clickAndReadRevealed(page, specsControl.selector);

        assert.match(revealedText, /RTX 4070 Via Disambiguated Selector/);
    } finally {
        await browser.close();
    }
});

test('a shared wrapping section holding every tab panel is not mistaken for the one revealed panel', async (t) => {
    // The other regression this round found: closest('section') matched a
    // tabs-group wrapper containing the long description AND every panel,
    // not just the one just opened - reintroducing the exact "long
    // unrelated content wins" failure under a different, very common markup
    // shape (a plain <section> used as a generic tabs container).
    const browser = await launch();
    if (!browser) {
        t.skip('No Chromium available on this machine.');
        return;
    }

    try {
        const page = await browser.newPage();
        const longDescription = 'Lorem ipsum dolor sit amet. '.repeat(700);
        await page.setContent(`
            <section class="tabs">
                <div>${longDescription}</div>
                <button class="tab-btn" onclick="document.getElementById('p1').style.display='block'">Описание</button>
                <button class="tab-btn" onclick="document.getElementById('p2').style.display='block'">Характеристики</button>
                <div id="p1" style="display:none">Duplicate of the description.</div>
                <div id="p2" style="display:none">GPU: RTX 4070 Inside Shared Section.</div>
            </section>
        `);

        const controls = await page.evaluate(captureObservedControlsInPage);
        const specsControl = controls.find((c) => c.text === 'Характеристики');
        assert.ok(specsControl);

        const revealedText = await clickAndReadRevealed(page, specsControl.selector);
        const text = await page.evaluate(capturePageTextInPage, revealedText);

        assert.doesNotMatch(revealedText, /Lorem ipsum/,
            'the shared <section> wrapper must not be accepted as the revealed panel');
        assert.match(text.slice(0, 12_000), /RTX 4070 Inside Shared Section/);
    } finally {
        await browser.close();
    }
});

test('a sibling tab button is never mistaken for the revealed panel - three tabs, specs in the middle, long description, shared section', async (t) => {
    // The exact reproduction GPT gave: "Описание" -> "Характеристики" ->
    // "Отзывы" in a row inside a shared section, opening the middle one.
    // Its own next sibling in document order is not its panel - it is the
    // NEXT TAB'S OWN BUTTON ("Отзывы"), visible and non-empty exactly like
    // a real panel, and previously accepted as one.
    const browser = await launch();
    if (!browser) {
        t.skip('No Chromium available on this machine.');
        return;
    }

    try {
        const page = await browser.newPage();
        const longDescription = 'Lorem ipsum dolor sit amet. '.repeat(700);
        await page.setContent(`
            <section class="tabs">
                <div>${longDescription}</div>
                <button class="tab-btn" onclick="document.getElementById('p1').style.display='block'">Описание</button>
                <button class="tab-btn" onclick="document.getElementById('p2').style.display='block'">Характеристики</button>
                <button class="tab-btn" onclick="document.getElementById('p3').style.display='block'">Отзывы</button>
                <div id="p1" style="display:none">General description text.</div>
                <div id="p2" style="display:none">GPU: RTX 4070 Middle Tab Panel.</div>
                <div id="p3" style="display:none">Customer reviews go here.</div>
            </section>
        `);

        const controls = await page.evaluate(captureObservedControlsInPage);
        const specsControl = controls.find((c) => c.text === 'Характеристики');
        assert.ok(specsControl);

        const revealedText = await clickAndReadRevealed(page, specsControl.selector);
        const text = await page.evaluate(capturePageTextInPage, revealedText);

        assert.notEqual(revealedText, 'Отзывы', 'the next tab\'s own button text must never be accepted as the revealed panel');
        assert.doesNotMatch(revealedText, /Lorem ipsum/, 'the shared section wrapper must not be accepted either');
        assert.match(text.slice(0, 12_000), /RTX 4070 Middle Tab Panel/);
    } finally {
        await browser.close();
    }
});

test('with no before-snapshot, a merely-visible sibling is not trusted as the revealed panel', async (t) => {
    // The fail-safe half of the fix: an older or mistaken caller that omits
    // beforeVisibility must not fall back to the disproven "visible and
    // non-empty" guess - it must find nothing from the structural
    // candidates, rather than confidently returning the wrong thing.
    const browser = await launch();
    if (!browser) {
        t.skip('No Chromium available on this machine.');
        return;
    }

    try {
        const page = await browser.newPage();
        await page.setContent(`
            <button class="tab-btn" onclick="document.getElementById('p1').style.display='block'">Описание</button>
            <button class="tab-btn" onclick="document.getElementById('p2').style.display='block'">Характеристики</button>
            <button class="tab-btn" onclick="document.getElementById('p3').style.display='block'">Отзывы</button>
            <div id="p1" style="display:none">General description text.</div>
            <div id="p2" style="display:none">GPU: RTX 4070 Should Not Be Found Without A Snapshot.</div>
            <div id="p3" style="display:none">Customer reviews go here.</div>
        `);

        const controls = await page.evaluate(captureObservedControlsInPage);
        const specsControl = controls.find((c) => c.text === 'Характеристики');
        await page.locator(specsControl.selector).click();

        const revealedText = await page.locator(specsControl.selector).first()
            .evaluate(revealedContainerTextInPage, undefined);

        assert.equal(revealedText, '');
    } finally {
        await browser.close();
    }
});

test('with nothing clicked, capturePageTextInPage behaves like a plain whole-page read', async (t) => {
    const browser = await launch();
    if (!browser) {
        t.skip('No Chromium available on this machine.');
        return;
    }

    try {
        const page = await browser.newPage();
        await page.setContent('<title>Test Laptop 15</title><h1>Test Laptop 15</h1><p>General description.</p>');

        const text = await page.evaluate(capturePageTextInPage, '');

        assert.match(text, /Test Laptop 15/);
        assert.match(text, /General description/);
    } finally {
        await browser.close();
    }
});

for (const scenario of ['visible-text-update', 'insert-panel', 'remove-sibling']) {
    test(`revealed text survives ${scenario} without relying on sibling positions`, async (t) => {
        const browser = await launch();
        if (!browser) { t.skip('No Chromium available.'); return; }
        try {
            const page = await browser.newPage();
            const longText = 'Unrelated description. '.repeat(900);
            await page.setContent(`<section><div>${longText}</div>
                <button id=specs>Specifications</button><button id=spacer>Reviews</button>
                <div id=panel style=display:${scenario === 'visible-text-update' ? 'block' : 'none'}>${scenario === 'remove-sibling' ? 'GPU: RTX 4070' : 'Old description'}</div>
                <div>Standing footer</div></section>`);
            await page.locator('#specs').evaluate((button, scenario) => {
                button.onclick = () => {
                    if (scenario === 'visible-text-update') document.getElementById('panel').textContent = 'GPU: RTX 4070';
                    else if (scenario === 'insert-panel') button.insertAdjacentHTML('afterend', '<div>GPU: RTX 4070</div>');
                    else {
                        document.getElementById('spacer').remove();
                        document.getElementById('panel').style.display = 'block';
                    }
                };
            }, scenario);
            const revealed = await clickAndReadRevealed(page, '#specs');
            const text = await page.evaluate(capturePageTextInPage, revealed);
            assert.equal(revealed, 'GPU: RTX 4070');
            assert.match(text.slice(0, 12_000), /RTX 4070/);
        } finally { await browser.close(); }
    });
}

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
