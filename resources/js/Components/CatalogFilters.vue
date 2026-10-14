<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import FilterSection from './FilterSection.vue';
import AttributeFilterSection from './AttributeFilterSection.vue';
import PriceRange from './PriceRange.vue';
import { useI18n } from '../i18n';
import { groupAttributeBlocks } from '../attributeGroups';
const props = defineProps({ facets: { type: Object, required: true }, mobileOpen: { type: Boolean, default: false }, hasActive: { type: Boolean, default: false }, total: { type: Number, default: 0 }, busy: { type: Boolean, default: false } });
const model = defineModel({ type: Object, required: true });
const emit = defineEmits(['apply', 'reset', 'close', 'category']);
const { t, categoryName, productCount, attributeGroupName } = useI18n();
const panel = ref(null);
const isMobile = ref(false);
let viewport;
let previousFocus;
let previousOverflow;
let focusFrame;
const facetTitle = (facet) => t(`facets.${facet.key}`, facet.label);
const attributeBlocks = computed(() => groupAttributeBlocks(props.facets.attributes.filter((facet) => facet.options?.length)));
const groupSelectedCount = (block) => block.attributes.reduce((total, facet) => total + (model.value.attributes[facet.key]?.length || 0), 0);
const groupSelectionKey = (block) => JSON.stringify(block.attributes.map((facet) => [facet.key, model.value.attributes[facet.key] || []]));
const optionLabel = (key, option) => ['types', 'stock'].includes(key) ? t(`${key}.${option.value}`, option.label) : option.label;
const selected = (values, value) => values?.includes(value) || false;
const unavailable = (count, isSelected = false) => !isSelected && Number(count) === 0;
const optionDisabled = (count, isSelected = false) => props.busy || unavailable(count, isSelected);
const countText = (count) => props.busy ? '…' : (count ?? 0);
const countHint = (count) => props.busy ? t('updatingFilters') : `${t('afterFilterChange')}: ${productCount(count ?? 0)}`;
const hasPrice = (value) => value !== '' && value !== null && value !== undefined;
const priceDraft = reactive({ min: model.value.min_price ?? '', max: model.value.max_price ?? '' });
const priceActive = computed(() => hasPrice(model.value.min_price) || hasPrice(model.value.max_price));
const priceDirty = computed(() => String(priceDraft.min ?? '') !== String(model.value.min_price ?? '') || String(priceDraft.max ?? '') !== String(model.value.max_price ?? ''));
const priceTitle = computed(() => props.facets.price.currency ? `${t('price')} · ${props.facets.price.currency}` : t('price'));
const applyPrice = () => {
    if (props.busy || !priceDirty.value) return;
    let min = hasPrice(priceDraft.min) ? Number(priceDraft.min) : null;
    let max = hasPrice(priceDraft.max) ? Number(priceDraft.max) : null;
    if ([min, max].some((value) => value !== null && (!Number.isFinite(value) || value < 0))) return;
    if (min !== null && max !== null && min > max) [min, max] = [max, min];
    model.value.min_price = min;
    model.value.max_price = max;
    priceDraft.min = min ?? '';
    priceDraft.max = max ?? '';
    emit('apply');
};
const clearPrice = () => {
    priceDraft.min = '';
    priceDraft.max = '';
    if (!priceActive.value) return;
    model.value.min_price = null;
    model.value.max_price = null;
    emit('apply');
};
watch(() => [model.value.min_price, model.value.max_price, props.facets], () => {
    priceDraft.min = model.value.min_price ?? '';
    priceDraft.max = model.value.max_price ?? '';
});
const releaseFocus = () => {
    cancelAnimationFrame(focusFrame);
    if (previousOverflow !== undefined) document.body.style.overflow = previousOverflow;
    previousOverflow = undefined;
    if (previousFocus?.isConnected) previousFocus.focus();
    previousFocus = null;
};
const keydown = (event) => {
    if (!props.mobileOpen || !isMobile.value) return;
    if (event.key === 'Escape') { event.preventDefault(); emit('close'); return; }
    if (event.key !== 'Tab') return;
    const controls = [...panel.value.querySelectorAll('button, input, select, a[href], [tabindex="0"]')].filter((item) => !item.disabled && item.getClientRects().length);
    const first = controls[0];
    const last = controls.at(-1);
    if (!panel.value.contains(document.activeElement)) { event.preventDefault(); (event.shiftKey ? last : first)?.focus(); }
    else if (event.shiftKey && (document.activeElement === first || document.activeElement === panel.value)) { event.preventDefault(); last?.focus(); }
    else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first?.focus(); }
};
const resize = () => {
    isMobile.value = viewport.matches;
    if (!isMobile.value && props.mobileOpen) emit('close');
};
watch(() => props.mobileOpen, async (open) => {
    if (open && isMobile.value) {
        previousFocus = document.activeElement;
        previousOverflow = document.body.style.overflow;
        document.body.style.overflow = 'hidden';
        await nextTick();
        // Let the drawer become visible before moving focus into it.
        focusFrame = requestAnimationFrame(() => {
            focusFrame = requestAnimationFrame(() => {
                if (props.mobileOpen && isMobile.value) panel.value?.querySelector('.filters-close')?.focus();
            });
        });
    } else releaseFocus();
});
onMounted(() => { viewport = window.matchMedia('(max-width: 900px)'); resize(); viewport.addEventListener('change', resize); document.addEventListener('keydown', keydown); });
onBeforeUnmount(() => { viewport?.removeEventListener('change', resize); document.removeEventListener('keydown', keydown); releaseFocus(); });
</script>
<template>
    <div class="catalog-filters-root">
        <div v-if="mobileOpen && isMobile" class="filter-backdrop" aria-hidden="true" @click="$emit('close')" />
        <aside id="catalog-filters" ref="panel" class="filters-panel" :class="{ 'filters-panel--open': mobileOpen }" :role="mobileOpen && isMobile ? 'dialog' : undefined" :aria-modal="mobileOpen && isMobile ? true : undefined" :aria-busy="busy" aria-labelledby="filters-title" :inert="isMobile && !mobileOpen" tabindex="-1">
            <header><strong id="filters-title">{{ t('filters') }}</strong><button v-if="hasActive" type="button" class="filters-reset" @click="$emit('reset')">{{ t('reset') }}</button><button type="button" class="filters-close" :aria-label="t('closeFilters')" @click="$emit('close')">×</button></header>
            <FilterSection v-if="facets.categories.length" :title="t('category')" :open-by-default="true" :selected-count="model.category ? 1 : 0">
                <label class="radio-row"><input :checked="!model.category" type="radio" name="catalog-category" value="" :disabled="busy" @change="$emit('category', '')" /><span>{{ t('allCategories') }}</span><small v-if="model.category" :aria-label="countHint(facets.allCategoriesCount)" :title="countHint(facets.allCategoriesCount)">({{ countText(facets.allCategoriesCount) }})</small></label>
                <label v-for="option in facets.categories" :key="option.value" class="radio-row" :class="{ 'filter-option--unavailable': unavailable(option.count, model.category === option.value) }"><input :checked="model.category === option.value" type="radio" name="catalog-category" :value="option.value" :disabled="optionDisabled(option.count, model.category === option.value)" @change="$emit('category', option.value)" /><span>{{ categoryName(option) }}</span><small v-if="model.category !== option.value" :aria-label="countHint(option.count)" :title="countHint(option.count)">({{ countText(option.count) }})</small></label>
            </FilterSection>
            <FilterSection :title="priceTitle" :open-by-default="true" :selected-count="priceActive ? 1 : 0">
                <form @submit.prevent="applyPrice">
                    <PriceRange v-model:min="priceDraft.min" v-model:max="priceDraft.max" :min-bound="facets.price.min" :max-bound="facets.price.max" :disabled="busy" @apply="applyPrice" />
                    <div class="price-fields">
                        <label><span>{{ t('from') }}</span><input v-model="priceDraft.min" type="number" min="0" step="any" inputmode="decimal" :disabled="busy" :placeholder="facets.price.min === null ? '0' : String(facets.price.min)" :aria-label="t('minimumPrice')" /></label>
                        <label><span>{{ t('to') }}</span><input v-model="priceDraft.max" type="number" min="0" step="any" inputmode="decimal" :disabled="busy" :placeholder="facets.price.max === null ? '—' : String(facets.price.max)" :aria-label="t('maximumPrice')" /></label>
                    </div>
                    <p v-if="facets.price.min === null" class="filter-hint">{{ t('noPricedProducts') }}</p>
                    <div class="price-actions"><button type="submit" class="filter-apply" :disabled="busy || !priceDirty">{{ t('apply') }}</button><button v-if="priceActive || priceDirty" type="button" class="filter-price-clear" :disabled="busy" @click="clearPrice">{{ t('clearPrice') }}</button></div>
                </form>
            </FilterSection>
            <FilterSection v-for="facet in facets.columns" :key="facet.key" :title="facetTitle(facet)" :open-by-default="facet.key === 'brands' || facet.key === 'stock'" :selected-count="model[facet.key]?.length || 0"><label v-for="option in facet.options" :key="option.value" class="check-row" :class="{ 'filter-option--unavailable': unavailable(option.count, selected(model[facet.key], option.value)) }"><input v-model="model[facet.key]" type="checkbox" :value="option.value" :disabled="optionDisabled(option.count, selected(model[facet.key], option.value))" @change="$emit('apply')" /><span>{{ optionLabel(facet.key, option) }}</span><small v-if="!selected(model[facet.key], option.value)" :aria-label="countHint(option.count)" :title="countHint(option.count)">({{ countText(option.count) }})</small></label></FilterSection>
            <template v-for="block in attributeBlocks" :key="block.key">
                <FilterSection v-if="block.group?.highlight_in_filters" class="filter-section--attribute-group" :title="attributeGroupName(block.group)" :selected-count="groupSelectedCount(block)" :selected-label="t('selectedOptions')" :selection-key="groupSelectionKey(block)">
                    <AttributeFilterSection v-for="facet in block.attributes" :key="facet.key" v-model="model.attributes[facet.key]" :facet="facet" :busy="busy" :heading-level="4" @apply="$emit('apply')" />
                </FilterSection>
                <template v-else><AttributeFilterSection v-for="facet in block.attributes" :key="facet.key" v-model="model.attributes[facet.key]" :facet="facet" :busy="busy" @apply="$emit('apply')" /></template>
            </template>
            <p class="filters-count-hint" role="status">{{ busy ? t('updatingFilters') : t('filterCountsHint') }}</p>
            <footer class="filters-mobile-footer"><button type="button" @click="$emit('close')">{{ busy ? t('updating') : `${t('showResults')} · ${productCount(total)}` }}</button></footer>
        </aside>
    </div>
</template>
