import assert from 'node:assert/strict';
import test from 'node:test';
import { normalizeRecipeActions, pickVariantOption } from '../../scripts/product-gallery-utils.mjs';

test('the option naming every word of the requested colour is picked, whatever the picker calls it', () => {
    // Live #140: the ASUS page opened on silver; "Ponder Blue" was one swatch away.
    const options = ['Foggy Silver', 'Ponder Blue', 'Jade Black'];
    assert.equal(pickVariantOption(options, 'Ponder Blue'), 1);
    assert.equal(pickVariantOption(options, 'ponder blue'), 1);
    assert.equal(pickVariantOption(['Цвет: Ponder Blue (синий)', 'Foggy Silver'], 'Ponder Blue'), 0);
});

test('a single-word colour does not pick a longer colour name that merely contains it', () => {
    assert.equal(pickVariantOption(['Jade Blue', 'Ponder Blue', 'Silver'], 'Blue'), null);
    assert.equal(pickVariantOption(['Blue', 'Silver'], 'Blue'), 0);
});

test('nothing is picked without a requested colour or a matching option', () => {
    assert.equal(pickVariantOption(['Silver', 'Black'], ''), null);
    assert.equal(pickVariantOption(['Silver', 'Black'], 'Ponder Blue'), null);
    assert.equal(pickVariantOption([], 'Ponder Blue'), null);
});

test('select_variant is a recipe action the runner accepts', () => {
    const [action] = normalizeRecipeActions([{ kind: 'select_variant', selector: '.variants [role=radio]', index: 0,
        limit: 1, wait_after_ms: 300, when: 'if_present', iteration_mode: 'elements', purpose: 'pick the requested colour' }]);
    assert.equal(action.kind, 'select_variant');
    assert.equal(action.selector, '.variants [role=radio]');
});
