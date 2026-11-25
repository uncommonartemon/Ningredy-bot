<script setup>
import { Link } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import CategoryIcon from './CategoryIcon.vue';
import { useI18n } from '../i18n';

// The kinds under the category as small tiles: its icon, its name and how many products it
// holds. A tile is a plain link to the kind, which starts its page without the filters of this
// one. The strip keeps to a few rows (the stylesheet says how many, --tile-rows) so the products
// stay near the top; the rest unfold in place.
const props = defineProps({ tiles: { type: Array, required: true } });
const { t, categoryName, productCount, moreCount, localeTag } = useI18n();
const grid = ref(null);
const capacity = ref(Infinity);
const expanded = ref(false);
let observer;
const measure = () => {
    if (!grid.value) return;
    const style = getComputedStyle(grid.value);
    const columns = style.gridTemplateColumns.split(' ').filter(Boolean).length || 1;
    capacity.value = columns * (Number.parseInt(style.getPropertyValue('--tile-rows'), 10) || 2);
};
const foldable = computed(() => props.tiles.length > capacity.value);
// The last cell of the last row is the link that unfolds the rest.
const shown = computed(() => (foldable.value && !expanded.value ? props.tiles.slice(0, capacity.value - 1) : props.tiles));
const href = (tile) => `/catalog?${new URLSearchParams({ category: tile.slug })}`;
const count = (value) => new Intl.NumberFormat(localeTag.value).format(value);
onMounted(() => {
    measure();
    observer = new ResizeObserver(measure);
    observer.observe(grid.value);
});
onBeforeUnmount(() => observer?.disconnect());
</script>

<template>
    <nav class="category-tiles" :aria-label="t('subcategories')">
        <ul ref="grid" class="category-tiles__grid">
            <li v-for="tile in shown" :key="tile.slug">
                <Link :href="href(tile)" class="category-tile" :aria-label="`${categoryName(tile)}, ${productCount(tile.count)}`">
                    <CategoryIcon :names="tile.icons" />
                    <span class="category-tile__name">{{ categoryName(tile) }}</span>
                    <span class="category-tile__count" aria-hidden="true">{{ count(tile.count) }}</span>
                </Link>
            </li>
            <li v-if="foldable">
                <button type="button" class="category-tile category-tile--more" :aria-expanded="expanded" @click="expanded = !expanded">
                    {{ expanded ? t('showLess') : moreCount(tiles.length - shown.length) }}
                </button>
            </li>
        </ul>
    </nav>
</template>
