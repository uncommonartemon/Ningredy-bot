import { imageAssetKey } from './product-gallery-utils.mjs';

// A caller's publication budget is not the size of a known gallery.
//
// Real case, 2026-09-15 (Idealo, Geizhals): a page carries far more candidate
// images than the budget allows - category icons, offer/retailer badges,
// unrelated widgets - alongside the actual product gallery. Truncating by
// discovery order kept whichever candidates were found first, which on
// these pages meant small icons that happened to be earlier in the DOM/
// network order won a slot while the real, larger gallery photos (found
// later, and already carrying a computed `score` the caller never
// consulted) were silently dropped - a genuine gallery, correctly detected,
// crowded out of its own result by unrelated small images. Sorting by score
// first means the budget is spent on the best candidates found, not
// whichever were found earliest; a candidate without a `score` (a bare URL
// string, as used before probing) sorts as 0 and this degrades to the
// previous, order-preserving behaviour exactly as before.
export const selectGalleryFrames = (frames, strict, limit) => (strict
    ? [...frames]
    : [...frames].sort((left, right) => (right?.score ?? 0) - (left?.score ?? 0)).slice(0, limit));

// Network URLs can improve a known frame, never introduce another gallery.
export const observedGalleryRenditions = (domUrls, observedUrls) => {
    const keys = new Set(domUrls.map(imageAssetKey));
    return [...new Set([...domUrls, ...observedUrls.filter((url) => keys.has(imageAssetKey(url)))])];
};

// Diagnostic history only: these bounds never limit actions or collected photos.
export const rememberGalleryLayer = (history, scout, action) => {
    const fields = { action_candidates: 20, image_candidates: 20, fragments: 4, visible_overlays: 8 };
    const observation = { action, final_url: scout.final_url, page_geometry: scout.page_geometry };
    observation.omitted = {};
    for (const [field, limit] of Object.entries(fields)) {
        const values = Array.isArray(scout[field]) ? scout[field] : [];
        observation[field] = values.slice(0, limit);
        observation.omitted[field] = Math.max(0, values.length - limit);
    }
    // Long srcsets/data attributes must not multiply the next paid prompt.
    const bytes = (value) => Buffer.byteLength(JSON.stringify(value), 'utf8');
    while (bytes(observation) > 12000) {
        const field = Object.keys(fields).filter((key) => observation[key].length > 0)
            .sort((a, b) => bytes(observation[b]) - bytes(observation[a]))[0];
        if (!field) break;
        observation[field].pop();
        observation.omitted[field]++;
    }
    const retained = [...history, observation].slice(-6);
    let omittedLayers = Math.max(0, history.length + 1 - retained.length);
    while (retained.length > 1 && bytes(retained) > 24000) {
        retained.shift();
        omittedLayers++;
    }
    observation.omitted.previous_layers = omittedLayers;
    return retained;
};
