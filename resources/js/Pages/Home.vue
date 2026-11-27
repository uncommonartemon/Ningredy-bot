<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { gsap } from 'gsap';
import { ScrollTrigger } from 'gsap/ScrollTrigger';
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import AccountLink from '../Components/AccountLink.vue';
import CategoryIcon from '../Components/CategoryIcon.vue';
import DecodeText from '../Components/DecodeText.vue';
import HomeSearch from '../Components/Home/HomeSearch.vue';
import LaptopWire from '../Components/Home/LaptopWire.vue';
import StoreMasthead from '../Components/StoreMasthead.vue';
import { dragRow } from '../dragRow';
import { useI18n } from '../i18n';
import { usePrice } from '../price';
import '../../css/home.css';

gsap.registerPlugin(ScrollTrigger);

// The start page (decisions of 2026-11-23, 2026-11-25 and 2026-11-27): a scene tied to the
// scroll whose one job is to open the catalog, and its hook is the price. A laptop drawn in lines
// stays behind the page; scrolling opens it, takes it apart and closes it again. Every number and
// price shown comes from the catalog as it is; nothing is promised that the shop did not decide.
const props = defineProps({
    plates: { type: Array, required: true },
    arrivals: { type: Array, required: true },
    ways: { type: Array, required: true },
    categories: { type: Array, required: true },
    brands: { type: Array, required: true },
    budgets: { type: Object, default: null },
    stats: { type: Object, required: true },
});
const { t, locale, localeTag, categoryName, productCount, translatedText } = useI18n();
const { formatPrice } = usePrice();
const root = ref(null);
const wire = ref(null);
const track = ref(null);
const dock = ref(null);
const number = (value) => new Intl.NumberFormat(localeTag.value).format(value);
const padded = (value) => String(value).padStart(2, '0');
const updated = computed(() => (props.stats.updated_at
    ? new Intl.DateTimeFormat(localeTag.value, { day: 'numeric', month: 'long' }).format(new Date(props.stats.updated_at))
    : null));
const titleOf = (item) => translatedText(item.translations, 'title') || item.title;
const catalogUrl = (query) => `/catalog?${new URLSearchParams(query)}`;
// "from 390 Kč" wherever the catalog has prices; nothing at all where it has none.
const fromPrice = (lowest) => (lowest ? `${t('from')} ${formatPrice(lowest.price, lowest.currency)}` : null);
const words = computed(() => t('home.words', []));
const manifesto = computed(() => t('home.manifesto').split(' '));
const arrivalWords = computed(() => t('home.arrivals').split(' '));
const catalogNote = computed(() => (props.stats.products
    ? [productCount(props.stats.products), fromPrice(props.stats.lowest)].filter(Boolean).join(' · ')
    : null));
const ways = computed(() => props.ways.map((way) => ({ ...way, word: t(`home.ways.${way.key}.word`), text: t(`home.ways.${way.key}.text`), link: t(`home.ways.${way.key}.link`) })));
// The row of brands runs in a loop, so it is written out enough times to fill any screen twice.
const brandLoop = computed(() => (props.brands.length ? Array.from({ length: Math.max(2, Math.ceil(16 / props.brands.length)) * 2 }, () => props.brands).flat() : []));
const budgetLabel = (range) => {
    const currency = props.budgets.currency;
    if (range.min === null) return `${t('to')} ${formatPrice(range.max, currency)}`;
    if (range.max === null) return `${t('from')} ${formatPrice(range.min, currency)}`;
    return `${formatPrice(range.min, currency)} – ${formatPrice(range.max, currency)}`;
};
const budgetQuery = (range) => Object.fromEntries([['min_price', range.min], ['max_price', range.max]].filter(([, value]) => value !== null));

// The row of new products: where it stands, for the previous and next buttons.
const row = reactive({ start: true, end: true, progress: 0 });
let slider;

// The way to the catalog stays one press away once the first screen is gone, until the last link shows.
const heroGone = ref(false);
const endReached = ref(false);
const dockShown = computed(() => heroGone.value && !endReached.value);
let watcher;
// A focused element is never left under the fixed button: the page moves it clear.
const keepClear = (event) => {
    const button = dock.value?.$el;
    if (!dockShown.value || !button || button.contains(event.target)) return;
    const focused = event.target.getBoundingClientRect();
    const covered = button.getBoundingClientRect();
    if (focused.right > covered.left - 8 && focused.left < covered.right + 8 && focused.bottom > covered.top - 8 && focused.top < covered.bottom + 8) {
        window.scrollBy({ top: focused.bottom - covered.top + 24, behavior: 'instant' });
    }
};

// Poster lines are set as large as their rule allows and shrink until they fit their box: the
// Ukrainian and Czech words are longer than the English ones. The three first-screen lines keep
// one size; every other line is fitted on its own.
const fitLines = (lines, room, owner = null) => {
    const target = owner || lines[0];
    if (!lines.length || !target) return;
    (owner ? [owner] : lines).forEach((line) => { line.style.fontSize = ''; });
    if (!room) return;
    const widest = Math.max(...lines.map((line) => line.scrollWidth));
    if (widest > room) target.style.fontSize = `${(parseFloat(getComputedStyle(target).fontSize) * room) / widest * 0.98}px`;
};
const fitPoster = () => {
    const scope = root.value;
    if (!scope) return;
    const words = scope.querySelector('.hero__words');
    if (words) fitLines([...words.querySelectorAll('.hero__word-inner')], words.clientWidth, words);
    const head = scope.querySelector('.gallery__head h2');
    if (head) fitLines([...head.querySelectorAll('.gallery__head-line')], head.parentElement.clientWidth, head);
    scope.querySelectorAll('.way').forEach((way) => fitLines([way.querySelector('.way__outline')], way.clientWidth, way.querySelector('.way__word')));
    scope.querySelectorAll('.index__name').forEach((name) => fitLines([name], name.clientWidth));
    const outro = scope.querySelector('.outro__link');
    if (outro) fitLines([outro], scope.querySelector('.outro').clientWidth);
};
const refit = async () => {
    await nextTick();
    fitPoster();
    ScrollTrigger.refresh();
    slider?.measure();
};
watch(locale, refit);
let resized;
const onResize = () => {
    clearTimeout(resized);
    resized = setTimeout(refit, 120);
};

let media;
onMounted(async () => {
    await nextTick();
    const scope = root.value;
    const pose = wire.value.pose;
    const render = wire.value.render;
    const find = (selector) => scope.querySelector(selector);
    const reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    fitPoster();
    document.fonts?.ready.then(refit);
    window.addEventListener('resize', onResize);

    if (track.value) slider = dragRow(track.value, { reduced, onScroll: (state) => Object.assign(row, state) });

    watcher = new IntersectionObserver((entries) => entries.forEach((entry) => {
        if (entry.target.classList.contains('hero')) heroGone.value = !entry.isIntersecting && entry.boundingClientRect.top < 0;
        else endReached.value = entry.isIntersecting || entry.boundingClientRect.top < 0;
    }));
    [find('.hero'), find('.outro__link')].filter(Boolean).forEach((element) => watcher.observe(element));
    document.addEventListener('focusin', keepClear);

    media = gsap.matchMedia(scope);
    media.add({ motion: '(prefers-reduced-motion: no-preference)', wide: '(min-width: 900px)' }, (context) => {
        const { motion, wide } = context.conditions;
        const rest = wide ? { x: 0.2, y: -0.03, scale: 1.3 } : { x: 0, y: -0.2, scale: 0.9 };
        Object.assign(pose, { open: 106, close: 0, explode: 0, yaw: -0.62, pitch: 0.36, alpha: 1, fade: 1, lookX: 0, lookY: 0, ...rest });
        if (!motion) {
            // A still page: the laptop stands open and faint behind the first screen.
            pose.alpha = 0.45;
            render();
            return undefined;
        }

        // The one entrance: the lid opens while the three lines rise into place.
        pose.open = 0;
        pose.fade = 0;
        gsap.timeline({ defaults: { ease: 'power3.out' }, onUpdate: render })
            .to(pose, { fade: 1, duration: 0.6 }, 0)
            .to(pose, { open: 106, duration: 1.5, ease: 'power2.inOut' }, 0.05)
            .from('.hero__word-inner', { yPercent: 108, duration: 0.9, stagger: 0.1 }, 0.05)
            .from('.hero__plate', { autoAlpha: 0, scale: 0.86, duration: 0.7, stagger: 0.09 }, 0.55)
            .from('.hero__foot > *', { autoAlpha: 0, y: 18, duration: 0.5, stagger: 0.08 }, 0.6);

        // From here on the scroll is the clock. No scene holds the page in place: every section is
        // passed like any other, so each one below is measured where it stands.
        const leaving = gsap.timeline({ defaults: { ease: 'none' }, scrollTrigger: { trigger: '.hero', start: 'top top', end: 'bottom top', scrub: 0.6 } })
            .to('.hero__word--0', { xPercent: -5 }, 0)
            .to('.hero__word--1', { xPercent: 6 }, 0)
            .to('.hero__word--2', { xPercent: -3 }, 0)
            .to('.hero__foot', { autoAlpha: 0, y: -50 }, 0);
        // Beside the lines the plates rise at their own pace; under them on a phone they would climb onto the lines.
        if (wide && find('.hero__plate')) leaving.to('.hero__plate', { y: (index) => -(index + 1) * 110 }, 0);

        const turn = (trigger, start, end, from, to) => {
            if (!find(trigger)) return;
            gsap.fromTo(pose, from, { ...to, ease: 'none', immediateRender: false, onUpdate: render, scrollTrigger: { trigger, start, end, scrub: 0.8 } });
        };
        const side = wide ? 0.27 : 0;
        turn('.hero', 'top top', 'bottom top', { yaw: -0.62, pitch: 0.36, alpha: 1, ...rest }, { yaw: -1.05, pitch: 0.52, alpha: 0.5, x: 0, y: 0, scale: 1 });
        turn('.manifesto', 'top 80%', 'bottom 40%', { explode: 0, yaw: -1.05 }, { explode: 1, yaw: -0.25 });
        turn('.ways', 'top 70%', 'bottom 30%', { yaw: -0.25, pitch: 0.52, alpha: 0.5 }, { yaw: 0.85, pitch: 0.3, alpha: 0.3 });
        turn('.gallery', 'top 90%', 'top 20%', { alpha: 0.3 }, { alpha: 0.05 });
        turn('.index', 'top 90%', 'top 30%', { alpha: 0.05, x: 0, yaw: 0.85 }, { alpha: 0.32, x: side, yaw: 1.5 });
        turn('.outro', 'top 95%', 'bottom bottom', { explode: 1, close: 0, x: side, yaw: 1.5, pitch: 0.3, alpha: 0.32, scale: 1 },
            { explode: 0, close: 1, x: 0, yaw: Math.PI * 2 - 0.62, pitch: 0.44, alpha: 0.75, scale: 1.25 });

        gsap.fromTo('.manifesto__word', { opacity: 0.14 }, { opacity: 1, stagger: 0.1, ease: 'none',
            scrollTrigger: { trigger: '.manifesto', start: 'top 72%', end: 'bottom 58%', scrub: true } });

        gsap.utils.toArray('.way', scope).forEach((way, index) => {
            gsap.timeline({ defaults: { ease: 'none' }, scrollTrigger: { trigger: way, start: 'top 88%', end: 'top 28%', scrub: 0.5 } })
                .fromTo(way.querySelector('.way__fill'), { clipPath: 'inset(-20% 100% -20% 0)' }, { clipPath: 'inset(-20% 0% -20% 0)' }, 0)
                .fromTo(way.querySelector('.way__word'), { xPercent: (index % 2 ? 1 : -1) * (wide ? 5 : 2) }, { xPercent: 0 }, 0)
                .fromTo(way.querySelector('.way__body'), { autoAlpha: 0.12, y: 26 }, { autoAlpha: 1, y: 0 }, 0.35);
        });

        gsap.utils.toArray('.index__row', scope).forEach((row, index) => {
            gsap.fromTo(row, { xPercent: index % 2 ? 7 : 3, autoAlpha: 0.2 }, { xPercent: 0, autoAlpha: 1, ease: 'none',
                scrollTrigger: { trigger: row, start: 'top 96%', end: 'top 62%', scrub: 0.5 } });
        });

        // The brands run by themselves and take their speed and direction from the scroll.
        const rows = gsap.utils.toArray('.brands__row', scope);
        const runs = rows.map((line, index) => gsap.fromTo(line, { xPercent: index % 2 ? -50 : 0 }, { xPercent: index % 2 ? 0 : -50, duration: 46, ease: 'none', repeat: -1 }));
        if (runs.length) {
            ScrollTrigger.create({ trigger: '.brands', start: 'top bottom', end: 'bottom top',
                onUpdate: (self) => {
                    const speed = gsap.utils.clamp(-7, 7, self.getVelocity() / 240);
                    const pace = (Math.abs(speed) < 1 ? 1 : Math.abs(speed)) * (self.direction < 0 ? -1 : 1);
                    runs.forEach((run) => gsap.to(run, { timeScale: pace, duration: 0.35, overwrite: true }));
                } });
        }

        if (find('.outro__link')) {
            gsap.fromTo('.outro__link', { yPercent: 28, autoAlpha: 0.2 }, { yPercent: 0, autoAlpha: 1, ease: 'none',
                scrollTrigger: { trigger: '.outro', start: 'top 92%', end: 'top 38%', scrub: 0.5 } });
        }

        let look;
        if (wide) {
            look = (event) => gsap.to(pose, { lookX: event.clientX / window.innerWidth - 0.5, lookY: event.clientY / window.innerHeight - 0.5,
                duration: 0.9, ease: 'power2.out', overwrite: 'auto', onUpdate: render });
            window.addEventListener('pointermove', look, { passive: true });
        }

        return () => {
            if (look) window.removeEventListener('pointermove', look);
        };
    });
    // Photos change the page's height as they load; the scroll positions are measured again then.
    window.addEventListener('load', ScrollTrigger.refresh);
});
onBeforeUnmount(() => {
    window.removeEventListener('load', ScrollTrigger.refresh);
    window.removeEventListener('resize', onResize);
    document.removeEventListener('focusin', keepClear);
    clearTimeout(resized);
    watcher?.disconnect();
    slider?.stop();
    media?.revert();
});
</script>

<template>
    <Head :title="t('home.title')" />
    <main ref="root" class="catalog-page home-page">
        <LaptopWire ref="wire" />
        <StoreMasthead />

        <section :class="['hero', { 'hero--plates': plates.length }]">
            <h1 class="hero__words" :aria-label="t('home.headline')">
                <span v-for="(word, index) in words" :key="index" :class="['hero__word', `hero__word--${index}`]" aria-hidden="true"><span class="hero__word-inner">{{ word }}</span></span>
            </h1>
            <div v-if="plates.length" class="hero__plates">
                <Link v-for="(product, index) in plates" :key="product.id" :href="product.url" :class="['hero__plate', `hero__plate--${index}`]" data-transition-scope>
                    <span class="hero__plate-photo" data-transition-photo><img v-if="product.image" :src="product.image" :alt="titleOf(product)" decoding="async" /></span>
                    <span class="hero__plate-caption"><small>{{ product.brand || categoryName(product.category) }}</small><strong>{{ titleOf(product) }}</strong><em v-if="product.price !== null">{{ formatPrice(product.price, product.currency) }}</em></span>
                </Link>
            </div>
            <div class="hero__foot">
                <div class="hero__copy">
                    <p>{{ t('home.lead') }}</p>
                    <div class="hero__actions">
                        <Link href="/catalog" class="hero__cta">
                            <span class="hero__cta-label">{{ t('home.openCatalog') }}<svg viewBox="0 0 20 20" aria-hidden="true"><path d="M3 10h13m-5-5 5 5-5 5" /></svg></span>
                            <small v-if="catalogNote">{{ catalogNote }}</small>
                        </Link>
                        <HomeSearch />
                    </div>
                </div>
                <dl class="hero__stats">
                    <div v-if="stats.lowest"><dt>{{ t('home.statLowest') }}</dt><dd><DecodeText :text="formatPrice(stats.lowest.price, stats.lowest.currency)" :delay="900" /></dd></div>
                    <div><dt>{{ t('home.statProducts') }}</dt><dd><DecodeText :text="number(stats.products)" :delay="980" /></dd></div>
                    <div><dt>{{ t('home.statInStock') }}</dt><dd><DecodeText :text="number(stats.in_stock)" :delay="1060" /></dd></div>
                    <div v-if="updated"><dt>{{ t('home.statUpdated') }}</dt><dd>{{ updated }}</dd></div>
                </dl>
            </div>
        </section>

        <section class="manifesto">
            <p :aria-label="t('home.manifesto')"><span v-for="(word, index) in manifesto" :key="index" class="manifesto__word" aria-hidden="true">{{ word }}</span></p>
        </section>

        <section v-if="ways.length" class="ways">
            <article v-for="way in ways" :key="way.key" :class="['way', `way--${way.key}`]">
                <h2 class="way__word" :aria-label="way.word"><span class="way__outline" aria-hidden="true">{{ way.word }}</span><span class="way__fill" aria-hidden="true">{{ way.word }}</span></h2>
                <div class="way__body">
                    <p class="way__text">{{ way.text }}</p>
                    <p v-if="way.lowest" class="way__price"><small>{{ t('from') }}</small><span>{{ formatPrice(way.lowest.price, way.lowest.currency) }}</span></p>
                    <div class="way__go">
                        <span class="way__count">{{ productCount(way.count) }}</span>
                        <Link :href="way.href" class="way__link">{{ way.link }}<svg viewBox="0 0 20 20" aria-hidden="true"><path d="M3 10h13m-5-5 5 5-5 5" /></svg></Link>
                    </div>
                </div>
            </article>
        </section>

        <section v-if="arrivals.length" class="gallery" :aria-label="t('home.arrivals')">
            <div ref="track" class="gallery__track">
                <header class="gallery__head">
                    <h2><span v-for="(word, index) in arrivalWords" :key="index" class="gallery__head-line">{{ word }}</span></h2>
                    <Link href="/catalog">{{ t('home.openCatalog') }}</Link>
                </header>
                <Link v-for="(product, index) in arrivals" :key="product.id" :href="product.url" class="gallery__item" data-transition-scope>
                    <span class="gallery__photo" data-transition-photo><img v-if="product.image" :src="product.image" :alt="titleOf(product)" loading="lazy" decoding="async" /></span>
                    <span class="gallery__meta"><span>{{ padded(index + 1) }}</span><span>{{ product.brand || categoryName(product.category) }}</span><span :class="`stock stock--${product.stock_status}`">{{ t(`stock.${product.stock_status}`, product.stock_status) }}</span></span>
                    <strong class="gallery__title">{{ titleOf(product) }}</strong>
                    <span class="gallery__price" :class="{ 'gallery__price--none': product.price === null }">{{ formatPrice(product.price, product.currency) }}</span>
                </Link>
                <Link href="/catalog" class="gallery__more"><span>{{ t('home.catalogWord') }}</span><svg viewBox="0 0 20 20" aria-hidden="true"><path d="M3 10h13m-5-5 5 5-5 5" /></svg><small>{{ productCount(stats.products) }}</small></Link>
            </div>
            <div v-if="!(row.start && row.end)" class="gallery__bar">
                <span class="gallery__progress" aria-hidden="true"><i :style="{ transform: `scaleX(${Math.max(0.04, row.progress)})` }"></i></span>
                <button type="button" :aria-label="t('home.previous')" :disabled="row.start" @click="slider?.page(-1)"><svg viewBox="0 0 20 20" aria-hidden="true"><path d="M17 10H4m5-5-5 5 5 5" /></svg></button>
                <button type="button" :aria-label="t('home.next')" :disabled="row.end" @click="slider?.page(1)"><svg viewBox="0 0 20 20" aria-hidden="true"><path d="M3 10h13m-5-5 5 5-5 5" /></svg></button>
            </div>
        </section>
        <section v-else class="gallery gallery--empty"><p>{{ t('home.emptyBench') }}</p></section>

        <section v-if="categories.length" class="index">
            <h2 class="home-label">{{ t('home.categories') }}</h2>
            <Link v-for="category in categories" :key="category.slug" :href="catalogUrl({ category: category.slug })" class="index__row">
                <CategoryIcon :names="category.icons" />
                <span class="index__name">{{ categoryName(category) }}<sup>{{ number(category.count) }}</sup></span>
                <span v-if="category.lowest" class="index__price">{{ fromPrice(category.lowest) }}</span>
                <svg class="index__arrow" viewBox="0 0 20 20" aria-hidden="true"><path d="M3 10h13m-5-5 5 5-5 5" /></svg>
            </Link>
        </section>

        <section v-if="brands.length" class="brands" :aria-label="t('home.brands')">
            <h2 class="home-label">{{ t('home.brands') }}</h2>
            <div v-for="line in 2" :key="line" class="brands__line" :aria-hidden="line === 2 ? 'true' : undefined">
                <div class="brands__row">
                    <Link v-for="(brand, index) in brandLoop" :key="`${line}-${index}`" :href="catalogUrl({ 'brands[]': brand.name })"
                        :tabindex="line === 2 || index >= brands.length ? -1 : undefined" :aria-hidden="index >= brands.length ? 'true' : undefined">{{ brand.name }}<sup>{{ number(brand.count) }}</sup></Link>
                </div>
            </div>
        </section>

        <section class="outro">
            <ul v-if="budgets" class="outro__budgets" :aria-label="t('home.budgets')">
                <li v-for="range in budgets.ranges" :key="`${range.min}-${range.max}`">
                    <Link :href="catalogUrl(budgetQuery(range))"><strong>{{ budgetLabel(range) }}</strong><span>{{ productCount(range.count) }}</span></Link>
                </li>
            </ul>
            <p class="outro__lead">{{ t('home.justLook') }}</p>
            <Link href="/catalog" class="outro__link" :aria-label="t('home.openCatalog')"><span>{{ t('home.catalogWord') }}</span><svg viewBox="0 0 20 20" aria-hidden="true"><path d="M3 10h13m-5-5 5 5-5 5" /></svg></Link>
            <p v-if="catalogNote" class="outro__count">{{ catalogNote }}</p>
        </section>

        <footer class="catalog-footer"><Link href="/catalog">{{ t('home.openCatalog') }}</Link><AccountLink /></footer>

        <Link ref="dock" href="/catalog" :class="['home-dock', { 'home-dock--shown': dockShown }]" :aria-hidden="dockShown ? undefined : 'true'" :tabindex="dockShown ? undefined : -1">
            <span>{{ t('home.catalogWord') }}</span><svg viewBox="0 0 20 20" aria-hidden="true"><path d="M3 10h13m-5-5 5 5-5 5" /></svg>
        </Link>
    </main>
</template>
