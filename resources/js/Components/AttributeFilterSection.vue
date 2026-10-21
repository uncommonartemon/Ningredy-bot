<script setup>
import { computed } from 'vue';
import FilterSection from './FilterSection.vue';
import { useI18n } from '../i18n';

const props = defineProps({ facet: { type: Object, required: true }, busy: { type: Boolean, default: false }, headingLevel: { type: Number, default: 3 } });
const values = defineModel({ type: Array, required: true });
defineEmits(['apply']);
const { t, productCount, facetLabel, facetOptionLabel } = useI18n();
const title = computed(() => facetLabel(props.facet));
const selected = (value) => values.value?.includes(value) || false;
const unavailable = (option) => !selected(option.value) && Number(option.count) === 0;
const countHint = (count) => props.busy ? t('updatingFilters') : `${t('afterFilterChange')}: ${productCount(count ?? 0)}`;
</script>

<template>
    <FilterSection :title="title" :selected-count="values?.length || 0" :selection-key="JSON.stringify(values || [])" :heading-level="headingLevel">
        <label v-for="option in facet.options" :key="option.value" class="check-row" :class="{ 'filter-option--unavailable': unavailable(option) }">
            <input v-model="values" type="checkbox" :value="option.value" :disabled="busy || unavailable(option)" @change="$emit('apply')" />
            <span>{{ facetOptionLabel(option) }}</span>
            <small v-if="!selected(option.value)" :aria-label="countHint(option.count)" :title="countHint(option.count)">({{ busy ? '…' : (option.count ?? 0) }})</small>
        </label>
    </FilterSection>
</template>
