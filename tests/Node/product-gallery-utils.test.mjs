import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import {
    galleryProbeMinimumSide,
    imageAssetKey,
    isAllowedProductNavigation,
    normalizeImageCandidate,
    urlQualityScore,
    withUpscaledSizeParam,
} from '../../scripts/product-gallery-utils.mjs';

const productUrl = 'https://www.cdw.com/product/example/8501080';

test('allows a same-product internal gallery route but rejects unrelated navigation', () => {
    const specification = 'https://us.msi.com/Laptop/Katana-17-HX-B14WX/Specification';

    assert.equal(
        isAllowedProductNavigation(specification, '/Laptop/Katana-17-HX-B14WX/Gallery'),
        true,
    );
    assert.equal(
        isAllowedProductNavigation(specification, '/Laptop/Another-Model/Gallery'),
        false,
    );
    assert.equal(
        isAllowedProductNavigation(specification, 'https://shop.example/Laptop/Katana-17-HX-B14WX/Gallery'),
        false,
    );
    assert.equal(
        isAllowedProductNavigation(specification, '/Laptop/Gallery'),
        false,
    );
});

test('keeps same-path modal and query interactions allowed', () => {
    assert.equal(
        isAllowedProductNavigation(productUrl, productUrl+'?view=gallery#media'),
        true,
    );
});

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

test('upscales a small width/height query parameter instead of discarding the candidate', () => {
    assert.equal(
        withUpscaledSizeParam('https://www.hp.com/nl-nl/shop/media/.../frontleft.jpg?store=nl-nl&width=140&fit=bounds'),
        'https://www.hp.com/nl-nl/shop/media/.../frontleft.jpg?store=nl-nl&width=1600&fit=bounds',
    );
    assert.equal(
        withUpscaledSizeParam('https://cdn.example/photo.jpg?w=200&h=200'),
        'https://cdn.example/photo.jpg?w=1600&h=1600',
    );
});

test('does not touch URLs without a resizable size parameter, or already large enough', () => {
    assert.equal(withUpscaledSizeParam('https://cdn.example/photo.jpg'), null);
    assert.equal(withUpscaledSizeParam('https://cdn.example/photo.jpg?width=2000'), null);
});

test('deduplicates ASUS size-variant paths (//w48, //w64, //w96, //w184) as one photo', () => {
    const base = 'https://dlcdnwebimgs.asus.com/gain/a57df082-80c1-4b35-9493-ff9727e4e7a4';
    const differentPhoto = 'https://dlcdnwebimgs.asus.com/gain/7fb77e0a-97ef-4be0-babd-22892330add9';

    assert.equal(imageAssetKey(base+'//w48'), imageAssetKey(base+'//w184'));
    assert.notEqual(imageAssetKey(base+'//w48'), imageAssetKey(differentPhoto+'//w48'));
});

test('deduplicates Shopify filename-suffix and query-param size variants as one photo', () => {
    // Real case (draft #27, vishalperipherals.com): six real gallery photos,
    // but the master URL, its "_180x" thumbnail, its "_grande" and "_1920x"
    // renditions, and its "&width=1445" query variant all pointed at the
    // exact same underlying asset and none of them collapsed to one key.
    const master = 'https://vishalperipherals.com/cdn/shop/files/x1605va_285f809a.png?v=1753955410';
    const thumb180 = 'https://vishalperipherals.com/cdn/shop/files/x1605va_285f809a_180x.png?v=1753955410';
    const grande = 'https://vishalperipherals.com/cdn/shop/files/x1605va_285f809a_grande.png?v=1753955410';
    const zoom1920 = 'https://vishalperipherals.com/cdn/shop/files/x1605va_285f809a_1920x.png?v=1753955410';
    const widthQuery = master+'&width=1445';
    const differentPhoto = 'https://vishalperipherals.com/cdn/shop/files/x1605va_9999999b_180x.png?v=1753955410';

    const key = imageAssetKey(master);
    assert.equal(imageAssetKey(thumb180), key);
    assert.equal(imageAssetKey(grande), key);
    assert.equal(imageAssetKey(zoom1920), key);
    assert.equal(imageAssetKey(widthQuery), key);
    assert.notEqual(imageAssetKey(differentPhoto), key);
});

test('scores a Shopify filename-suffix variant by its encoded width', () => {
    assert.equal(
        urlQualityScore('https://vishalperipherals.com/cdn/shop/files/photo_180x.png'),
        180,
    );
    assert.equal(
        urlQualityScore('https://vishalperipherals.com/cdn/shop/files/photo_1920x.png'),
        1920,
    );
});

test('upscales a Shopify filename-suffix variant by stripping it down to the master asset', () => {
    assert.equal(
        withUpscaledSizeParam('https://vishalperipherals.com/cdn/shop/files/photo_180x.png?v=1'),
        'https://vishalperipherals.com/cdn/shop/files/photo.png?v=1',
    );
    assert.equal(
        withUpscaledSizeParam('https://vishalperipherals.com/cdn/shop/files/photo_grande.png?v=1'),
        'https://vishalperipherals.com/cdn/shop/files/photo.png?v=1',
    );
    assert.equal(
        withUpscaledSizeParam('https://vishalperipherals.com/cdn/shop/files/photo.png?v=1'),
        null,
    );
});
