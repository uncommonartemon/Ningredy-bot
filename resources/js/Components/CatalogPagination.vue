<script setup>
import { Link } from '@inertiajs/vue3';
import { useI18n } from '../i18n';
defineProps({ links: { type: Array, required: true } });
const { t } = useI18n();
const label = (link, index, count) => index === 0 ? t('previousPage') : index === count - 1 ? t('nextPage') : link.label;
</script>
<template>
    <nav class="pagination" :aria-label="t('pagination')">
        <template v-for="(link, index) in links" :key="index"><Link v-if="link.url && !link.active" :href="link.url" preserve-state>{{ label(link, index, links.length) }}</Link><span v-else :class="link.active ? 'active' : 'disabled'" :aria-current="link.active ? 'page' : undefined" :aria-disabled="!link.active || undefined">{{ label(link, index, links.length) }}</span></template>
    </nav>
</template>
