import { imageAssetKey, sameProductIdentity } from './product-gallery-utils.mjs';

// A media widget has its own address/title; those are not a product conflict.
// Explicit commercial identifiers published inside it must not contradict the card.
export const embeddedProductConflicts = (product, frame) => sameProductIdentity(
    { identifiers: product?.identifiers }, { identifiers: frame?.identifiers },
) === false;

// Executed in the selected document, not on the whole page's network traffic.
export const readTraversalMediaInPage = ({ selectors, positionSelector = '' }) => {
    const visible = (el) => {
        const r = el.getBoundingClientRect();
        return r.width > 0 && r.height > 0 && el.checkVisibility({ checkVisibilityCSS: true, checkOpacity: true });
    };
    const images = new Set();
    let busy = false;
    for (const selector of selectors) {
        try {
            for (const root of document.querySelectorAll(selector)) {
                if (!visible(root)) continue;
                busy ||= root.matches('[aria-busy=true]') || Boolean(root.closest('[aria-busy=true]'));
                for (const img of root.matches('img') ? [root] : root.querySelectorAll('img')) {
                    if (visible(img)) images.add(img);
                }
            }
        } catch { /* Invalid selectors are returned as no evidence, never end-of-gallery. */ }
    }
    let position = '';
    try {
        const node = positionSelector && document.querySelector(positionSelector);
        if (node && visible(node)) position = (node.textContent || '').trim().slice(0, 200);
    } catch { /* No position evidence. */ }
    return {
        images: [...images].map((img) => ({ url: img.currentSrc || img.src,
            ready: img.complete && img.naturalWidth > 0, failed: img.complete && img.naturalWidth === 0 })),
        busy, position,
    };
};

export const traversalMediaState = (observation) => {
    const images = observation?.images || [];
    const keys = images.map((image) => {
        try { return imageAssetKey(image.url); } catch { return null; }
    }).filter(Boolean);
    return {
        signature: keys.length ? JSON.stringify([
            [...new Set(keys)].sort(), observation.position || '',
        ]) : '',
        ready: keys.length > 0 && !observation.busy && images.every((image) => image.ready),
        failed: images.some((image) => image.failed),
    };
};

// Wait for the selected media, not networkidle (ads can keep that busy forever).
// The caller supplies the operation deadline; this never grants new time.
export const waitForTraversalMedia = async (read, { deadline, settleMs = 150, pollMs = 50 } = {}) => {
    let stableSince = Date.now();
    let previous = '';
    let state = { signature: '', ready: false, failed: false };
    while (Date.now() < deadline) {
        state = traversalMediaState(await read());
        if (state.signature !== previous || !state.ready) stableSince = Date.now();
        previous = state.signature;
        if (state.ready && Date.now() - stableSince >= settleMs) return { ...state, status: 'ready' };
        if (state.failed) return { ...state, status: 'image_load_failed' };
        await new Promise((resolve) => setTimeout(resolve, pollMs));
    }
    return { ...state, status: 'media_wait_timeout' };
};

// Separate completion evidence from resource guards. A new rendition is progress
// even when its physical asset was already discovered as a thumbnail.
export class GalleryTraversalProgress {
    constructor(initial, urls = []) {
        this.initial = initial?.signature || '';
        this.last = this.initial;
        this.leftInitial = false;
        this.urls = new Set(urls);
        this.stagnant = 0;
    }

    advance(state, urls, { disabled = false } = {}) {
        // A failed image is not a broken arrow. If its DOM identity/position is
        // observable, keep visiting the remaining frames. The caller retains
        // the load failure separately and cannot publish it as verified.
        if (!state.ready && !state.signature) return { stop: true, complete: false, reason: state.status || 'media_not_ready' };
        const changedFrame = Boolean(state.signature && state.signature !== this.last);
        const newRendition = urls.some((url) => !this.urls.has(url));
        urls.forEach((url) => this.urls.add(url));
        if (!this.initial) this.initial = state.signature;
        const returned = this.leftInitial && state.signature === this.initial && changedFrame;
        if (state.signature !== this.initial) this.leftInitial = true;
        this.last = state.signature;
        this.stagnant = changedFrame || newRendition ? 0 : this.stagnant + 1;
        if (disabled || returned) return { stop: true, complete: true, reason: disabled ? 'control_disabled' : 'returned_to_first_frame' };
        if (this.stagnant >= 3) return { stop: true, complete: false, reason: 'no_observable_progress' };
        return { stop: false, complete: false, reason: 'progress' };
    }
}

// A recipe stores a path through frame elements, never a transient Frame ID.
// Empty path is the main document. Ambiguity is an observation, not permission
// to click the first frame. The browser context's request policy still applies.
export const resolveGalleryDocument = async (page, selectors = []) => {
    let document = page;
    for (const selector of selectors) {
        if (typeof selector !== 'string' || selector.length > 300 || /(?:javascript:|https?:|file:|xpath|\0)/i.test(selector)) {
            throw new Error('invalid_frame_selector');
        }
        const locator = document.locator(selector);
        if (await locator.count() !== 1) throw new Error('frame_missing_or_ambiguous: ' + selector);
        const handle = await locator.elementHandle();
        try {
            const frame = await handle.contentFrame();
            if (!frame) throw new Error('selected_element_is_not_a_frame: ' + selector);
            document = frame;
        } finally { await handle.dispose(); }
    }
    return document;
};
