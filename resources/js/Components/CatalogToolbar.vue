<script setup>
import { useI18n } from '../i18n';
defineProps({ view: { type: String, required: true }, activeCount: { type: Number, default: 0 }, filtersOpen: { type: Boolean, default: false } });
const model = defineModel({ type: Object, required: true });
defineEmits(['apply', 'change-view', 'open-filters']);
const { t } = useI18n();
</script>
<template>
    <div class="catalog-toolbar">
        <label class="catalog-search"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="10.5" cy="10.5" r="6.5" /><path d="m16 16 4 4" /></svg><span class="sr-only">{{ t('searchLabel') }}</span><input v-model="model.search" type="search" :placeholder="t('search')" maxlength="120" @keydown.enter.prevent="$emit('apply')" /></label>
        <div class="catalog-toolbar__actions">
            <button type="button" class="mobile-filter-button" :class="{ active: activeCount }" :aria-expanded="filtersOpen" aria-controls="catalog-filters" @click="$emit('open-filters')"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 6h16M7 12h10M10 18h4" /></svg>{{ t('filters') }}<span v-if="activeCount">{{ activeCount }}</span></button>
            <select v-model="model.sort" :aria-label="t('sorting')" @change="$emit('apply')"><option value="newest">{{ t('sortNewest') }}</option><option value="price_asc">{{ t('sortPriceAsc') }}</option><option value="price_desc">{{ t('sortPriceDesc') }}</option><option value="title">{{ t('sortTitle') }}</option></select>
            <select v-model.number="model.per_page" class="per-page-select" :aria-label="t('perPage')" @change="$emit('apply')"><option :value="12">12 {{ t('perPage') }}</option><option :value="24">24 {{ t('perPage') }}</option><option :value="50">50 {{ t('perPage') }}</option></select>
            <div class="view-switcher" role="group" :aria-label="t('viewMode')">
                <button type="button" :class="{ active: view === 'grid' }" :aria-pressed="view === 'grid'" :aria-label="t('grid')" @click="$emit('change-view', 'grid')"><svg viewBox="0 0 20 20" aria-hidden="true"><rect x="2" y="2" width="6" height="6" /><rect x="12" y="2" width="6" height="6" /><rect x="2" y="12" width="6" height="6" /><rect x="12" y="12" width="6" height="6" /></svg></button>
                <button type="button" :class="{ active: view === 'list' }" :aria-pressed="view === 'list'" :aria-label="t('list')" @click="$emit('change-view', 'list')"><svg viewBox="0 0 20 20" aria-hidden="true"><path d="M3 4h2M8 4h9M3 10h2M8 10h9M3 16h2M8 16h9" /></svg></button>
            </div>
        </div>
    </div>
</template>