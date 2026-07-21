<script setup>
import { Head, router } from '@inertiajs/vue3';
import { gsap } from 'gsap';
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import CatalogFilters from '../../Components/CatalogFilters.vue';
import CatalogPagination from '../../Components/CatalogPagination.vue';
import CatalogToolbar from '../../Components/CatalogToolbar.vue';
import ProductCard from '../../Components/ProductCard.vue';
import { useI18n } from '../../i18n';

const props = defineProps({
    products: { type: Object, required: true },
    filters: { type: Object, required: true },
    facets: { type: Object, required: true },
});

const initialFilters = () => ({
    search: '', category: '', brands: [], types: [], countries: [], colors: [], stock: [],
    attributes: {}, min_price: null, max_price: null, sort: 'newest', per_page: 12,
    ...props.filters,
    attributes: Object.fromEntries(props.facets.attributes.map((facet) => [
        facet.key, props.filters.attributes?.[facet.key] || [],
    ])),
});

const form = reactive(initialFilters());
const view = ref('grid');
const mobileFiltersOpen = ref(false);
const loading = ref(false);
const page = ref(null);
let context;
let searchTimer;
const { t } = useI18n();

const hasActiveFilters = computed(() => Boolean(
    form.search || form.category || form.brands.length || form.types.length || form.countries.length ||
    form.colors.length || form.stock.length || form.min_price || form.max_price ||
    Object.values(form.attributes).some((values) => values.length),
));

const query = () => Object.fromEntries(Object.entries(form).filter(([, value]) => {
    if (Array.isArray(value)) return value.length;
    if (value && typeof value === 'object') return Object.values(value).some((items) => items.length);
    return value !== '' && value !== null && value !== undefined;
}));

const applyFilters = () => {
    loading.value = true;
    gsap.to('.products-stage', { opacity: .32, y: 8, duration: .22, ease: 'power2.out' });
    router.get('/catalog', query(), {
        preserveState: true,
        preserveScroll: true,
        replace: true,
        only: ['products', 'filters', 'facets'],
        onFinish: () => {
            loading.value = false;
            gsap.to('.products-stage', { opacity: 1, y: 0, duration: .48, ease: 'power3.out', clearProps: 'transform' });
        },
    });
};

const changeView = async (nextView) => {
    if (view.value === nextView) return;
    view.value = nextView;
    await nextTick();
    gsap.fromTo('.product-card', { opacity: 0, scale: .975, y: 12 }, {
        opacity: 1, scale: 1, y: 0, duration: .48, stagger: .045, ease: 'power3.out', clearProps: 'transform',
    });
};

const resetFilters = () => {
    Object.assign(form, initialFilters(), {
        search: '', category: '', brands: [], types: [], countries: [], colors: [], stock: [],
        min_price: null, max_price: null, sort: 'newest', per_page: 12,
    });
    Object.keys(form.attributes).forEach((key) => { form.attributes[key] = []; });
    mobileFiltersOpen.value = false;
    applyFilters();
};

watch(() => form.search, () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(applyFilters, 400);
});

// leaving cards are absolutely positioned by the CSS transition,
// so freeze their box to keep the grid from jumping
const freezeLeavingCard = (element) => {
    element.style.width = `${element.offsetWidth}px`;
    element.style.height = `${element.offsetHeight}px`;
};

onMounted(() => {
    context = gsap.context(() => {
        const timeline = gsap.timeline({ defaults: { ease: 'power3.out' } });
        timeline
            .from('.catalog-toolbar__title > *', { opacity: 0, x: -20, duration: .55, stagger: .07 })
            .from('.catalog-search', { opacity: 0, y: -12, scale: .98, duration: .5 }, '-=.38')
            .from('.catalog-toolbar__actions > *', { opacity: 0, x: 15, duration: .45, stagger: .08 }, '-=.4')
            .from('.filters-panel', { opacity: 0, x: -24, duration: .62, clearProps: 'transform' }, '-=.25');

        const filterSections = gsap.utils.toArray('.filter-section');
        if (filterSections.length) {
            timeline.from(filterSections, { opacity: 0, x: -10, duration: .36, stagger: .035, clearProps: 'transform' }, '-=.42');
        }
    }, page.value);
});

onBeforeUnmount(() => {
    clearTimeout(searchTimer);
    context?.revert();
});
</script>

<template>
    <Head :title="t('pageTitle')" />

    <main ref="page" class="catalog-page">
        <span class="ambient-glow ambient-glow--cyan" aria-hidden="true" />
        <span class="ambient-glow ambient-glow--violet" aria-hidden="true" />
        <span class="catalog-loading-line" :class="{ active: loading }" aria-hidden="true" />
        <CatalogToolbar
            v-model="form"
            :total="products.total"
            :view="view"
            :has-active="hasActiveFilters"
            @apply="applyFilters"
            @change-view="changeView"
            @open-filters="mobileFiltersOpen = true"
        />

        <div class="catalog-layout">
            <CatalogFilters
                v-model="form"
                :facets="facets"
                :mobile-open="mobileFiltersOpen"
                :has-active="hasActiveFilters"
                @apply="applyFilters"
                @reset="resetFilters"
                @close="mobileFiltersOpen = false"
            />

            <section class="products-stage" :class="{ loading }">
                <TransitionGroup
                    v-if="products.data.length"
                    name="products"
                    tag="div"
                    :class="['products-grid', `products-grid--${view}`]"
                    @leave="freezeLeavingCard"
                >
                    <ProductCard
                        v-for="(product, index) in products.data"
                        :key="product.id"
                        :style="{ '--stagger': Math.min(index, 10) }"
                        :product="product"
                        :view="view"
                    />
                </TransitionGroup>

                <div v-else class="empty-state">
                    <h2>{{ t('emptyTitle') }}</h2>
                    <p>{{ t('emptyText') }}</p>
                    <button type="button" @click="resetFilters">{{ t('resetFilters') }}</button>
                </div>

                <CatalogPagination v-if="products.last_page > 1" :links="products.links" />
            </section>
        </div>
    </main>
</template>
