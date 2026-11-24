<script setup>
import { Head, Link } from '@inertiajs/vue3';
import StoreMasthead from './StoreMasthead.vue';
import { useI18n } from '../i18n';
import '../../css/account.css';

// The frame the account pages share: the catalog's own masthead and footer around one panel.
defineProps({ title: { type: String, required: true }, eyebrow: { type: String, default: '' }, lead: { type: String, default: '' } });
const { t } = useI18n();
</script>

<template>
    <Head :title="title" />
    <main class="catalog-page account-page">
        <StoreMasthead />
        <div class="account-page__body">
            <section class="account-panel">
                <p v-if="eyebrow" class="account-panel__eyebrow">{{ eyebrow }}</p>
                <h1>{{ title }}</h1>
                <p v-if="lead" class="account-panel__lead">{{ lead }}</p>
                <slot />
            </section>
        </div>
        <footer class="catalog-footer"><Link href="/catalog">{{ t('account.toCatalog') }} <span aria-hidden="true">↗</span></Link></footer>
    </main>
</template>
