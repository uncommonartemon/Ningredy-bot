<script setup>
import { computed } from 'vue';
import { CATEGORY_ICONS, categoryIcon } from '../categoryIcons';

// A category's icon from the one set. Every path is one unit long to the browser, so a
// page can draw the icon in by its stroke.
const props = defineProps({ names: { type: Array, default: () => [] } });
const paths = computed(() => CATEGORY_ICONS[categoryIcon(props.names)]);
</script>

<template>
    <svg class="category-icon" viewBox="0 0 24 24" aria-hidden="true">
        <path v-for="path in paths" :key="path" :d="path" pathLength="1" />
    </svg>
</template>
