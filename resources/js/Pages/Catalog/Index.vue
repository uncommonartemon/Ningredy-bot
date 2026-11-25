<script setup>
import { Head, Link, router, useRemember } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue';
import AccountLink from '../../Components/AccountLink.vue';
import CatalogFilters from '../../Components/CatalogFilters.vue';
import CatalogPagination from '../../Components/CatalogPagination.vue';
import CatalogToolbar from '../../Components/CatalogToolbar.vue';
import CategoryTiles from '../../Components/CategoryTiles.vue';
import ProductCard from '../../Components/ProductCard.vue';
import StoreMasthead from '../../Components/StoreMasthead.vue';
import { useI18n } from '../../i18n';
import { usePrice } from '../../price';

const props = defineProps({ products: { type: Object, required: true }, filters: { type: Object, required: true }, facets: { type: Object, required: true } });
const { t, categoryName, localeTag, facetLabel, facetOptionLabel } = useI18n();
const { formatPrice } = usePrice();
const COLUMNS = ['brands', 'types', 'countries', 'colors', 'stock', 'condition'];
const NAMED_VALUES = ['types', 'stock', 'condition'];
const defaults = () => ({ search: '', category: '', brands: [], types: [], countries: [], colors: [], stock: [], condition: [], attributes: {}, min_price: null, max_price: null, sort: 'newest', per_page: 12 });
const normalizedFilters = () => {
    const state = defaults();
    for (const key of Object.keys(state)) {
        const value = props.filters[key];
        if (value !== undefined && value !== null) state[key] = JSON.parse(JSON.stringify(value));
    }
    // Preserve selections even when their facet disappears from the returned options.
    for (const facet of props.facets.attributes) state.attributes[facet.key] ??= [];
    return state;
};
const form = reactive(normalizedFilters());
const view = useRemember('grid', 'catalog-view');
const mobileFiltersOpen = ref(false);
const loading = ref(false);
const searchPending = ref(false);
const facetsBusy = computed(() => loading.value || searchPending.value);
const requestError = ref('');
let searchTimer;
let syncing = false;
let requestId = 0;
// The category is the page: its path from the catalog is the breadcrumbs, its name the heading.
const trail = computed(() => props.facets.trail || []);
const current = computed(() => trail.value.at(-1) || null);
const heading = computed(() => categoryName(current.value) || t('catalog'));
const categoryHref = (step) => `/catalog?${new URLSearchParams({ category: step.slug })}`;
const hasValue = (value) => value !== '' && value !== null && value !== undefined;
const money = (value) => (props.facets.price.currency ? formatPrice(value, props.facets.price.currency) : new Intl.NumberFormat(localeTag.value).format(value));
const priceText = () => {
    if (hasValue(form.min_price) && hasValue(form.max_price)) return `${money(form.min_price)} – ${money(form.max_price)}`;
    return hasValue(form.min_price) ? `${t('from')} ${money(form.min_price)}` : `${t('to')} ${money(form.max_price)}`;
};
// What is chosen, one group per filter in the order of the panel: "Brand: Lenovo × ASUS ×".
const chipGroups = computed(() => {
    const groups = [];
    if (form.search) groups.push({ key: 'search', label: t('searchLabel'), chips: [{ key: 'search', value: form.search, label: form.search }] });
    if (hasValue(form.min_price) || hasValue(form.max_price)) groups.push({ key: 'price', label: t('price'), chips: [{ key: 'price', value: 'price', label: priceText() }] });
    const column = (key) => {
        if (!form[key]?.length) return;
        const facet = props.facets.columns.find((item) => item.key === key);
        groups.push({ key, label: t(`facets.${key}`, facet?.label || key), chips: form[key].map((value) => {
            const label = facet?.options.find((option) => option.value === value)?.label || value;
            return { key, value, label: NAMED_VALUES.includes(key) ? t(`${key}.${value}`, label) : label };
        }) });
    };
    const columnOrder = [...props.facets.columns.map((facet) => facet.key), ...COLUMNS].filter((key, index, keys) => keys.indexOf(key) === index);
    const leading = new Set(props.facets.columns.filter((facet) => facet.leading).map((facet) => facet.key));
    columnOrder.filter((key) => leading.has(key)).forEach(column);
    const attributeOrder = [...props.facets.attributes.map((facet) => facet.key), ...Object.keys(form.attributes)].filter((key, index, keys) => keys.indexOf(key) === index);
    for (const key of attributeOrder) {
        const values = form.attributes[key] || [];
        if (!values.length) continue;
        const facet = props.facets.attributes.find((item) => item.key === key);
        groups.push({ key: `attribute-${key}`, label: facet ? facetLabel(facet) : t(`attributes.${key}`, key), chips: values.map((value) => {
            const option = facet?.options.find((item) => item.value === value);
            return { key: 'attributes', attribute: key, value, label: option ? facetOptionLabel(option) : value };
        }) });
    }
    columnOrder.filter((key) => !leading.has(key)).forEach(column);
    return groups;
});
const hasActiveFilters = computed(() => chipGroups.value.length > 0);
const activeCount = computed(() => chipGroups.value.filter((group) => group.key !== 'search').reduce((total, group) => total + group.chips.length, 0));
const query = () => Object.fromEntries(Object.entries(form).flatMap(([key, value]) => {
    if (key === 'attributes') {
        const attributes = Object.fromEntries(Object.entries(value).filter(([, values]) => values.length));
        return Object.keys(attributes).length ? [[key, attributes]] : [];
    }
    if (Array.isArray(value)) return value.length ? [[key, [...value]]] : [];
    return hasValue(value) ? [[key, value]] : [];
}));
const applyFilters = () => {
    clearTimeout(searchTimer);
    searchPending.value = false;
    requestError.value = '';
    const currentRequest = ++requestId;
    loading.value = true;
    router.get('/catalog', query(), {
        preserveState: true, preserveScroll: true, replace: true, only: ['products', 'filters', 'facets'],
        onError: () => {
            if (requestId !== currentRequest) return;
            requestError.value = t('filterError');
            syncing = true;
            const pendingSearch = searchPending.value ? form.search : null;
            Object.assign(form, normalizedFilters());
            if (pendingSearch !== null) form.search = pendingSearch;
            syncing = false;
        },
        onFinish: () => { if (requestId === currentRequest) loading.value = false; },
    });
};
const changeFilters = (callback) => {
    syncing = true;
    clearTimeout(searchTimer);
    searchPending.value = false;
    callback();
    syncing = false;
    applyFilters();
};
// Resetting clears what was chosen on this page; the category, the sorting and the page size stay.
const resetFilters = () => changeFilters(() => {
    const attributes = Object.fromEntries(props.facets.attributes.map((facet) => [facet.key, []]));
    Object.assign(form, defaults(), { category: form.category, sort: form.sort, per_page: form.per_page, attributes });
});
const removeFilter = (chip) => changeFilters(() => {
    if (chip.attribute) form.attributes[chip.attribute] = form.attributes[chip.attribute].filter((value) => value !== chip.value);
    else if (chip.key === 'price') Object.assign(form, { min_price: null, max_price: null });
    else if (Array.isArray(form[chip.key])) form[chip.key] = form[chip.key].filter((value) => value !== chip.value);
    else form[chip.key] = '';
});
watch(() => form.search, () => {
    if (syncing) return;
    clearTimeout(searchTimer);
    searchPending.value = true;
    searchTimer = setTimeout(applyFilters, 350);
}, { flush: 'sync' });
watch(() => [props.filters, props.facets], () => {
    syncing = true;
    // A response to the previous search must not discard text typed in the meantime.
    const pendingSearch = searchPending.value ? form.search : null;
    if (pendingSearch === null) clearTimeout(searchTimer);
    Object.assign(form, normalizedFilters());
    if (pendingSearch !== null) form.search = pendingSearch;
    syncing = false;
}, { flush: 'sync' });
onBeforeUnmount(() => clearTimeout(searchTimer));
</script>

<template>
    <Head :title="heading" />
    <main class="catalog-page">
        <a class="skip-link" href="#catalog-results">{{ t('skipToProducts') }}</a>
        <StoreMasthead />
        <nav class="catalog-breadcrumbs" :aria-label="t('breadcrumbs')">
            <ol>
                <li><Link href="/">{{ t('home.link') }}</Link></li>
                <li v-if="!current"><span aria-current="page">{{ t('catalog') }}</span></li>
                <template v-else>
                    <li><Link href="/catalog">{{ t('catalog') }}</Link></li>
                    <li v-for="step in trail.slice(0, -1)" :key="step.slug"><Link :href="categoryHref(step)">{{ categoryName(step) }}</Link></li>
                    <li><span aria-current="page">{{ heading }}</span></li>
                </template>
            </ol>
        </nav>
        <div class="catalog-layout">
            <CatalogFilters v-model="form" :facets="facets" :mobile-open="mobileFiltersOpen" :has-active="hasActiveFilters" :total="products.total" :busy="facetsBusy" @apply="applyFilters" @reset="resetFilters" @close="mobileFiltersOpen = false" />
            <div class="catalog-main">
                <div class="catalog-heading">
                    <h1>{{ heading }}</h1>
                    <label class="catalog-search"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="10.5" cy="10.5" r="6.5" /><path d="m16 16 4 4" /></svg><span class="sr-only">{{ t('searchLabel') }}</span><input v-model="form.search" type="search" :placeholder="t('search')" maxlength="120" @keydown.enter.prevent="applyFilters" /></label>
                </div>
                <div v-if="hasActiveFilters" class="active-filters" role="group" :aria-label="t('selectedFilters')">
                    <div v-for="group in chipGroups" :key="group.key" class="chip-group">
                        <span class="chip-group__label">{{ group.label }}:</span>
                        <button v-for="chip in group.chips" :key="`${chip.key}-${chip.attribute || ''}-${chip.value}`" type="button" class="filter-chip" :aria-label="`${t('removeFilter')}: ${group.label}: ${chip.label}`" @click="removeFilter(chip)">{{ chip.label }} <span aria-hidden="true">×</span></button>
                    </div>
                    <button type="button" class="clear-filters" @click="resetFilters">{{ t('resetFilters') }}</button>
                </div>
                <CategoryTiles v-if="facets.tiles?.length" :key="current?.slug || ''" :tiles="facets.tiles" />
                <p v-if="requestError" class="catalog-error" role="alert">{{ requestError }}</p>
                <section id="catalog-results" class="products-stage" :class="{ loading }" :aria-label="t('catalog')" :aria-busy="loading" tabindex="-1">
                    <CatalogToolbar v-model="form" :view="view" :total="products.total" :active-count="activeCount" :filters-open="mobileFiltersOpen" @apply="applyFilters" @change-view="view = $event" @open-filters="mobileFiltersOpen = true" />
                    <div class="products-stage__results">
                        <span v-if="loading" class="catalog-updating" role="status">{{ t('updating') }}</span>
                        <Transition name="catalog-view" mode="out-in">
                            <TransitionGroup v-if="products.data.length" :key="view" name="catalog-cards" tag="div" appear :class="['products-grid', `products-grid--${view}`]">
                                <ProductCard v-for="(product, index) in products.data" :key="product.id" :style="{ '--stagger': Math.min(index, 7) }" :product="product" :view="view" />
                            </TransitionGroup>
                        </Transition>
                        <div v-if="!products.data.length" class="empty-state">
                            <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="10" cy="10" r="6" /><path d="m15 15 6 6M7 10h6" /></svg>
                            <h2>{{ t('emptyTitle') }}</h2><p>{{ t('emptyText') }}</p><button v-if="hasActiveFilters" type="button" @click="resetFilters">{{ t('resetFilters') }}</button>
                        </div>
                    </div>
                    <CatalogPagination v-if="products.last_page > 1" :links="products.links" />
                </section>
            </div>
        </div>
        <footer class="catalog-footer"><span>{{ t('catalog') }}</span><AccountLink /></footer>
    </main>
</template>
