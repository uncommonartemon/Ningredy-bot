import { readFileSync } from 'node:fs';
import assert from 'node:assert/strict';
import test from 'node:test';
import { normalizeRecipeActions, recipeActionRequestedIndex, recipeActionPlanStatus } from '../../scripts/product-gallery-utils.mjs';

// The other half of tests/Feature/RecipeActionContractTest.php. One action of a
// recipe crosses two whitelists that cannot read each other - PHP on the way out
// of validation, this file on the way into the browser - so both are held to the
// same committed list. after_each_selector was asked for in the prompt, accepted
// by the rules and checked by the validator while the runner never received it
// once, purely because one of the two lists had not been told about it.
const contract = JSON.parse(readFileSync(new URL('../contracts/recipe-action-fields.json', import.meta.url), 'utf8'));

const maximalAction = {
    kind: 'click_each',
    when: 'if_present',
    iteration_mode: 'elements',
    selector: '.gallery .thumb',
    index: 0,
    limit: 3,
    wait_after_ms: 200,
    after_each_selector: '.viewer .zoom',
    after_each_limit: 2,
    after_each_wait_after_ms: 300,
    purpose: 'walk the thumbnails and enlarge each frame',
};

test('every agreed action field survives into the browser', () => {
    const [normalized] = normalizeRecipeActions([maximalAction]);

    for (const field of contract.fields) {
        assert.ok(
            Object.hasOwn(normalized, field),
            `The action field [${field}] is dropped by normalizeRecipeActions, so the browser never receives it.`,
        );
    }
});

test('the agreed list is the whole list the browser is given', () => {
    // Kept in both directions: a field the runner invents on its own would not
    // be validated on the PHP side and has no place in the contract either.
    const [normalized] = normalizeRecipeActions([maximalAction]);

    assert.deepEqual(Object.keys(normalized).sort(), [...contract.fields].sort());
});

test('an invented field is still dropped', () => {
    const [normalized] = normalizeRecipeActions([{ ...maximalAction, evaluate: 'fetch("https://evil.example")' }]);

    assert.ok(!Object.hasOwn(normalized, 'evaluate'));
});

test('iteration mode keeps its meaning across normalization and remains optional for old recipes', () => {
    for (const iteration_mode of ['elements', 'repeat', 'auto']) {
        const [normalized] = normalizeRecipeActions([{ ...maximalAction, iteration_mode }]);
        assert.equal(normalized.iteration_mode, iteration_mode);
    }

    const { iteration_mode, ...legacyAction } = maximalAction;
    const [legacy] = normalizeRecipeActions([legacyAction]);
    assert.ok(!Object.hasOwn(legacy, 'iteration_mode'));
});

test('scroll_each enumerates current elements even when the training gallery had one', () => {
    const [action] = normalizeRecipeActions([{ ...maximalAction, kind: 'scroll_each', iteration_mode: 'repeat' }]);
    assert.ok(action, 'The browser must receive the scroll traversal action');
    assert.equal(recipeActionRequestedIndex(action, 0, 1), 0);
    assert.equal(recipeActionRequestedIndex(action, 4, 9), 4);
    const [legacy] = normalizeRecipeActions([{ ...maximalAction, kind: 'scroll_into_view' }]);
    assert.equal(recipeActionRequestedIndex(legacy, 4, 9), 0);
});

test('scroll_each requires actual traversal proof, not just a successful scroll', () => {
    const action = { ...maximalAction, kind: 'scroll_each', after_each_selector: '' };
    const trace = { action: 'scroll_each', action_index: 0, clicked: true, selector_match_count: 9 };
    assert.equal(recipeActionPlanStatus({ actions: [action], actionTrace: [trace] }).complete, false);
    assert.equal(recipeActionPlanStatus({ actions: [action], actionTrace: [{ ...trace,
        traversal_complete: false, traversal_stop_reason: 'safety_ceiling_reached' }] }).complete, false);
});

test('scroll_each cannot use a smaller final count or optional absence to hide unvisited elements', () => {
    const action = { ...maximalAction, kind: 'scroll_each', after_each_selector: '' };
    const traces = [0, 1].map((index) => ({ action: 'scroll_each', action_index: 0,
        clicked: true, index, selector_match_count: 4 - index }));
    Object.assign(traces[1], { traversal_complete: true, traversal_match_count: 2 });
    assert.equal(recipeActionPlanStatus({ actions: [action], actionTrace: traces }).complete, false);
    assert.equal(recipeActionPlanStatus({ actions: [action], actionTrace: [{ action: 'scroll_each',
        action_index: 0, optional_absent: true, clicked: false }] }).complete, false);
});

test('scroll_each counts gallery elements independently of matching zoom controls', () => {
    const action = { ...maximalAction, kind: 'scroll_each', after_each_limit: 1 };
    const traces = [{ action: 'scroll_each', action_index: 0, clicked: true, index: 0,
        selector_match_count: 1, traversal_complete: true, traversal_match_count: 1 },
    { action: 'scroll_each', action_index: 0, parent_repetition: 0, after_each: true,
        clicked: true, changed: true, selector_match_count: 2 }];
    assert.equal(recipeActionPlanStatus({ actions: [action], actionTrace: traces }).complete, true);
});
