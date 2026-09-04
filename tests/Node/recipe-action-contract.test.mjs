import { readFileSync } from 'node:fs';
import assert from 'node:assert/strict';
import test from 'node:test';
import { normalizeRecipeActions } from '../../scripts/product-gallery-utils.mjs';

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
