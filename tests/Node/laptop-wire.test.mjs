import test from 'node:test';
import assert from 'node:assert/strict';
import { laptopLines, project } from '../../resources/js/laptopWire.js';

const top = (segments) => Math.max(...segments.flatMap(({ a, b }) => [a[1], b[1]]));

test('the lid lies on the body when closed and stands up when open', () => {
    assert.ok(top(laptopLines(0, 0).segments) < 0.1);
    assert.ok(top(laptopLines(100, 0).segments) > 2);
});

test('taking the laptop apart names the parts the shop sells', () => {
    const assembled = laptopLines(100, 0);
    const apart = laptopLines(100, 1);
    assert.deepEqual(apart.labels.map((label) => label.text), ['SSD', 'RAM', 'CPU']);
    assert.ok(assembled.labels.every((label) => label.alpha === 0));
    assert.ok(apart.labels.every((label) => label.alpha === 1));
    assert.ok(top(apart.segments) > top(assembled.segments));
});

test('a pose outside the range is held at its limit', () => {
    assert.deepEqual(laptopLines(500, 7), laptopLines(135, 1));
});

test('a farther point is drawn smaller and reported deeper', () => {
    const view = { yaw: 0, pitch: 0, size: 100, cx: 0, cy: 0 };
    const [nearX, , nearDepth] = project([1, 0, -1], view);
    const [farX, , farDepth] = project([1, 0, 1], view);
    assert.ok(nearX > farX);
    assert.ok(farDepth > nearDepth);
});
