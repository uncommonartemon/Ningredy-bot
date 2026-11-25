<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { gsap } from 'gsap';
import { ScrollTrigger } from 'gsap/ScrollTrigger';
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue';
import AccountLink from '../Components/AccountLink.vue';
import DecodeText from '../Components/DecodeText.vue';
import HomeSearch from '../Components/Home/HomeSearch.vue';
import LaptopWire from '../Components/Home/LaptopWire.vue';
import StoreMasthead from '../Components/StoreMasthead.vue';
import { useI18n } from '../i18n';
import { usePrice } from '../price';
import '../../css/home.css';

gsap.registerPlugin(ScrollTrigger);

// The start page (decisions of 2026-11-23 and 2026-11-25): a scene tied to the scroll. A laptop
// drawn in lines stays behind the page; scrolling opens it, takes it apart and closes it again
// while the page says what the shop does (buy, sell, repair) and leads into the catalog.
// Everything shown comes from the catalog as it is.
const props = defineProps({
    bench: { type: Array, required: true },
    arrivals: { type: Array, required: true },
    categories: { type: Array, required: true },
    brands: { type: Array, required: true },
    budgets: { type: Object, default: null },
    stats: { type: Object, required: true },
});
const { t, localeTag, categoryName, productCount, translatedText } = useI18n();
const { formatPrice } = usePrice();
const root = ref(null);
const wire = ref(null);
const fan = ref(null);
const fanActive = ref(-1);
const number = (value) => new Intl.NumberFormat(localeTag.value).format(value);
const padded = (value) => String(value).padStart(2, '0');
const updated = computed(() => (props.stats.updated_at
    ? new Intl.DateTimeFormat(localeTag.value, { day: 'numeric', month: 'long' }).format(new Date(props.stats.updated_at))
    : null));
const titleOf = (item) => translatedText(item.translations, 'title') || item.title;
const catalogUrl = (query) => `/catalog?${new URLSearchParams(query)}`;
const words = computed(() => [t('home.buy'), t('home.sell'), t('home.repair')]);
const manifesto = computed(() => t('home.manifesto').split(' '));
const services = computed(() => [
    { key: 'buy', word: t('home.buy'), text: t('home.buyText'), href: '/catalog', action: t('home.openCatalog') },
    { key: 'sell', word: t('home.sell'), text: t('home.sellText') },
    { key: 'repair', word: t('home.repair'), text: t('home.repairText') },
]);
const plates = computed(() => props.bench.slice(0, 3));
// The row of brands runs in a loop, so it is written out enough times to fill any screen twice.
const brandLoop = computed(() => (props.brands.length ? Array.from({ length: Math.max(2, Math.ceil(16 / props.brands.length)) * 2 }, () => props.brands).flat() : []));
const budgetLabel = (range) => {
    const currency = props.budgets.currency;
    if (range.min === null) return `${t('to')} ${formatPrice(range.max, currency)}`;
    if (range.max === null) return `${t('from')} ${formatPrice(range.min, currency)}`;
    return `${formatPrice(range.min, currency)} – ${formatPrice(range.max, currency)}`;
};
const budgetQuery = (range) => Object.fromEntries([['min_price', range.min], ['max_price', range.max]].filter(([, value]) => value !== null));

// Photos of a category fan out beside the pointer while it is over that category's row.
let fanX;
let fanY;
const canHover = () => window.matchMedia('(hover: hover) and (pointer: fine)').matches;
const showFan = (index, event) => {
    if (!canHover() || !fan.value) return;
    if (fanActive.value === -1) gsap.set(fan.value, { x: event.clientX, y: event.clientY });
    fanActive.value = index;
};
const moveFan = (event) => {
    if (fanActive.value === -1) return;
    fanX?.(event.clientX);
    fanY?.(event.clientY);
};
const hideFan = () => { fanActive.value = -1; };

let media;
onMounted(async () => {
    await nextTick();
    const scope = root.value;
    const pose = wire.value.pose;
    const render = wire.value.render;
    const find = (selector) => scope.querySelector(selector);
    if (fan.value) {
        fanX = gsap.quickTo(fan.value, 'x', { duration: 0.45, ease: 'power3' });
        fanY = gsap.quickTo(fan.value, 'y', { duration: 0.45, ease: 'power3' });
    }

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

        // The newest products pass sideways while the page is held in place. Created before
        // everything else, so the scenes below are measured with the held distance included.
        const gallery = find('.gallery');
        const track = find('.gallery__track');
        if (gallery && track && wide) {
            gallery.classList.add('gallery--pinned');
            const distance = () => Math.max(0, track.scrollWidth - window.innerWidth);
            gsap.to(track, { x: () => -distance(), ease: 'none',
                scrollTrigger: { trigger: gallery, start: 'top top', end: () => `+=${distance()}`, pin: true, scrub: 0.6, invalidateOnRefresh: true, anticipatePin: 1 } });
        }

        // The one entrance: the lid opens while the three words rise into place.
        pose.open = 0;
        pose.fade = 0;
        gsap.timeline({ defaults: { ease: 'power3.out' }, onUpdate: render })
            .to(pose, { fade: 1, duration: 0.6 }, 0)
            .to(pose, { open: 106, duration: 1.5, ease: 'power2.inOut' }, 0.05)
            .from('.hero__word-inner', { yPercent: 108, duration: 0.9, stagger: 0.1 }, 0.05)
            .from('.hero__plate', { autoAlpha: 0, scale: 0.86, duration: 0.7, stagger: 0.09 }, 0.55)
            .from('.hero__foot > *', { autoAlpha: 0, y: 18, duration: 0.5, stagger: 0.08 }, 0.6);

        // From here on the scroll is the clock.
        gsap.timeline({ defaults: { ease: 'none' }, scrollTrigger: { trigger: '.hero', start: 'top top', end: 'bottom top', scrub: 0.6 } })
            .to('.hero__word--0', { xPercent: -7 }, 0)
            .to('.hero__word--1', { xPercent: 9 }, 0)
            .to('.hero__word--2', { xPercent: -4 }, 0)
            .to('.hero__plate', { y: (index) => -(index + 1) * (wide ? 110 : 40) }, 0)
            .to('.hero__foot', { autoAlpha: 0, y: -50 }, 0);

        const turn = (trigger, start, end, from, to) => {
            if (!find(trigger)) return;
            gsap.fromTo(pose, from, { ...to, ease: 'none', immediateRender: false, onUpdate: render, scrollTrigger: { trigger, start, end, scrub: 0.8 } });
        };
        const side = wide ? 0.27 : 0;
        turn('.hero', 'top top', 'bottom top', { yaw: -0.62, pitch: 0.36, alpha: 1, ...rest }, { yaw: -1.05, pitch: 0.52, alpha: 0.5, x: 0, y: 0, scale: 1 });
        turn('.manifesto', 'top 80%', 'bottom 40%', { explode: 0, yaw: -1.05 }, { explode: 1, yaw: -0.25 });
        turn('.services', 'top 70%', 'bottom 30%', { yaw: -0.25, pitch: 0.52, alpha: 0.5 }, { yaw: 0.85, pitch: 0.3, alpha: 0.3 });
        turn('.gallery', 'top 90%', 'top 20%', { alpha: 0.3 }, { alpha: 0.05 });
        turn('.index', 'top 90%', 'top 30%', { alpha: 0.05, x: 0, yaw: 0.85 }, { alpha: 0.32, x: side, yaw: 1.5 });
        turn('.outro', 'top 95%', 'bottom bottom', { explode: 1, close: 0, x: side, yaw: 1.5, pitch: 0.3, alpha: 0.32, scale: 1 },
            { explode: 0, close: 1, x: 0, yaw: Math.PI * 2 - 0.62, pitch: 0.44, alpha: 0.75, scale: 1.25 });

        gsap.fromTo('.manifesto__word', { opacity: 0.14 }, { opacity: 1, stagger: 0.1, ease: 'none',
            scrollTrigger: { trigger: '.manifesto', start: 'top 72%', end: 'bottom 58%', scrub: true } });

        gsap.utils.toArray('.service', scope).forEach((service, index) => {
            gsap.timeline({ defaults: { ease: 'none' }, scrollTrigger: { trigger: service, start: 'top 88%', end: 'top 28%', scrub: 0.5 } })
                .fromTo(service.querySelector('.service__fill'), { clipPath: 'inset(-20% 100% -20% 0)' }, { clipPath: 'inset(-20% 0% -20% 0)' }, 0)
                .fromTo(service.querySelector('.service__word'), { xPercent: (index % 2 ? 1 : -1) * (wide ? 7 : 3) }, { xPercent: 0 }, 0)
                .fromTo(service.querySelector('.service__body'), { autoAlpha: 0.12, y: 26 }, { autoAlpha: 1, y: 0 }, 0.35);
        });

        gsap.utils.toArray('.index__row', scope).forEach((row, index) => {
            gsap.fromTo(row, { xPercent: index % 2 ? 7 : 3, autoAlpha: 0.2 }, { xPercent: 0, autoAlpha: 1, ease: 'none',
                scrollTrigger: { trigger: row, start: 'top 96%', end: 'top 62%', scrub: 0.5 } });
        });

        // The brands run by themselves and take their speed and direction from the scroll.
        const rows = gsap.utils.toArray('.brands__row', scope);
        const runs = rows.map((row, index) => gsap.fromTo(row, { xPercent: index % 2 ? -50 : 0 }, { xPercent: index % 2 ? 0 : -50, duration: 46, ease: 'none', repeat: -1 }));
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
            gallery?.classList.remove('gallery--pinned');
        };
    });
    // Photos change the page's height as they load; the scroll positions are measured again then.
    window.addEventListener('load', ScrollTrigger.refresh);
});
onBeforeUnmount(() => {
    window.removeEventListener('load', ScrollTrigger.refresh);
    media?.revert();
});
</script>

<template>
    <Head :title="t('home.title')" />
    <main ref="root" class="catalog-page home-page">
        <LaptopWire ref="wire" />
        <StoreMasthead />

        <section class="hero">
            <h1 class="hero__words" :aria-label="words.join(', ')">
                <span v-for="(word, index) in words" :key="word" :class="['hero__word', `hero__word--${index}`]" aria-hidden="true"><span class="hero__word-inner">{{ word }}</span></span>
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
                    <HomeSearch />
                </div>
                <dl class="hero__stats">
                    <div><dt>{{ t('home.statProducts') }}</dt><dd><DecodeText :text="number(stats.products)" :delay="900" /></dd></div>
                    <div><dt>{{ t('home.statInStock') }}</dt><dd><DecodeText :text="number(stats.in_stock)" :delay="980" /></dd></div>
                    <div v-if="stats.brands"><dt>{{ t('home.statBrands') }}</dt><dd><DecodeText :text="number(stats.brands)" :delay="1060" /></dd></div>
                    <div v-if="updated"><dt>{{ t('home.statUpdated') }}</dt><dd>{{ updated }}</dd></div>
                </dl>
            </div>
        </section>

        <section class="manifesto">
            <p :aria-label="t('home.manifesto')"><span v-for="(word, index) in manifesto" :key="index" class="manifesto__word" aria-hidden="true">{{ word }}</span></p>
        </section>

        <section class="services">
            <article v-for="service in services" :key="service.key" class="service">
                <h2 class="service__word" :aria-label="service.word"><span class="service__outline" aria-hidden="true">{{ service.word }}</span><span class="service__fill" aria-hidden="true">{{ service.word }}</span></h2>
                <div class="service__body">
                    <p>{{ service.text }}</p>
                    <Link v-if="service.href" :href="service.href" class="service__link">{{ service.action }}<svg viewBox="0 0 20 20" aria-hidden="true"><path d="M3 10h13m-5-5 5 5-5 5" /></svg></Link>
                </div>
            </article>
        </section>

        <section v-if="arrivals.length" class="gallery" :aria-label="t('home.arrivals')">
            <div class="gallery__track">
                <header class="gallery__head">
                    <h2>{{ t('home.arrivals') }}</h2>
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
        </section>
        <section v-else class="gallery gallery--empty"><p>{{ t('home.emptyBench') }}</p></section>

        <section v-if="categories.length" class="index" @pointermove="moveFan" @pointerleave="hideFan">
            <h2 class="home-label">{{ t('home.categories') }}</h2>
            <Link v-for="(category, index) in categories" :key="category.slug" :href="catalogUrl({ category: category.slug })" class="index__row"
                @pointerenter="showFan(index, $event)" @focus="hideFan">
                <span class="index__name">{{ categoryName(category) }}<sup>{{ number(category.count) }}</sup></span>
                <span class="index__thumbs" aria-hidden="true"><img v-for="image in category.images.slice(0, 3)" :key="image" :src="image" alt="" loading="lazy" decoding="async" /></span>
                <svg class="index__arrow" viewBox="0 0 20 20" aria-hidden="true"><path d="M3 10h13m-5-5 5 5-5 5" /></svg>
            </Link>
            <div ref="fan" class="index__fan" aria-hidden="true">
                <div v-for="(category, index) in categories" :key="category.slug" class="index__fan-group" :class="{ active: index === fanActive }">
                    <span v-for="(image, position) in category.images" :key="image" :style="{ '--i': position, '--n': category.images.length }"><img :src="image" alt="" loading="lazy" decoding="async" /></span>
                </div>
            </div>
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
            <Link href="/catalog" class="outro__link" :aria-label="t('home.openCatalog')"><span>{{ t('home.catalogWord') }}</span><svg viewBox="0 0 20 20" aria-hidden="true"><path d="M3 10h13m-5-5 5 5-5 5" /></svg></Link>
            <p class="outro__count">{{ productCount(stats.products) }}</p>
        </section>

        <footer class="catalog-footer"><Link href="/catalog">{{ t('home.openCatalog') }}</Link><AccountLink /></footer>
    </main>
</template>
