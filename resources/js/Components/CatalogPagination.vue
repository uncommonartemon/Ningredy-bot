<script setup>
import { Link } from '@inertiajs/vue3';
import { useI18n } from '../i18n';

defineProps({ links: { type: Array, required: true } });
const { t } = useI18n();
</script>

<template>
    <nav class="pagination" :aria-label="t('pagination')">
        <Link
            v-for="link in links"
            :key="link.label"
            :href="link.url || '#'"
            :class="{ active: link.active, disabled: !link.url }"
            preserve-state
            v-html="link.label.replace('Previous', '‹').replace('Next', '›')"
        />
    </nav>
</template>
