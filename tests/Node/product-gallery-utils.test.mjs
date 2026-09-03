import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import {
    galleryCollectionTarget,
    galleryContextLooksExcluded,
    imageAssetKey,
    imageDimensionsMeetMinimum,
    isAllowedProductNavigation,
    normalizeImageCandidate,
    normalizeRecipeActions,
    prioritizeCandidateRenditions,
    recipeActionOpensGallery,
    recipeActionPlanStatus,
    recipeActionShouldStop,
    recipeActionTraversesGallery,
    urlQualityScore,
} from '../../scripts/product-gallery-utils.mjs';

const productUrl = 'https://www.cdw.com/product/example/8501080';
test('rejects recommendation, related-product and review carousel contexts', () => {
    assert.equal(
        galleryContextLooksExcluded('What other items do customers buy after viewing this item?'),
        true,
    );
    assert.equal(galleryContextLooksExcluded('relatedProducts a-carousel'), true);
    assert.equal(galleryContextLooksExcluded('customer review media slider'), true);
    assert.equal(galleryContextLooksExcluded('sponsored product carousel'), true);
});

test('keeps the primary product media gallery context', () => {
    assert.equal(
        galleryContextLooksExcluded('imageBlock product media gallery thumbnails'),
        false,
    );
    assert.equal(galleryContextLooksExcluded('main lightbox zoom viewer'), false);
});


test('allows a same-product internal gallery route but rejects unrelated navigation', () => {
    const specification = 'https://us.msi.com/Laptop/Katana-17-HX-B14WX/Specification';

    assert.equal(
        isAllowedProductNavigation(specification, '/Laptop/Katana-17-HX-B14WX/Gallery'),
        true,
    );
    assert.equal(
        isAllowedProductNavigation(
            'https://www.gigabyte.com/us/Laptop/AORUS-MASTER-16-AM6H/sp',
            '/us/Laptop/AORUS-MASTER-16-AM6H/gallery',
        ),
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

test('keeps only the exact image URL observed by the browser or page markup', () => {
    const page = 'https://www.bhphotovideo.com/c/product/1932364-REG/example.html/accessories';

    assert.equal(
        normalizeImageCandidate(
            'https://static.bhphoto.com/images/multiple_images/images500x500/1767181436_IMG_2646502.jpg',
            page,
        ),
        'https://static.bhphoto.com/images/multiple_images/images500x500/1767181436_IMG_2646502.jpg',
    );
    assert.equal(
        normalizeImageCandidate(
            'https://static.bhphoto.com/images/images750x750/1767181363_1932364.jpg',
            page,
        ),
        'https://static.bhphoto.com/images/images750x750/1767181363_1932364.jpg',
    );
    assert.equal(
        normalizeImageCandidate(
            'https://example-other-cdn.test/media/240x240/product.jpg',
            'https://example-other-cdn.test/product-page',
        ),
        'https://example-other-cdn.test/media/240x240/product.jpg',
    );
    assert.equal(
        normalizeImageCandidate(
            'https://dlcdnwebimgs.asus.com/gain/a57df082-80c1-4b35-9493-ff9727e4e7a4//w48',
            'https://www.asus.com/laptops/example',
        ),
        'https://dlcdnwebimgs.asus.com/gain/a57df082-80c1-4b35-9493-ff9727e4e7a4//w48',
    );
});
test('requires the complete ordered action plan regardless of already collected URLs', () => {
    const actions = [
        {
            kind: 'click',
            selector: 'button[data-gallery]',
            index: 0,
            limit: 1,
            wait_after_ms: 100,
            purpose: 'Open the expanded gallery',
        },
        {
            kind: 'click_each',
            selector: 'button[data-thumbnail]',
            index: 0,
            limit: 16,
            wait_after_ms: 100,
            purpose: 'Click each thumbnail',
        },
    ];
    const opener = {
        action: 'click',
        action_index: 0,
        clicked: true,
        changed: true,
        selector_match_count: 1,
    };
    const oneThumbnail = {
        action: 'click_each',
        action_index: 1,
        repetition: 0,
        clicked: true,
        changed: true,
        selector_match_count: 16,
    };

    assert.equal(recipeActionOpensGallery(actions[0]), true);
    assert.equal(recipeActionTraversesGallery(actions[0]), false);
    assert.equal(recipeActionTraversesGallery(actions[1]), true);
    const partial = recipeActionPlanStatus({
        actions,
        actionTrace: [opener, oneThumbnail],
    });
    assert.equal(partial.complete, false);
    assert.equal(partial.completed_actions, 1);
    assert.equal(partial.steps[1].required_clicks, 16);

    const complete = recipeActionPlanStatus({
        actions,
        actionTrace: [
            opener,
            ...Array.from({ length: 16 }, (_, repetition) => ({
                ...oneThumbnail,
                repetition,
            })),
        ],
    });
    assert.equal(complete.complete, true);
    assert.equal(complete.completed_actions, 2);
});

test('requires arrow traversal to reach no-change or its declared limit', () => {
    const actions = [{
        kind: 'click_until_no_change',
        selector: 'button.next',
        index: 0,
        limit: 5,
        wait_after_ms: 100,
        purpose: 'Traverse next until exhausted',
    }];
    const changed = (repetition) => ({
        action: 'click_until_no_change',
        action_index: 0,
        repetition,
        clicked: true,
        changed: true,
        selector_match_count: 1,
    });

    assert.equal(recipeActionPlanStatus({ actions, actionTrace: [changed(0), changed(1)] }).complete, false);
    assert.equal(recipeActionPlanStatus({
        actions,
        actionTrace: [changed(0), { ...changed(1), changed: false }],
    }).complete, true);
});

test('one failed thumbnail click does not abort the remaining click_each traversal', () => {
    assert.equal(recipeActionShouldStop({
        kind: 'click_each',
        clicked: false,
        changed: false,
    }), false);
    assert.equal(recipeActionShouldStop({
        kind: 'click',
        clicked: false,
        changed: false,
    }), true);
    assert.equal(recipeActionShouldStop({
        kind: 'click_until_no_change',
        clicked: true,
        changed: false,
    }), true);
});

test('a single-element click_each control stops once it stops responding', () => {
    // A next/prev arrow matches one element, so every repetition re-presses the
    // same control: an unchanged press means the remaining ones cannot help.
    assert.equal(recipeActionShouldStop({
        kind: 'click_each',
        clicked: true,
        changed: false,
        matchCount: 1,
    }), true);
    // Still responding: keep traversing.
    assert.equal(recipeActionShouldStop({
        kind: 'click_each',
        clicked: true,
        changed: true,
        matchCount: 1,
    }), false);
    // Several matches means the next repetition targets a different element,
    // so one unchanged click must not abort the traversal.
    assert.equal(recipeActionShouldStop({
        kind: 'click_each',
        clicked: true,
        changed: false,
        matchCount: 6,
    }), false);
});

test('requires the nested after-each action for every visited thumbnail', () => {
    const actions = [{
        kind: 'click_each', selector: '.thumb', index: 0, limit: 2,
        wait_after_ms: 100, purpose: 'Visit each image and enlarge it',
        after_each_selector: '.zoom-plus', after_each_limit: 2, after_each_wait_after_ms: 100,
    }];
    const primary = [0, 1].map((repetition) => ({
        action: 'click_each', action_index: 0, repetition,
        selector_match_count: 2, clicked: true, changed: true,
    }));
    const followup = (parent_repetition, followup_repetition, changed) => ({
        action: 'click_each', action_index: 0, after_each: true,
        parent_repetition, followup_repetition, clicked: true, changed,
    });

    assert.equal(recipeActionPlanStatus({
        actions,
        actionTrace: [...primary, followup(0, 0, true), followup(0, 1, true), followup(1, 0, true)],
    }).complete, false);
    assert.equal(recipeActionPlanStatus({
        actions,
        actionTrace: [...primary, followup(0, 0, true), followup(0, 1, true), followup(1, 0, false)],
    }).complete, true);
});

test('checks configured image width and height independently for every source', () => {
    assert.equal(imageDimensionsMeetMinimum({
        width: 1000,
        height: 550,
        minimumWidth: 1000,
        minimumHeight: 550,
    }), true);
    assert.equal(imageDimensionsMeetMinimum({
        width: 999,
        height: 700,
        minimumWidth: 1000,
        minimumHeight: 550,
    }), false);
    assert.equal(imageDimensionsMeetMinimum({
        width: 1200,
        height: 549,
        minimumWidth: 1000,
        minimumHeight: 550,
    }), false);
    assert.equal(imageDimensionsMeetMinimum({
        width: 1000,
        height: 1,
        minimumWidth: 1000,
        minimumHeight: 0,
    }), true);
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

test('scopes the post-interaction scout to the confirmed media container, not the initial one', () => {
    // A later round already knows an interaction happened - re-scanning the
    // whole page again (not just the opened gallery/viewer) is why a real
    // round 2 payload grew instead of shrinking (~55KB -> ~79KB). The
    // initial, pre-interaction scout call must stay unscoped (nothing has
    // opened yet); only the post-interaction one should pass scopeToMedia.
    const extractor = readFileSync(new URL('../../scripts/extract-product-gallery.mjs', import.meta.url), 'utf8');

    assert.match(extractor, /scout = await captureInteractionScout\(\);/);
    assert.match(extractor, /postInteractionScout = await captureInteractionScout\(true\)/);
    assert.match(extractor, /const scopeFilter = \(list, isWithinMedia\) => \{/);
});

test('counts Swiper data-image frames and declared product gallery size', () => {
    const extractor = readFileSync(new URL('../../scripts/extract-product-gallery.mjs', import.meta.url), 'utf8');

    assert.match(extractor, /\.swiper-slide\[data-image\]/);
    assert.match(extractor, /dataImageCount/);
    assert.match(extractor, /declaredImageCount/);
});

test('deduplicates resized dynamic-image variants without merging gallery frames', () => {
    const original = 'https://webobjects2.cdw.com/is/image/CDW/8501080a';
    const thumbnail = original+'?$product-200x144$';
    const differentFrame = 'https://webobjects2.cdw.com/is/image/CDW/8501080b';

    assert.equal(imageAssetKey(original), imageAssetKey(thumbnail));
    assert.notEqual(imageAssetKey(original), imageAssetKey(differentFrame));
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

test('probes every Shopify gallery frame before extra renditions consume the budget', () => {
    const candidates = [];

    for (let frame = 1; frame <= 13; frame++) {
        for (const width of [246, 493, 600, 823, 1100, 1445, 1680, 2200]) {
            candidates.push(`https://shop.example/cdn/shop/files/frame-${frame}.jpg?width=${width}`);
        }
    }

    const prioritized = prioritizeCandidateRenditions(candidates);
    const firstPass = prioritized.slice(0, 13);

    assert.equal(new Set(firstPass.map(imageAssetKey)).size, 13);
    assert.ok(firstPass.every((url) => url.endsWith('width=2200')));
});

test('stops gallery traversal at the observed target instead of an unreachable runner limit', () => {
    assert.equal(galleryCollectionTarget(20, 13), 13);
    assert.equal(galleryCollectionTarget(10, 13), 10);
    assert.equal(galleryCollectionTarget(10, 0), 10);
});

test('never sends a complete srcset value to URL normalization as one image', () => {
    const extractor = readFileSync(new URL('../../scripts/extract-product-gallery.mjs', import.meta.url), 'utf8');

    assert.match(extractor, /\['srcset', 'data-srcset'\]\.includes\(attribute\.toLowerCase\(\)\)/);
});

test('deduplicates named rendition directories and keeps xlarge ahead of large', () => {
    const large = 'https://cdn.example/Images/Product/Default/large/108438004_8959892653.jpg';
    const xlarge = 'https://cdn.example/Images/Product/Default/xlarge/108438004_8959892653.jpg';
    const other = 'https://cdn.example/Images/Product/Default/xlarge/another-frame.jpg';

    assert.equal(imageAssetKey(large), imageAssetKey(xlarge));
    assert.notEqual(imageAssetKey(xlarge), imageAssetKey(other));
    assert.ok(urlQualityScore(xlarge) > urlQualityScore(large));
});

test('deduplicates BigCommerce Stencil WxH/Nw path-segment renditions and keeps the largest', () => {
    // Regression: a real fleetnetwork.ca search staged the same hero photo
    // four times (500x500, 640w, 840w, 1280x1280) as distinct gallery
    // frames. The size sits in its own path segment between "/stencil/" and
    // "/products/", not adjacent to the filename like the named
    // /large//xlarge/ buckets above, and not a query param either.
    const thumb = 'https://cdn11.bigcommerce.com/s-xwx16vh8tc/images/stencil/500x500/products/689686/3683749/1086710301__09195.1766340706.jpg?c=2';
    const srcsetMid = 'https://cdn11.bigcommerce.com/s-xwx16vh8tc/images/stencil/640w/products/689686/3683749/1086710301__09195.1766340706.jpg?c=2';
    const zoom = 'https://cdn11.bigcommerce.com/s-xwx16vh8tc/images/stencil/1280x1280/products/689686/3683749/1086710301__09195.1766340706.jpg?c=2';
    const otherAngle = 'https://cdn11.bigcommerce.com/s-xwx16vh8tc/images/stencil/1280x1280/products/689686/3683750/1086710301__24458.1766340706.jpg?c=2';

    assert.equal(imageAssetKey(thumb), imageAssetKey(srcsetMid));
    assert.equal(imageAssetKey(srcsetMid), imageAssetKey(zoom));
    assert.notEqual(imageAssetKey(zoom), imageAssetKey(otherAngle));
    assert.ok(urlQualityScore(zoom) > urlQualityScore(srcsetMid));
    assert.ok(urlQualityScore(srcsetMid) > urlQualityScore(thumb));
});

test('deduplicates UUID filename renditions and keeps the larger rendition', () => {
    const frame = '01988206-a137-7bfd-912b-69d69304d643';
    const thumb = `https://static01.example/productimages/${frame}_720.jpeg`;
    const expanded = `https://static01.example/productimages/${frame}_sea.jpeg`;
    const alternateFormat = `https://static01.example/productimages/${frame}_1000.avif`;
    const other = 'https://static01.example/productimages/01988206-a1a1-73fc-a9cf-83cc4b7d9198_720.jpeg';

    assert.equal(imageAssetKey(thumb), imageAssetKey(expanded));
    assert.equal(imageAssetKey(expanded), imageAssetKey(alternateFormat));
    assert.notEqual(imageAssetKey(expanded), imageAssetKey(other));
    assert.ok(urlQualityScore(expanded) > urlQualityScore(thumb));
});

test('respects the explicit action plan and keeps the automatic opener only for legacy traversal', () => {
    const extractor = readFileSync(new URL('../../scripts/extract-product-gallery.mjs', import.meta.url), 'utf8');
    const actionFallback = extractor.indexOf('await attemptExpandedGallery(priorViewerOpenAction)');
    const actionTraversal = extractor.indexOf('const locator = page.locator(action.selector)', actionFallback);
    const legacyFallback = extractor.indexOf('await attemptExpandedGallery();');
    const legacyTraversal = extractor.indexOf('const thumbnails = thumbnailSelectors.length', legacyFallback);

    assert.ok(actionFallback >= 0 && actionFallback < actionTraversal);
    assert.match(extractor, /!expandedGalleryAttempted && priorViewerOpenAction && actionTraversesGallery/);
    assert.match(extractor, /strictRecipe\s*\? \[\.\.\.new Set\(domImages\)\]/);
    assert.match(extractor, /source: domKeys\.has\(key\) \? 'recipe_dom'/);
    assert.ok(legacyFallback >= 0 && legacyFallback < legacyTraversal);
    assert.match(extractor, /includePageFallbacks: !strictRecipe/);
    assert.doesNotMatch(extractor, /break actionPlanLoop/);
    assert.doesNotMatch(extractor, /enoughCollected\(\) && thumbnailClicks/);
});

test('sanitize strips inline svg from DOM fragments sent to the AI trainer', () => {
    // A single star-rating or icon svg can be thousands of characters of
    // path/gradient data - never a gallery photo source in this pipeline
    // (photos are always <img src>/network requests) but enough on its own
    // to consume the whole 1600-char per-fragment budget before any of the
    // actually useful text (captions, price, SKU) is reached.
    const extractor = readFileSync(new URL('../../scripts/extract-product-gallery.mjs', import.meta.url), 'utf8');
    const sanitizeStart = extractor.indexOf('const sanitize = (element) => {');
    const sanitizeEnd = extractor.indexOf('const visible = (element) => {', sanitizeStart);
    const sanitizeBody = extractor.slice(sanitizeStart, sanitizeEnd);

    assert.match(sanitizeBody, /querySelectorAll\('script,style,noscript,iframe,object,embed,form,input,textarea,svg'\)/);
});

test('scopes video and 360 filtering to standalone media items', () => {
    const extractor = readFileSync(new URL('../../scripts/extract-product-gallery.mjs', import.meta.url), 'utf8');

    assert.match(extractor, /element\.closest\?\.\('video,\[data-type\],\[data-media-type\],li,figure,\[role="option"\]'/);
    assert.match(extractor, /li\[class\*="video" i\],figure\[class\*="video" i\],\[role="option"\]\[class\*="video" i\]/);
    assert.doesNotMatch(extractor, /element\.closest\?\.\('\[class\*="video" i\],\[class\*="360" i\]'/);
});

test('normalizes the safe ordered AI action plan and rejects executable selectors', () => {
    const actions = normalizeRecipeActions([
        {
            kind: 'click',
            selector: ' button[data-gallery] ',
            index: 99,
            limit: 0,
            wait_after_ms: 9999,
            purpose: 'Open the gallery',
        },
        {
            kind: 'click_each',
            selector: '[data-thumbnail]',
            index: 0,
            limit: 8,
            wait_after_ms: 150,
            after_each_selector: ' button[data-zoom-plus] ',
            after_each_limit: 30,
            after_each_wait_after_ms: 10,
            purpose: 'Visit each photo',
        },
        {
            kind: 'click',
            selector: 'a[href="javascript:alert(1)"]',
            index: 0,
            limit: 1,
            wait_after_ms: 100,
            purpose: 'Unsafe',
        },
        {
            kind: 'evaluate',
            selector: '.gallery',
            index: 0,
            limit: 1,
            wait_after_ms: 100,
            purpose: 'Unsupported',
        },
    ]);

    assert.deepEqual(actions, [
        {
            kind: 'click',
            selector: 'button[data-gallery]',
            index: 20,
            limit: 1,
            wait_after_ms: 1500,
            purpose: 'Open the gallery',
        },
        {
            kind: 'click_each',
            selector: '[data-thumbnail]',
            index: 0,
            limit: 8,
            wait_after_ms: 150,
            after_each_selector: 'button[data-zoom-plus]',
            after_each_limit: 20,
            after_each_wait_after_ms: 50,
            purpose: 'Visit each photo',
        },
    ]);
});
