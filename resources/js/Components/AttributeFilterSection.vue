<script setup>
import { computed, ref } from 'vue';
import FilterSection from './FilterSection.vue';
import { useI18n } from '../i18n';

// One section of checkboxes: a characteristic, or a column filter (brand, colour, availability)
// that brings its own title and option names.
const props = defineProps({ facet: { type: Object, required: true }, busy: { type: Boolean, default: false }, headingLevel: { type: Number, default: 3 }, title: { type: String, default: '' }, optionLabel: { type: Function, default: null }, openByDefault: { type: Boolean, default: false } });
const values = defineModel({ type: Array, required: true });
defineEmits(['apply']);
const { t, productCount, moreCount, facetLabel, facetOptionLabel } = useI18n();
const heading = computed(() => props.title || facetLabel(props.facet));
const optionName = (option) => props.optionLabel ? props.optionLabel(option) : facetOptionLabel(option);
const selected = (value) => values.value?.includes(value) || false;
const unavailable = (option) => !selected(option.value) && Number(option.count) === 0;
const countHint = (count) => props.busy ? t('updatingFilters') : `${t('afterFilterChange')}: ${productCount(count ?? 0)}`;
// A long list shows five and unfolds in place; folding one more would hide less than the link
// takes. A ticked option stays in sight wherever it stands, and the five are the first that can
// still be chosen: a greyed-out option never hides one that can. All keep the list's order.
const FIRST = 5;
const expanded = ref(false);
const foldable = computed(() => props.facet.options.length > FIRST + 1);
const shown = computed(() => {
    const options = props.facet.options;
    if (!foldable.value || expanded.value) return options;
    const kept = new Set(options.filter((option) => selected(option.value)));
    for (const option of [...options.filter((item) => !unavailable(item)), ...options.filter(unavailable)]) {
        if (kept.size >= FIRST) break;
        kept.add(option);
    }
    return options.filter((option) => kept.has(option));
});
const folded = computed(() => props.facet.options.length - shown.value.length);
</script>

<template>
    <FilterSection :title="heading" :open-by-default="openByDefault" :selected-count="values?.length || 0" :selection-key="JSON.stringify(values || [])" :heading-level="headingLevel">
        <label v-for="option in shown" :key="option.value" class="check-row" :class="{ 'filter-option--unavailable': unavailable(option) }">
            <input v-model="values" type="checkbox" :value="option.value" :disabled="busy || unavailable(option)" @change="$emit('apply')" />
            <span>{{ optionName(option) }}</span>
            <small v-if="!selected(option.value)" :aria-label="countHint(option.count)" :title="countHint(option.count)">({{ busy ? '…' : (option.count ?? 0) }})</small>
        </label>
        <button v-if="foldable && (expanded || folded > 0)" type="button" class="filter-more" :aria-expanded="expanded" @click="expanded = !expanded">
            {{ expanded ? t('showLess') : moreCount(folded) }}<svg viewBox="0 0 20 20" aria-hidden="true"><path :d="expanded ? 'm5 12.5 5-5 5 5' : 'm5 7.5 5 5 5-5'" /></svg>
        </button>
    </FilterSection>
</template>
