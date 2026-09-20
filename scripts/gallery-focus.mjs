export const captureGalleryScoutInPage = ({ excludedContextPatternSource, focusSelector = '', initialUrl = '', rememberState = true }) => {
    const excludedContextPattern = new RegExp(excludedContextPatternSource.replaceAll('\\\\', '\\'), 'i');
    const semanticContext = (element) => {
        const parts = [];
        let current = element;

        for (let depth = 0; current && depth < 8; depth++, current = current.parentElement) {
            for (const attribute of [
                'id', 'class', 'role', 'aria-label', 'title', 'data-testid',
                'data-component-type', 'data-feature-name', 'data-cel-widget',
            ]) {
                const value = current.getAttribute?.(attribute);
                if (value) parts.push(value);
            }

            if (current.matches?.('section,aside,[role="region"],[role="dialog"],dialog')) {
                const heading = current.querySelector?.('h1,h2,h3,h4,[role="heading"]');
                if (heading?.textContent) parts.push(heading.textContent.slice(0, 240));
            }
        }

        return parts.join(' ').replace(/([a-z])([A-Z])/g, '$1 $2');
    };
    const excludedContext = (element) => excludedContextPattern.test(semanticContext(element));
    const sanitize = (element) => {
        const clone = element.cloneNode(true);

        for (const node of [clone, ...clone.querySelectorAll('*')]) {
            for (const attribute of [...node.attributes]) {
                if (/^on/i.test(attribute.name) || ['style', 'nonce', 'integrity', 'srcset', 'data-srcset', 'data-bgset'].includes(attribute.name)) {
                    node.removeAttribute(attribute.name);
                }
            }
        }

        // svg is never a gallery photo source in this pipeline (product
        // photos are always <img src>/network requests, never inline paths)
        // but a single star-rating or icon svg can be thousands of
        // characters of path/gradient data - enough on its own to consume
        // the whole per-fragment budget below before any of the actually
        // useful text (captions, price, SKU) is reached.
        for (const node of clone.querySelectorAll('script,style,noscript,iframe,object,embed,form,input,textarea,svg')) {
            node.remove();
        }

        return clone.outerHTML.replace(/\s+/g, ' ').slice(0, 1600);
    };
    const visible = (element) => {
        const rect = element.getBoundingClientRect();
        const style = getComputedStyle(element);

        return rect.width > 1 && rect.height > 1
            && style.display !== 'none'
            && style.visibility !== 'hidden';
    };
    const inViewport = (element) => {
        const rect = element.getBoundingClientRect();

        return visible(element)
            && rect.bottom > 0
            && rect.right > 0
            && rect.top < innerHeight
            && rect.left < innerWidth;
    };
    const attributeSelector = (element, name, value) =>
        `${element.tagName.toLowerCase()}[${name}=${JSON.stringify(value)}]`;
    const selectorFor = (element) => {
        const id = element.getAttribute('id');

        if (id && id.length <= 100) {
            const selector = `#${CSS.escape(id)}`;

            if (document.querySelectorAll(selector).length === 1) {
                return selector;
            }
        }

        for (const name of ['data-testid', 'data-test', 'data-selenium', 'data-qa', 'aria-label', 'name']) {
            const value = element.getAttribute(name);

            if (!value || value.length > 160) {
                continue;
            }

            const selector = attributeSelector(element, name, value);

            try {
                if (document.querySelectorAll(selector).length > 0) {
                    return selector;
                }
            } catch {
                // Try the next stable attribute.
            }
        }

        const classTokens = [...element.classList]
            .filter((token) => token.length >= 3
                && token.length <= 60
                && !/^\d/.test(token)
                && !/[a-f0-9]{8,}/i.test(token))
            .slice(0, 2);

        if (classTokens.length) {
            return element.tagName.toLowerCase()+classTokens.map((token) => `.${CSS.escape(token)}`).join('');
        }

        return element.tagName.toLowerCase();
    };
    const rectFor = (element) => {
        const rect = element.getBoundingClientRect();

        return {
            x: Math.round(rect.x),
            y: Math.round(rect.y),
            width: Math.round(rect.width),
            height: Math.round(rect.height),
        };
    };

    // Attention only; never changes collection or recipe validation.
    let root = null;
    let reason = focusSelector ? 'selected_container' : 'wide_requested';
    if (focusSelector) {
        try {
            const nth = focusSelector.match(/^(.*) >> nth=(\d+)$/);
            const matches = [...document.querySelectorAll(nth ? nth[1] : focusSelector)];
            const selected = nth ? matches.slice(Number(nth[2]), Number(nth[2]) + 1) : matches;
            if (selected.length !== 1) reason = selected.length ? 'ambiguous_container' : 'container_missing';
            else if (!visible(selected[0])) reason = 'container_hidden';
            else if (!selected[0].querySelector('img,picture,button,a,[role=button],canvas')) reason = 'container_empty';
            else root = selected[0];
            const address = (value) => { const u = new URL(value); u.hash = ''; return u.href; };
            if (initialUrl && address(location.href) !== address(initialUrl)) { root = null; reason = 'page_navigated'; }
        } catch { root = null; reason = 'invalid_focus_selector'; }
    }
    const overlays = new Set([...document.querySelectorAll('[role=dialog],dialog[open],[aria-modal=true],[role=alert]')].filter(visible));
    if (root) {
        const r = root.getBoundingClientRect();
        for (const [x, y] of [[r.left + r.width / 2, r.top + r.height / 2], [r.left + 1, r.top + 1], [r.right - 1, r.bottom - 1]]) {
            if (x < 0 || y < 0 || x >= innerWidth || y >= innerHeight) continue;
            let node = document.elementFromPoint(x, y);
            if (!node || root.contains(node)) continue;
            while (node && node !== document.body) {
                if (['fixed', 'sticky'].includes(getComputedStyle(node).position)
                    && !node.contains(root) && visible(node)) { overlays.add(node); break; }
                node = node.parentElement;
            }
        }
    }
    // A viewer can be a plain div outside the selected root, without dialog/slider names.
    // Track actual node/source/visibility changes, not names; report them as observations,
    // never as proof that those images belong to the product.
    const stateKey = Symbol.for('ningredy.gallery-observation-images');
    const previousImages = globalThis[stateKey];
    const currentImages = new WeakMap();
    const regionKey = Symbol.for('ningredy.gallery-observation-regions');
    const changedRegions = new Set(root ? [...(globalThis[regionKey] || [])]
        .filter((node) => node.isConnected && visible(node) && !root.contains(node)) : []);
    for (const img of document.images) {
        const state = { visible: visible(img), src: img.currentSrc || img.getAttribute('src') || '' };
        currentImages.set(img, state);
        const previous = previousImages?.get(img);
        if (!root || !previousImages || root.contains(img) || !state.visible
            || (previous?.visible && previous.src === state.src)) continue;
        let region = img.parentElement;
        for (let depth = 0; region && depth < 4 && region.parentElement !== document.body
            && !region.querySelector('button,a,[role=button]'); depth++) {
            region = region.parentElement;
        }
        if (region && region !== document.body && region !== document.documentElement
            && !region.contains(root)) changedRegions.add(region);
    }
    if (rememberState) {
        globalThis[stateKey] = currentImages;
        globalThis[regionKey] = changedRegions;
    }
    const outside = [...new Set([...overlays, ...changedRegions])]
        .filter((node) => !root || !root.contains(node));
    const withinObservation = (node) => !root || root.contains(node) || outside.some((overlay) => overlay.contains(node));
    const r = root?.getBoundingClientRect();
    const clip = r ? { x: Math.max(0, r.left), y: Math.max(0, r.top),
        width: Math.max(0, Math.min(innerWidth, r.right) - Math.max(0, r.left)),
        height: Math.max(0, Math.min(innerHeight, r.bottom) - Math.max(0, r.top)) } : null;
    const observationFocus = {
        mode: root ? 'focused' : 'page', selector: focusSelector, reason,
        anomalies: [...(focusSelector && !root ? [reason] : []),
            ...(overlays.size ? ['overlay_visible'] : []),
            ...(changedRegions.size ? ['outside_images_changed'] : [])],
        screenshot_clip: !outside.length && clip?.width > 1 && clip?.height > 1 ? clip : null,
        wide_observation_available: true,
    };
    const mediaContainerSelector = [
        '[class*=gallery i]', '[class*=thumbnail i]', '[class*=slider i]',
        '[class*=carousel i]', '[class*=swiper i]', '[class*=zoom i]',
        '[class*=product-image i]', '[class*=product-media i]', '[data-selenium*=media i]',
    ].join(',');
    const scopeFilter = (list, belongs) => root ? list.filter(belongs) : list;
    const candidates = scopeFilter(
        [...document.querySelectorAll([
            '[data-old-hires]', '[data-zoom-image]', '[data-large_image]', '[data-full]', '[itemprop=image]',
            '[class*=gallery i]', '[class*=thumbnail i]', '[class*=slider i]',
            '[class*=carousel i]', '[class*=swiper i]', '[class*=zoom i]',
            '[class*=product-image i]', '[class*=product-media i]', '[data-selenium*=media i]',
            'button[aria-label*=next i]', 'button[aria-label*=image i]',
        ].join(','))].filter((element) => !excludedContext(element)),
        (element) => withinObservation(element),
    ).slice(0, 70);
    const interactiveControlElements = [...document.querySelectorAll('button,a,[role=button]')]
        .filter((element) => /\b(gallery|media|image|photo|thumbnail|carousel|slider|zoom|next|more)\b/i.test([
            element.getAttribute('aria-label'),
             element.getAttribute('title'),
             element.getAttribute('class'),
             element.getAttribute('data-selenium'),
             element.getAttribute('href'),
             element.textContent,
        ].filter(Boolean).join(' ')))
        .filter((element) => !excludedContext(element));
    if (root) candidates.unshift(root, ...outside);
    const interactiveControls = scopeFilter(
        interactiveControlElements,
        (element) => withinObservation(element),
    ).slice(0, 50)
        .map(sanitize)
        .filter(Boolean);
    const actionCandidateObjects = [...document.querySelectorAll('button,a,[role=button],summary')]
        .filter(visible)
        .filter((element) => !excludedContext(element))
        .map((element, documentIndex) => {
            const selector = selectorFor(element);
            const signal = [
                element.getAttribute('aria-label'),
                element.getAttribute('title'),
                element.getAttribute('class'),
                element.getAttribute('data-selenium'),
                element.getAttribute('href'),
                element.textContent,
            ].filter(Boolean).join(' ');
            const withinMedia = Boolean(element.closest(mediaContainerSelector));
            const containsImage = Boolean(element.querySelector('img,picture'));
            let selectorCount = 0;
            let selectorIndex = 0;

            try {
                const selectorMatches = [...document.querySelectorAll(selector)];
                selectorCount = selectorMatches.length;
                selectorIndex = Math.max(0, selectorMatches.indexOf(element));
            } catch {
                // The AI still receives the element, but knows the selector is unusable.
            }

            return {
                document_index: documentIndex,
                selector,
                selector_match_count: selectorCount,
                selector_index: selectorIndex,
                tag: element.tagName.toLowerCase(),
                role: element.getAttribute('role') || null,
                text: (element.textContent || '').replace(/\s+/g, ' ').trim().slice(0, 180),
                aria_label: (element.getAttribute('aria-label') || '').slice(0, 180),
                title: (element.getAttribute('title') || '').slice(0, 180),
                href: (element.getAttribute('href') || '').slice(0, 500),
                disabled: element.matches(':disabled,[aria-disabled=true]'),
                in_viewport: inViewport(element),
                within_media: withinMedia,
                within_observation: withinObservation(element),
                contains_image: containsImage,
                rect: rectFor(element),
                relevance: (withinMedia ? 4 : 0)
                    + (containsImage ? 2 : 0)
                    + (inViewport(element) ? 1 : 0)
                    + (/\b(gallery|media|image|photo|thumbnail|carousel|slider|zoom|next|more)\b/i.test(signal) ? 5 : 0),
            };
        });
    const actionCandidates = scopeFilter(actionCandidateObjects, (candidate) => candidate.within_observation)
        .sort((left, right) => right.relevance - left.relevance || left.document_index - right.document_index)
        .slice(0, 80)
        .map(({ relevance, ...candidate }) => candidate);
    const imageCandidateObjects = [...document.images].filter((element) => !excludedContext(element))
        .map((element, documentIndex) => {
            const dataAttributes = Object.fromEntries(
                [...element.attributes]
                    .filter((attribute) => /^data-/i.test(attribute.name)
                        && /(src|image|zoom|large|full|hires|original)/i.test(attribute.name))
                    .slice(0, 10)
                    .map((attribute) => [attribute.name, attribute.value.slice(0, 500)]),
            );
            const parentControl = element.closest('button,a,[role=button]');
            // A thumbnail button whose full-resolution URL is already a
            // quoted literal inside its own onclick (real case: onclick=
            // "window.changeMainImage('.../02.png', this)") needs no click
            // at all - but the agent can only notice that if this URL is
            // actually part of what it is shown, same reasoning as
            // data_attributes below.
            const parentControlOnclick = (parentControl?.getAttribute('onclick') || '').slice(0, 500) || null;

            return {
                document_index: documentIndex,
                selector: selectorFor(element),
                src: (element.getAttribute('src') || '').slice(0, 500),
                current_src: (element.currentSrc || '').slice(0, 500),
                srcset: (element.getAttribute('srcset') || '').slice(0, 4000),
                alt: (element.getAttribute('alt') || '').slice(0, 180),
                natural_width: element.naturalWidth || 0,
                natural_height: element.naturalHeight || 0,
                rendered: rectFor(element),
                visible: visible(element),
                in_viewport: inViewport(element),
                within_media: Boolean(element.closest(mediaContainerSelector)),
                within_observation: withinObservation(element),
                parent_control_selector: parentControl ? selectorFor(parentControl) : null,
                parent_control_onclick: parentControlOnclick,
                data_attributes: dataAttributes,
            };
        });
    const imageCandidates = scopeFilter(imageCandidateObjects, (candidate) => candidate.within_observation)
        .sort((left, right) =>
            Number(right.within_media) - Number(left.within_media)
            || (right.natural_width * right.natural_height) - (left.natural_width * left.natural_height))
        .slice(0, 50);

    return {
        observation_focus: observationFocus,
        final_url: location.href,
        title: document.title.slice(0, 500),
        product_headings: [...document.querySelectorAll('h1')].filter(visible)
            .map((node) => (node.innerText || '').trim().slice(0, 500)).filter(Boolean).slice(0, 4),
        fragments: candidates.map(sanitize).filter(Boolean).slice(0, 32),
        interactive_controls: interactiveControls,
        action_candidates: actionCandidates,
        image_candidates: imageCandidates,
        // Do not hide an outside consent/modal layer when media is focused.
        visible_overlays: [...overlays].slice(0, 8).map(sanitize),
        outside_changed_regions: [...changedRegions].slice(0, 8).map(sanitize),
        // Structural suggestions, independent of gallery-related class names.
        container_candidates: [...new Set([...document.images].filter(visible)
            .flatMap((img) => [img.parentElement, img.parentElement?.parentElement]))]
            .filter((node) => node && node !== document.body && node !== document.documentElement && withinObservation(node))
            .slice(0, 80).map((node) => {
                const base = selectorFor(node);
                let matches;
                try { matches = [...document.querySelectorAll(base)]; } catch { return null; }
                return { selector: matches.length > 1 ? base + ' >> nth=' + matches.indexOf(node) : base,
                    image_count: node.querySelectorAll('img').length, rect: rectFor(node) };
            }).filter(Boolean),
        page_geometry: {
            viewport_width: innerWidth,
            viewport_height: innerHeight,
            document_width: document.documentElement.scrollWidth,
            document_height: document.documentElement.scrollHeight,
            visible_dialogs: [...document.querySelectorAll('[role=dialog],dialog[open]')].filter(visible).length,
        },
    };
};
