import test from 'node:test';
import assert from 'node:assert/strict';
import { chromium } from 'playwright-core';
import { captureGalleryScoutInPage } from '../../scripts/gallery-focus.mjs';
import { evaluateWithRecipeSelectors } from '../../scripts/recipe-dom-selectors.mjs';
import { readTraversalMediaInDocument, waitForTraversalMedia } from '../../scripts/gallery-traversal.mjs';
import { normalizeRecipeActions, recipeActionRequestedIndex, recipeActionPlanStatus } from '../../scripts/product-gallery-utils.mjs';

test('selectors emitted by the actual scout resolve to those exact containers during traversal', async () => {
    const browser = await chromium.launch({ headless: true });
    try {
        const page = await browser.newPage();
        const svg = 'data:image/svg+xml,' + encodeURIComponent('<svg xmlns="http://www.w3.org/2000/svg" width="100" height="100"></svg>');
        await page.setContent('<div class="media"><img src="' + svg + '"></div><div class="media"><img src="' + svg + '"></div>');
        const scout = await page.evaluate(captureGalleryScoutInPage, { excludedContextPatternSource: 'a^' });
        assert.equal(scout.container_candidates.length, 2);
        for (const container of scout.container_candidates) {
            const media = await readTraversalMediaInDocument(page, { selectors: [container.selector] });
            assert.equal(media.images.length, 1);
            assert.deepEqual(media.selector_errors, []);
            const exact = await evaluateWithRecipeSelectors(page, [container.selector],
                ({ resolvedSelectors, selector }) => ({
                    count: resolvedSelectors.elements[selector].length,
                    index: [...document.querySelectorAll('.media')].indexOf(resolvedSelectors.elements[selector][0]),
                }), { selector: container.selector });
            assert.equal(exact.count, 1);
            assert.equal(exact.index, Number(container.selector.split('nth=')[1]));
        }
        const missing = await readTraversalMediaInDocument(page, { selectors: ['div.media >> nth=8'] });
        assert.deepEqual(missing.images, []);
        assert.deepEqual(missing.selector_errors, [], 'A missing match is different from invalid syntax.');
        const invalid = await waitForTraversalMedia(
            () => readTraversalMediaInDocument(page, { selectors: ['div.media >> nth=bad'] }),
            { deadline: Date.now() + 1000 },
        );
        assert.equal(invalid.status, 'invalid_recipe_selector');
        assert.equal(invalid.ready, false);
    } finally { await browser.close(); }
});

test('explicit iteration survives normalization and is independent of current match count', () => {
    for (const mode of ['elements', 'repeat']) {
        const [action] = normalizeRecipeActions([{ kind: 'click_each', selector: 'button', index: 0, iteration_mode: mode }]);
        assert.equal(action.iteration_mode, mode);
        for (const count of [1, 7]) {
            assert.equal(recipeActionRequestedIndex(action, 2, count), mode === 'elements' ? 2 : 0);
        }
    }
});

test('explicit traversal cannot manufacture completion from enough legacy clicks', () => {
    for (const iteration_mode of ['elements', 'repeat']) {
        const result = recipeActionPlanStatus({
            actions: [{ kind: 'click_each', selector: '#control', index: 0, limit: 1, iteration_mode }],
            actionTrace: [{ action: 'click_each', action_index: 0, clicked: true, changed: false, selector_match_count: 1 }],
        });
        assert.equal(result.complete, false);
        assert.equal(result.steps[0].completion, 'traversal_not_confirmed');
    }
});
