import { router } from '@inertiajs/vue3';

const canAnimate = () => typeof document.startViewTransition === 'function'
    && document.visibilityState === 'visible'
    && !window.matchMedia('(prefers-reduced-motion: reduce)').matches;
const isCatalog = (url) => url.pathname === '/catalog' || url.pathname === '/';
const isStorefront = (url) => url.origin === window.location.origin
    && (isCatalog(url) || url.pathname.startsWith('/products/'));
let activeTransition;

function styleTransition(transition, kind) {
    activeTransition = transition;
    document.documentElement.dataset.pageTransition = kind;
    const clear = () => {
        if (activeTransition !== transition) return;
        delete document.documentElement.dataset.pageTransition;
        activeTransition = undefined;
    };
    transition.finished.then(clear, clear);
}

export function navigationVisitOptions(href, options) {
    const from = new URL(window.location.href);
    const to = new URL(href, from);
    if ((options.method || 'get').toLowerCase() !== 'get' || options.prefetch || !isStorefront(from) || !isStorefront(to) || !canAnimate()) {
        return { viewTransition: false };
    }
    const updatesCatalog = isCatalog(from) && isCatalog(to) && Object.keys(options.data || {}).length > 0;
    if (from.pathname === to.pathname && from.search === to.search && !updatesCatalog) return { viewTransition: false };
    let kind = isCatalog(to) ? 'back' : 'forward';
    if (isCatalog(from) && isCatalog(to)) {
        const previousPage = Number(from.searchParams.get('page') || 1);
        const nextPage = Number(to.searchParams.get('page') || 1);
        kind = updatesCatalog || previousPage === nextPage ? 'results' : nextPage > previousPage ? 'page-next' : 'page-previous';
    }
    return { viewTransition: (transition) => styleTransition(transition, kind) };
}

// Capture before the explicit Back action; Inertia restores filters and scroll.
export function backToCatalog() {
    if (!canAnimate()) { window.history.back(); return; }
    const transition = document.startViewTransition(() => new Promise((resolve) => {
        let timeout;
        const done = () => { stop(); window.clearTimeout(timeout); resolve(); };
        const stop = router.on('navigate', done);
        timeout = window.setTimeout(done, 2500);
        window.history.back();
    }));
    styleTransition(transition, 'back');
    transition.ready.catch(() => {});
}
