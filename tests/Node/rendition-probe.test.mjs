import assert from 'node:assert/strict';
import test from 'node:test';
import { readFileSync } from 'node:fs';
import { largerRenditions } from '../../scripts/product-gallery-utils.mjs';

// The same cases hold the PHP copy of these rules (tests/Unit/ImageRenditionProbeTest.php).
const cases = JSON.parse(readFileSync(new URL('../Fixtures/rendition-probe-cases.json', import.meta.url), 'utf8'));

for (const { name, thumbnail, first, also = [], width = null, height = null } of cases) test(`${name}: the address names where its larger rendition is`, () => {
    const guesses = largerRenditions(thumbnail, 6, width ? { width, height } : null);
    if (first === null) {
        assert.deepEqual(guesses, []);
        return;
    }
    assert.equal(guesses[0], first, guesses.join('\n'));
    for (const guess of also) assert.ok(guesses.includes(guess), `${guess} not among:\n${guesses.join('\n')}`);
    assert.ok(!guesses.includes(thumbnail));
    assert.ok(guesses.length <= 6);
});

test('an address that is not a URL gives no guesses', () => {
    assert.deepEqual(largerRenditions('not a url'), []);
});
