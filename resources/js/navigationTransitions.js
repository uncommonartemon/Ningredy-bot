import { router } from '@inertiajs/vue3';

const canAnimate = () => typeof document.startViewTransition === 'function'
    && document.visibilityState === 'visible'
    && !window.matchMedia('(prefers-reduced-motion: reduce)').matches;
const isCatalog = (url) => url.pathname === '/catalog';
const isHome = (url) => url.pathname === '/';
const isStorefront = (url) => url.origin === window.location.origin
    && (isHome(url) || isCatalog(url) || url.pathname.startsWith('/products/'));
let activeTransition;
// The photo of the product the visitor clicked: it flies into the product page's gallery
// instead of fading out and back in. Only that one element carries the shared name.
let clickedPhoto;
if (typeof document !== 'undefined') {
    document.addEventListener('click', (event) => {
        const element = event.target.closest?.('[data-transition-scope]')?.querySelector('[data-transition-photo]');
        clickedPhoto = element ? { element, at: performance.now() } : undefined;
    }, true);
}

function styleTransition(transition, kind) {
    activeTransition = transition;
    document.documentElement.dataset.pageTransition = kind;
    // A click older than a moment belongs to something else (a later Enter in the search, say).
    const photo = kind === 'forward' && clickedPhoto && performance.now() - clickedPhoto.at < 5000 ? clickedPhoto.element : undefined;
    if (photo?.isConnected) photo.style.viewTransitionName = 'product-photo';
    clickedPhoto = undefined;
    const clear = () => {
        if (photo) photo.style.viewTransitionName = '';
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
    // Home is the top: going there is a return; the catalog is a return only from a product.
    let kind = isHome(to) || (isCatalog(to) && !isHome(from)) ? 'back' : 'forward';
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
