// The scout emits CSS with an optional Playwright `>> nth=N` suffix. Resolve
// those addresses once in the selected document; never silently broaden a
// missing nth match to every CSS match or turn malformed syntax into no data.
export const resolveRecipeDomSelectorsInPage = (selectors) => {
    const elements = Object.create(null);
    const errors = [];
    for (const selector of new Set(selectors)) {
        try {
            if (typeof selector !== 'string' || !selector.trim()) throw new Error('Empty selector');
            const nth = selector.match(/^([\s\S]+?)\s*>>\s*nth=(\d+)\s*$/);
            const matches = [...document.querySelectorAll(nth ? nth[1].trim() : selector)];
            const index = nth ? Number(nth[2]) : null;
            if (nth && !Number.isSafeInteger(index)) throw new Error('Invalid nth index');
            elements[selector] = nth ? (matches[index] ? [matches[index]] : []) : matches;
        } catch (error) {
            elements[selector] = [];
            errors.push({ selector, reason: 'invalid_recipe_selector', detail: String(error.message).slice(0, 300) });
        }
    }
    return { elements, errors };
};

// Keep DOM references in a JSHandle, not in serialized JSON or a page-global
// helper. The same resolver is used by collection, readiness and traversal.
export const evaluateWithRecipeSelectors = async (document, selectors, evaluate, args = {}, onErrors = null) => {
    const resolved = await document.evaluateHandle(resolveRecipeDomSelectorsInPage, selectors);
    try {
        const errors = await resolved.evaluate((value) => value.errors);
        if (errors.length) onErrors?.(errors);
        return await document.evaluate(evaluate, { ...args, resolvedSelectors: resolved });
    } finally {
        await resolved.dispose();
    }
};
