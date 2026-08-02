import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import {
    galleryProbeMinimumSide,
    imageAssetKey,
    normalizeImageCandidate,
} from '../../scripts/product-gallery-utils.mjs';

const productUrl = 'https://www.cdw.com/product/example/8501080';

test('accepts extensionless dynamic image URLs', () => {
    assert.equal(
        normalizeImageCandidate('//webobjects2.cdw.com/is/image/CDW/8501080a', productUrl),
        'https://webobjects2.cdw.com/is/image/CDW/8501080a',
    );
});

test('lowers the probe threshold only for a structurally complete Playwright gallery', () => {
    assert.equal(galleryProbeMinimumSide({
        minimumSide: 600,
        confirmedMinimumSide: 400,
        galleryPresent: true,
        expectedCount: 6,
        observedCount: 6,
    }), 400);
    assert.equal(galleryProbeMinimumSide({
        minimumSide: 600,
        confirmedMinimumSide: 400,
        galleryPresent: true,
        expectedCount: 6,
        observedCount: 1,
    }), 600);
});

test('rejects known non-image resources before the browser probe', () => {
    assert.equal(normalizeImageCandidate('/manual.pdf', productUrl), null);
    assert.equal(normalizeImageCandidate('/assets/logo.svg', productUrl), null);
    assert.equal(normalizeImageCandidate('/media/product-video-poster.jpg', productUrl), null);
    assert.equal(normalizeImageCandidate('/gallery/360-view/frame-01.jpg', productUrl), null);
});

test('uses browser-valid quoted attribute selectors for non-photo media', () => {
    const extractor = readFileSync(new URL('../../scripts/extract-product-gallery.mjs', import.meta.url), 'utf8');

    assert.doesNotMatch(extractor, /\[class\*=(?:video|360) i\]/);
    assert.match(extractor, /\[class\*="video" i\]/);
    assert.match(extractor, /\[class\*="360" i\]/);
});
test('deduplicates resized dynamic-image variants without merging gallery frames', () => {
    const original = 'https://webobjects2.cdw.com/is/image/CDW/8501080a';
    const thumbnail = original+'?$product-200x144$';
    const differentFrame = 'https://webobjects2.cdw.com/is/image/CDW/8501080b';

    assert.equal(imageAssetKey(original), imageAssetKey(thumbnail));
    assert.notEqual(imageAssetKey(original), imageAssetKey(differentFrame));
});
