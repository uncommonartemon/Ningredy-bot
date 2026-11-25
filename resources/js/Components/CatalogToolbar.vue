<script setup>
import { computed } from 'vue';
import { useI18n } from '../i18n';

// The line above the products: the sorting as tabs with the count beside them, the page size
// and the view. A phone has no room for tabs: one row holds the sorting as a list and the
// button that opens the filters.
const props = defineProps({ view: { type: String, required: true }, total: { type: Number, default: 0 }, activeCount: { type: Number, default: 0 }, filtersOpen: { type: Boolean, default: false } });
const model = defineModel({ type: Object, required: true });
const emit = defineEmits(['apply', 'change-view', 'open-filters']);
const { t, productCount } = useI18n();
const sorts = computed(() => [
    { value: 'newest', label: t('sortNewest') },
    { value: 'price_asc', label: t('sortPriceAsc') },
    { value: 'price_desc', label: t('sortPriceDesc') },
    { value: 'title', label: t('sortTitle') },
]);
const sortBy = (value) => {
    if (model.value.sort === value) return;
    model.value.sort = value;
    emit('apply');
};
const filtersLabel = computed(() => (props.activeCount ? `${t('filters')} (${props.activeCount})` : t('filters')));
</script>

<template>
    <div class="catalog-toolbar">
        <div class="sort-tabs" role="group" :aria-label="t('sorting')">
            <button v-for="sort in sorts" :key="sort.value" type="button" :class="{ active: model.sort === sort.value }" :aria-pressed="model.sort === sort.value" @click="sortBy(sort.value)">{{ sort.label }}</button>
        </div>
        <p class="catalog-count" role="status" aria-live="polite">{{ productCount(total) }}</p>
        <div class="catalog-toolbar__view">
            <select v-model.number="model.per_page" class="per-page-select" :aria-label="t('perPage')" @change="$emit('apply')"><option :value="12">12 {{ t('perPage') }}</option><option :value="24">24 {{ t('perPage') }}</option><option :value="50">50 {{ t('perPage') }}</option></select>
            <div class="view-switcher" role="group" :aria-label="t('viewMode')">
                <button type="button" :class="{ active: view === 'grid' }" :aria-pressed="view === 'grid'" :aria-label="t('grid')" @click="$emit('change-view', 'grid')"><svg viewBox="0 0 20 20" aria-hidden="true"><rect x="2" y="2" width="6" height="6" /><rect x="12" y="2" width="6" height="6" /><rect x="2" y="12" width="6" height="6" /><rect x="12" y="12" width="6" height="6" /></svg></button>
                <button type="button" :class="{ active: view === 'list' }" :aria-pressed="view === 'list'" :aria-label="t('list')" @click="$emit('change-view', 'list')"><svg viewBox="0 0 20 20" aria-hidden="true"><path d="M3 4h2M8 4h9M3 10h2M8 10h9M3 16h2M8 16h9" /></svg></button>
            </div>
        </div>
        <div class="catalog-toolbar__phone">
            <label class="sort-select"><span class="sr-only">{{ t('sorting') }}</span><select v-model="model.sort" @change="$emit('apply')"><option v-for="sort in sorts" :key="sort.value" :value="sort.value">{{ sort.label }}</option></select></label>
            <button type="button" class="mobile-filter-button" :class="{ active: activeCount }" :aria-expanded="filtersOpen" aria-controls="catalog-filters" @click="$emit('open-filters')"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 6h16M7 12h10M10 18h4" /></svg>{{ filtersLabel }}</button>
        </div>
    </div>
</template>
