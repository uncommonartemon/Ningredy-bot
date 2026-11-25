<script setup>
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import LocaleSwitcher from './LocaleSwitcher.vue';
import { useI18n } from '../i18n';

// The header every storefront page shares: home and the catalog, without a logo (decision of 2026-10-11).
const { t } = useI18n();
const path = computed(() => usePage().url.split('?')[0]);
</script>

<template>
    <header class="catalog-masthead">
        <nav :aria-label="t('navigation')">
            <Link href="/" :aria-current="path === '/' ? 'page' : undefined">{{ t('home.link') }}</Link>
            <Link href="/catalog" :aria-current="path === '/catalog' ? 'page' : undefined">{{ t('catalog') }}</Link>
        </nav>
        <LocaleSwitcher />
    </header>
</template>
