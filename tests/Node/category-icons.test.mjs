import test from 'node:test';
import assert from 'node:assert/strict';
import { CATEGORY_ICONS, categoryIcon } from '../../resources/js/categoryIcons.js';

test('a category is drawn by its own icon, then its address, then its parents', () => {
    assert.equal(categoryIcon(['laptop', 'laptops']), 'laptop');
    assert.equal(categoryIcon([null, 'ssd', 'component', 'components']), 'ssd');
    assert.equal(categoryIcon(['', 'thermal-paste', 'component', 'components']), 'component');
});

test('a name written another way finds the same icon', () => {
    assert.equal(categoryIcon(['Laptops']), 'laptop');
    assert.equal(categoryIcon(['other-tech']), 'devices');
    assert.equal(categoryIcon(['graphics_card']), 'gpu');
});

test('an unknown category gets the plain box, never nothing', () => {
    assert.equal(categoryIcon([]), 'box');
    assert.equal(categoryIcon([null, 'vacuum-tubes']), 'box');
});

test('every icon is paths on the one grid', () => {
    for (const [name, paths] of Object.entries(CATEGORY_ICONS)) {
        assert.ok(paths.length > 0, name);
        for (const path of paths) assert.match(path, /^M[\d.\-a-zA-Z ]+$/, name);
    }
});
