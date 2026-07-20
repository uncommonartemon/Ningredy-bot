<script setup>
import FilterSection from './FilterSection.vue';
import { useI18n } from '../i18n';

defineProps({
    facets: { type: Object, required: true },
    mobileOpen: { type: Boolean, default: false },
    hasActive: { type: Boolean, default: false },
});

const model = defineModel({ type: Object, required: true });
defineEmits(['apply', 'reset', 'close']);
const { t, categoryName } = useI18n();

const facetTitle = (facet) => t(`facets.${facet.key}`, facet.label);
const attributeTitle = (facet) => t(`attributes.${facet.key}`, facet.label);
const optionLabel = (facetKey, option) => {
    if (facetKey === 'types') return t(`types.${option.value}`, option.label);
    if (facetKey === 'stock') return t(`stock.${option.value}`, option.label);
    return option.label;
};
</script>

<template>
    <div class="catalog-filters-root">
        <div v-if="mobileOpen" class="filter-backdrop" @click="$emit('close')" />
        <aside class="filters-panel" :class="{ 'filters-panel--open': mobileOpen }">
            <header>
                <div><strong>{{ t('filters') }}</strong><small v-if="hasActive">{{ t('filtersActive') }}</small></div>
                <button v-if="hasActive" type="button" @click="$emit('reset')">{{ t('reset') }}</button>
                <button type="button" class="filters-close" @click="$emit('close')">×</button>
            </header>

            <FilterSection v-if="facets.categories.length" :title="t('category')" :open-by-default="true">
                <label v-for="option in facets.categories" :key="option.value" class="radio-row">
                    <input v-model="model.category" type="radio" :value="option.value" @change="$emit('apply')" />
                    <span>{{ categoryName(option) }}</span><small>{{ option.count }}</small>
                </label>
            </FilterSection>

            <FilterSection v-if="facets.price.min !== null" :title="t('price')" :open-by-default="true">
                <div class="price-fields">
                    <label><span>{{ t('from') }}</span><input v-model="model.min_price" type="number" :placeholder="facets.price.min" @keyup.enter="$emit('apply')" /></label>
                    <label><span>{{ t('to') }}</span><input v-model="model.max_price" type="number" :placeholder="facets.price.max" @keyup.enter="$emit('apply')" /></label>
                </div>
                <button type="button" class="filter-apply" @click="$emit('apply')">{{ t('apply') }}</button>
            </FilterSection>

            <FilterSection
                v-for="facet in facets.columns"
                :key="facet.key"
                :title="facetTitle(facet)"
                :open-by-default="facet.key === 'brands'"
            >
                <label v-for="option in facet.options" :key="option.value" class="check-row">
                    <input v-model="model[facet.key]" type="checkbox" :value="option.value" @change="$emit('apply')" />
                    <span>{{ optionLabel(facet.key, option) }}</span><small>{{ option.count }}</small>
                </label>
            </FilterSection>

            <FilterSection v-for="facet in facets.attributes" :key="facet.key" :title="attributeTitle(facet)">
                <label v-for="option in facet.options" :key="option.value" class="check-row">
                    <input v-model="model.attributes[facet.key]" type="checkbox" :value="option.value" @change="$emit('apply')" />
                    <span>{{ option.label }}</span><small>{{ option.count }}</small>
                </label>
            </FilterSection>
        </aside>
    </div>
</template>
