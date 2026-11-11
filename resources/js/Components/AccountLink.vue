<script setup>
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useI18n } from '../i18n';

// The only door to the accounts (operator, 2026-11-11): a quiet link in the footer. A
// signed-in customer sees their name and group there instead.
const { t } = useI18n();
const customer = computed(() => usePage().props.customer);
</script>

<template>
    <Link :href="customer ? '/account' : '/account/login'" class="account-link">
        <template v-if="customer">{{ customer.name }}<span v-if="customer.group" class="account-link__group">{{ customer.group }}</span></template>
        <template v-else>{{ t('account.partnerLogin') }}</template>
    </Link>
</template>
