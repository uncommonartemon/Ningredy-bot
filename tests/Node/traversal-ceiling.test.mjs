import { test } from 'node:test';
import assert from 'node:assert/strict';
import { TRAVERSAL_CEILING, traversalCeiling } from '../../scripts/product-gallery-utils.mjs';

/**
 * A recipe stores how to open and walk a gallery. How many photographs the
 * product it was trained on happened to have is not part of that, and three
 * numbers were still acting as though it were.
 */
test('a count learned on one product does not cap the next one', () => {
    // Trained on a laptop with seven photographs; the next has thirteen.
    assert.equal(Math.min(13, traversalCeiling(7, 20)), 13);
    assert.equal(Math.min(30, traversalCeiling(7, 20)), 30);
});

test('zero still means do not use this control at all', () => {
    // Structural, not a count: the agent looked and there is no carousel worth
    // pressing. Turning that into "press forty times" would walk a gallery the
    // recipe deliberately left alone.
    assert.equal(traversalCeiling(0, 15), 0);
});

test('a declared number above the ceiling is respected', () => {
    assert.equal(traversalCeiling(60, 20), 60);
});

test('a missing number falls back and is still a ceiling', () => {
    assert.equal(traversalCeiling(null, 20), TRAVERSAL_CEILING);
    assert.equal(traversalCeiling(undefined, 1), TRAVERSAL_CEILING);
    assert.equal(traversalCeiling('7', 20), TRAVERSAL_CEILING, 'A string is not a declared number.');
});

test('a negative number cannot turn into a walk', () => {
    assert.equal(traversalCeiling(-3, 20), 0);
});
