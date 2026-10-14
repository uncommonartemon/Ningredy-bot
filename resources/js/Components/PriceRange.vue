<script setup>
import { computed, ref } from 'vue';
import { useI18n } from '../i18n';

const props = defineProps({
    minBound: { type: Number, default: null },
    maxBound: { type: Number, default: null },
    disabled: { type: Boolean, default: false },
});
const minValue = defineModel('min', { default: '' });
const maxValue = defineModel('max', { default: '' });
defineEmits(['apply']);
const { t, localeTag } = useI18n();
const activeHandle = ref('max');
const hasBounds = computed(() => props.minBound !== null && props.maxBound !== null
    && Number.isFinite(props.minBound) && Number.isFinite(props.maxBound)
    && props.minBound >= 0 && props.maxBound >= props.minBound);
const adjustable = computed(() => hasBounds.value && props.maxBound > props.minBound);
const numeric = (value) => value !== '' && value !== null && value !== undefined && Number.isFinite(Number(value)) ? Number(value) : null;
const clamp = (value) => Math.min(props.maxBound, Math.max(props.minBound, value));
// Empty and out-of-range limits stay unchanged in the form. Only the thumb's
// position is clamped to the available prices; moving one thumb writes one limit.
const lower = computed(() => hasBounds.value ? clamp(numeric(minValue.value) ?? props.minBound) : null);
const upper = computed(() => hasBounds.value ? clamp(numeric(maxValue.value) ?? props.maxBound) : null);
const percentage = (value) => adjustable.value ? (value - props.minBound) / (props.maxBound - props.minBound) * 100 : 50;
const start = computed(() => Math.min(percentage(lower.value), percentage(upper.value)));
const end = computed(() => Math.max(percentage(lower.value), percentage(upper.value)));
const closeHandles = computed(() => adjustable.value && end.value - start.value < 16);
const outsideRange = computed(() => hasBounds.value && [minValue.value, maxValue.value].some((value) => {
    const number = numeric(value);
    return number !== null && (number < props.minBound || number > props.maxBound);
}));
const format = (value) => new Intl.NumberFormat(localeTag.value, { maximumFractionDigits: 2 }).format(value);
const move = (handle, event) => {
    if (!adjustable.value || props.disabled) return;
    let next = clamp(Number(event.target.value));
    const other = numeric(handle === 'min' ? maxValue.value : minValue.value);
    if (handle === 'min' && other !== null && other >= props.minBound) next = Math.min(next, other);
    if (handle === 'max' && other !== null && other <= props.maxBound) next = Math.max(next, other);
    next = Number(next.toFixed(2));
    if (handle === 'min') minValue.value = next;
    else maxValue.value = next;
    // A rejected crossing can leave the reactive value unchanged, so restore
    // the native thumb as well instead of letting it visually cross the other.
    event.target.value = String(clamp(next));
};
</script>

<template>
    <div class="price-range" :class="{ 'price-range--disabled': disabled || !adjustable, 'price-range--close': closeHandles }" role="group" :aria-label="t('priceRange')" :aria-disabled="disabled || !adjustable">
        <div class="price-range__control" :style="{ '--range-start': `${start}%`, '--range-end': `${end}%` }">
            <div class="price-range__rail" aria-hidden="true"><span v-if="hasBounds" class="price-range__selection" /></div>
            <template v-if="adjustable">
                <input type="range" class="price-range__input price-range__input--min" :class="{ 'price-range__input--active': activeHandle === 'min' }" :min="minBound" :max="maxBound" step="0.01" :value="lower" :disabled="disabled" :aria-label="t('minimumPrice')" @focus="activeHandle = 'min'" @pointerdown="activeHandle = 'min'" @input="move('min', $event)" @keydown.enter.prevent="$emit('apply')" />
                <input type="range" class="price-range__input price-range__input--max" :class="{ 'price-range__input--active': activeHandle === 'max' }" :min="minBound" :max="maxBound" step="0.01" :value="upper" :disabled="disabled" :aria-label="t('maximumPrice')" @focus="activeHandle = 'max'" @pointerdown="activeHandle = 'max'" @input="move('max', $event)" @keydown.enter.prevent="$emit('apply')" />
            </template>
            <span v-else-if="hasBounds" class="price-range__single" aria-hidden="true" />
        </div>
        <div v-if="hasBounds" class="price-range__scale" :class="{ 'price-range__scale--single': !adjustable }"><span>{{ format(minBound) }}</span><span v-if="adjustable">{{ format(maxBound) }}</span></div>
        <p v-if="outsideRange" class="filter-hint">{{ t('priceOutsideRange') }}</p>
    </div>
</template>
