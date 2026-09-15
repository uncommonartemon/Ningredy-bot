import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { observedGalleryRenditions, rememberGalleryLayer, selectGalleryFrames } from '../../scripts/gallery-observation.mjs';

test('all strict gallery frames reach filtering even when caller needs only four', () => {
    const frames = Array.from({ length: 13 }, (_, i) => ({ url: 'frame-' + i }));
    assert.deepEqual(selectGalleryFrames(frames, true, 4), frames);
    assert.equal(selectGalleryFrames(frames, false, 4).length, 4);
    const approved = selectGalleryFrames(frames, true, 10).filter((_, i) => i > 1);
    assert.equal(approved.length, 11);
    assert.equal(approved.slice(0, 10).length, 10);
});

/**
 * Regression, 2026-09-15 (Idealo, Geizhals): a page with more candidates than
 * the budget allows used to keep whichever were discovered first - small,
 * unrelated icons found early in the DOM - and drop real, larger gallery
 * photos found later, even though each candidate already carries a `score`
 * from probing. The budget must go to the best-scoring candidates, not the
 * earliest-discovered ones.
 */
test('a non-strict selection keeps the highest-scored frames, not merely the earliest-found ones', () => {
    const earlyButSmall = Array.from({ length: 8 }, (_, i) => ({ url: 'category-icon-' + i, score: 240 * 200 }));
    const lateButLarge = Array.from({ length: 3 }, (_, i) => ({ url: 'product-photo-' + i, score: 1600 * 1200 }));
    const frames = [...earlyButSmall, ...lateButLarge];

    const selected = selectGalleryFrames(frames, false, 3);

    assert.deepEqual(
        selected.map((frame) => frame.url),
        ['product-photo-0', 'product-photo-1', 'product-photo-2'],
        'the 3 real, larger photos must win the 3 available slots over 8 smaller icons found earlier',
    );
});

test('a non-strict selection with no score data falls back to the original, discovery order', () => {
    const frames = Array.from({ length: 5 }, (_, i) => ({ url: 'frame-' + i }));

    assert.deepEqual(
        selectGalleryFrames(frames, false, 3).map((frame) => frame.url),
        ['frame-0', 'frame-1', 'frame-2'],
    );
});

test('a non-strict selection tolerates bare URL strings without a score, unchanged from before', () => {
    const urls = ['a', 'b', 'c', 'd', 'e'];

    assert.deepEqual(selectGalleryFrames(urls, false, 3), ['a', 'b', 'c']);
});

test('strict gallery accepts an observed larger rendition but not a foreign network frame', () => {
    const small = 'https://cdn.example/is/image/shop/laptop-front?wid=584';
    const large = 'https://cdn.example/is/image/shop/laptop-front?wid=1600';
    const banner = 'https://cdn.example/is/image/shop/banner?wid=1600';
    assert.deepEqual(observedGalleryRenditions([small], [small, large, banner]), [small, large]);
    assert.deepEqual(observedGalleryRenditions([], [large]), []);
});

test('layer history retains an opened high-resolution image after the viewer closes', () => {
    const opened = { image_candidates: [{ current_src: '/large.jpg', natural_width: 1200 }],
        visible_overlays: ['<dialog>Consent</dialog>'] };
    const first = rememberGalleryLayer([], opened, { phase: 'click' });
    const history = rememberGalleryLayer(first, { image_candidates: [] }, { phase: 'after_each' });
    assert.equal(history[0].image_candidates[0].natural_width, 1200);
    assert.equal(history[0].visible_overlays.length, 1);
    assert.equal(history[1].image_candidates.length, 0);
    assert.equal(first.length, 1);
});

test('diagnostic bounds report omissions without mutating collection evidence', () => {
    const scout = { image_candidates: Array.from({ length: 35 }, (_, i) => ({ src: String(i) })) };
    let history = [];
    for (let i = 0; i < 9; i++) history = rememberGalleryLayer(history, scout, { index: i });
    assert.equal(history.length, 6);
    assert.equal(history[0].action.index, 3);
    assert.equal(history[5].omitted.image_candidates, 15);
    assert.equal(scout.image_candidates.length, 35);
});

test('runner does not inflate finite zoom into full-gallery traversal', () => {
    const runner = readFileSync(new URL('../../scripts/extract-product-gallery.mjs', import.meta.url), 'utf8');
    assert.match(runner, /const followupLimit = Math.max\(1, Math.min\(20, action.after_each_limit \|\| 1\)\)/);
    assert.doesNotMatch(runner, /followupLimit = .*traversalCeiling/);
    assert.doesNotMatch(runner, /followupTrace.after_each_truncated = true/);
});

test('large DOM attributes cannot grow layer history without a byte budget', () => {
    const scout = { image_candidates: Array.from({ length: 20 }, () => ({ srcset: 'x'.repeat(4000) })) };
    let history = [];
    for (let i = 0; i < 8; i++) history = rememberGalleryLayer(history, scout, { index: i });
    assert.ok(Buffer.byteLength(JSON.stringify(history), 'utf8') < 24100);
    assert.ok(history.at(-1).omitted.image_candidates > 0);
    assert.equal(scout.image_candidates.length, 20);
});
