<script setup>
import { Head, Link, router, useRemember } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue';
import CatalogFilters from '../../Components/CatalogFilters.vue';
import CatalogPagination from '../../Components/CatalogPagination.vue';
import CatalogToolbar from '../../Components/CatalogToolbar.vue';
import LocaleSwitcher from '../../Components/LocaleSwitcher.vue';
import ProductCard from '../../Components/ProductCard.vue';
import { useI18n } from '../../i18n';

const props = defineProps({ products: { type: Object, required: true }, filters: { type: Object, required: true }, facets: { type: Object, required: true } });
const { t, categoryName, productCount, facetLabel, facetOptionLabel } = useI18n();
const defaults = () => ({ search: '', category: '', brands: [], types: [], countries: [], colors: [], stock: [], attributes: {}, min_price: null, max_price: null, sort: 'newest', per_page: 12 });
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
const selectedCategory = computed(() => props.facets.categories.find((item) => item.value === form.category));
const heading = computed(() => categoryName(selectedCategory.value) || t('catalog'));
const hasValue = (value) => value !== '' && value !== null && value !== undefined;
const selectedFilters = computed(() => {
    const chips = [];
    if (form.search) chips.push({ key: 'search', value: form.search, label: `${t('searchLabel')}: ${form.search}` });
    if (form.category) chips.push({ key: 'category', value: form.category, label: categoryName(selectedCategory.value, form.category) });
    for (const key of ['brands', 'types', 'countries', 'colors', 'stock']) {
        const facet = props.facets.columns.find((item) => item.key === key);
        for (const value of form[key]) {
            const label = facet?.options.find((option) => option.value === value)?.label || value;
            chips.push({ key, value, label: t(`${key}.${value}`, label) });
        }
    }
    for (const [key, values] of Object.entries(form.attributes)) {
        const facet = props.facets.attributes.find((item) => item.key === key);
        for (const value of values) {
            const option = facet?.options.find((item) => item.value === value);
            chips.push({ key: 'attributes', attribute: key, value, label: `${facet ? facetLabel(facet) : t(`attributes.${key}`, key)}: ${option ? facetOptionLabel(option) : value}` });
        }
    }
    for (const key of ['min_price', 'max_price']) {
        if (hasValue(form[key])) chips.push({ key, value: form[key], label: `${t('price')} ${t(key === 'min_price' ? 'from' : 'to')} ${form[key]}` });
    }
    return chips;
});
const hasActiveFilters = computed(() => selectedFilters.value.length > 0);
const categoryDisabled = (count, isSelected = false) => facetsBusy.value || (!isSelected && Number(count) === 0);
const categoryCount = (count) => facetsBusy.value ? '…' : (count ?? 0);
const categoryCountHint = (count) => facetsBusy.value ? t('updatingFilters') : `${t('afterFilterChange')}: ${productCount(count ?? 0)}`;
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
const resetFilters = () => changeFilters(() => {
    const attributes = Object.fromEntries(props.facets.attributes.map((facet) => [facet.key, []]));
    Object.assign(form, defaults(), { attributes });
});
const selectCategory = (value) => changeFilters(() => { form.category = value; });
const removeFilter = (chip) => changeFilters(() => {
    if (chip.attribute) form.attributes[chip.attribute] = form.attributes[chip.attribute].filter((value) => value !== chip.value);
    else if (Array.isArray(form[chip.key])) form[chip.key] = form[chip.key].filter((value) => value !== chip.value);
    else form[chip.key] = chip.key.endsWith('_price') ? null : '';
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
        <header class="catalog-masthead">
            <nav :aria-label="t('navigation')"><Link href="/catalog" aria-current="page">{{ t('catalog') }}</Link></nav>
            <LocaleSwitcher />
        </header>
        <div class="catalog-heading">
            <div><p class="catalog-eyebrow">NINGREDY / {{ t('tech') }}</p><h1>{{ heading }}</h1></div>
            <p class="catalog-count" role="status" aria-live="polite">{{ productCount(products.total) }}</p>
        </div>
        <nav v-if="facets.categories.length" class="category-tabs" :aria-label="t('category')">
            <button type="button" :class="{ active: !form.category }" :aria-pressed="!form.category" :disabled="facetsBusy" @click="selectCategory('')">{{ t('allCategories') }} <span v-if="form.category" :aria-label="categoryCountHint(facets.allCategoriesCount)" :title="categoryCountHint(facets.allCategoriesCount)">({{ categoryCount(facets.allCategoriesCount) }})</span></button>
            <button v-for="category in facets.categories" :key="category.value" type="button" :class="{ active: form.category === category.value }" :aria-pressed="form.category === category.value" :disabled="categoryDisabled(category.count, form.category === category.value)" @click="selectCategory(category.value)">{{ categoryName(category) }} <span v-if="form.category !== category.value" :aria-label="categoryCountHint(category.count)" :title="categoryCountHint(category.count)">({{ categoryCount(category.count) }})</span></button>
        </nav>
        <CatalogToolbar v-model="form" :view="view" :active-count="selectedFilters.length" :filters-open="mobileFiltersOpen" @apply="applyFilters" @change-view="view = $event" @open-filters="mobileFiltersOpen = true" />
        <div v-if="hasActiveFilters" class="active-filters" :aria-label="t('selectedFilters')">
            <button v-for="chip in selectedFilters" :key="`${chip.key}-${chip.attribute || ''}-${chip.value}`" type="button" class="filter-chip" :aria-label="`${t('removeFilter')}: ${chip.label}`" @click="removeFilter(chip)">{{ chip.label }} <span aria-hidden="true">×</span></button>
            <button type="button" class="clear-filters" @click="resetFilters">{{ t('resetFilters') }}</button>
        </div>
        <p v-if="requestError" class="catalog-error" role="alert">{{ requestError }}</p>
        <div class="catalog-layout">
            <CatalogFilters v-model="form" :facets="facets" :mobile-open="mobileFiltersOpen" :has-active="hasActiveFilters" :total="products.total" :busy="facetsBusy" @apply="applyFilters" @category="selectCategory" @reset="resetFilters" @close="mobileFiltersOpen = false" />
            <section id="catalog-results" class="products-stage" :class="{ loading }" :aria-label="t('catalog')" :aria-busy="loading" tabindex="-1">
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
                <CatalogPagination v-if="products.last_page > 1" :links="products.links" />
            </section>
        </div>
        <footer class="catalog-footer"><span>{{ t('catalog') }}</span></footer>
    </main>
</template>
