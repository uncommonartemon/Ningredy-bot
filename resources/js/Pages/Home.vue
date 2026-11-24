<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import AccountLink from '../Components/AccountLink.vue';
import HomeBench from '../Components/Home/HomeBench.vue';
import HomeSearch from '../Components/Home/HomeSearch.vue';
import ProductRail from '../Components/Home/ProductRail.vue';
import StoreMasthead from '../Components/StoreMasthead.vue';
import { useI18n } from '../i18n';
import { usePrice } from '../price';
import '../../css/home.css';

// The start page (decision of 2026-11-23): one strong first screen with a real product and
// the search, then ways into the catalog built from what the catalog holds right now.
const props = defineProps({
    bench: { type: Array, required: true },
    arrivals: { type: Array, required: true },
    categories: { type: Array, required: true },
    brands: { type: Array, required: true },
    budgets: { type: Object, default: null },
    stats: { type: Object, required: true },
});
const { t, localeTag, categoryName, productCount } = useI18n();
const { formatPrice } = usePrice();
const number = (value) => new Intl.NumberFormat(localeTag.value).format(value);
const updated = computed(() => (props.stats.updated_at
    ? new Intl.DateTimeFormat(localeTag.value, { day: 'numeric', month: 'long' }).format(new Date(props.stats.updated_at))
    : null));
const catalogUrl = (query) => `/catalog?${new URLSearchParams(query)}`;
const budgetLabel = (range) => {
    const currency = props.budgets.currency;
    if (range.min === null) return `${t('to')} ${formatPrice(range.max, currency)}`;
    if (range.max === null) return `${t('from')} ${formatPrice(range.min, currency)}`;
    return `${formatPrice(range.min, currency)} – ${formatPrice(range.max, currency)}`;
};
const budgetQuery = (range) => Object.fromEntries([['min_price', range.min], ['max_price', range.max]].filter(([, value]) => value !== null));
</script>

<template>
    <Head :title="t('home.title')" />
    <main class="catalog-page home-page">
        <StoreMasthead />
        <div class="home-hero">
            <div class="home-hero__copy">
                <h1>{{ t('home.headline') }}</h1>
                <p class="home-hero__lead">{{ t('home.lead') }}</p>
                <HomeSearch />
                <nav v-if="categories.length" class="home-hero__categories" :aria-label="t('category')">
                    <Link v-for="category in categories" :key="category.slug" :href="catalogUrl({ category: category.slug })">
                        {{ categoryName(category) }} <span>{{ number(category.count) }}</span>
                    </Link>
                </nav>
                <dl class="home-status">
                    <div><dt>{{ t('home.statProducts') }}</dt><dd>{{ number(stats.products) }}</dd></div>
                    <div><dt>{{ t('home.statInStock') }}</dt><dd>{{ number(stats.in_stock) }}</dd></div>
                    <div v-if="stats.brands"><dt>{{ t('home.statBrands') }}</dt><dd>{{ number(stats.brands) }}</dd></div>
                    <div v-if="updated"><dt>{{ t('home.statUpdated') }}</dt><dd>{{ updated }}</dd></div>
                </dl>
            </div>
            <HomeBench :products="bench" />
        </div>

        <ProductRail v-if="arrivals.length" :title="t('home.arrivals')" :products="arrivals" />

        <section v-if="categories.length" class="home-section">
            <header class="home-section__head"><h2>{{ t('home.categories') }}</h2></header>
            <div class="home-categories">
                <Link v-for="category in categories" :key="category.slug" :href="catalogUrl({ category: category.slug })" class="home-category">
                    <span class="home-category__plate"><img v-if="category.image" :src="category.image" alt="" loading="lazy" decoding="async" /></span>
                    <span class="home-category__name">{{ categoryName(category) }}</span>
                    <span class="home-category__count">{{ productCount(category.count) }}</span>
                </Link>
            </div>
        </section>

        <section v-if="brands.length || budgets" class="home-section home-split">
            <div v-if="brands.length">
                <header class="home-section__head"><h2>{{ t('home.brands') }}</h2></header>
                <ul class="home-brands">
                    <li v-for="brand in brands" :key="brand.name"><Link :href="catalogUrl({ 'brands[]': brand.name })">{{ brand.name }} <span>{{ number(brand.count) }}</span></Link></li>
                </ul>
            </div>
            <div v-if="budgets">
                <header class="home-section__head"><h2>{{ t('home.budgets') }}</h2></header>
                <ul class="home-budgets">
                    <li v-for="range in budgets.ranges" :key="`${range.min}-${range.max}`">
                        <Link :href="catalogUrl(budgetQuery(range))"><strong>{{ budgetLabel(range) }}</strong><span>{{ productCount(range.count) }}</span><svg viewBox="0 0 20 20" aria-hidden="true"><path d="M3 10h13m-5-5 5 5-5 5" /></svg></Link>
                    </li>
                </ul>
            </div>
        </section>

        <footer class="catalog-footer"><Link href="/catalog">{{ t('home.openCatalog') }}</Link><AccountLink /></footer>
    </main>
</template>
