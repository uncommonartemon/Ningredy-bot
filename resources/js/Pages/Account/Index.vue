<script setup>
import { Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import AccountShell from '../../Components/AccountShell.vue';
import { useI18n } from '../../i18n';

defineProps({ account: { type: Object, required: true } });
const { t } = useI18n();
const leaving = ref(false);
const signOut = () => {
    leaving.value = true;
    router.post('/account/logout', {}, { onFinish: () => { leaving.value = false; } });
};
</script>

<template>
    <AccountShell :title="account.name" :eyebrow="t('account.accountTitle')"
        :lead="account.group ? t('account.groupPrices') : t('account.regularPrices')">
        <dl class="account-facts">
            <div><dt>{{ t('account.email') }}</dt><dd>{{ account.email }}</dd></div>
            <div v-if="account.company"><dt>{{ t('account.companyLabel') }}</dt><dd>{{ account.company }}</dd></div>
            <div v-if="account.group"><dt>{{ t('account.group') }}</dt><dd>{{ account.group }}</dd></div>
        </dl>
        <div class="account-actions">
            <Link class="account-submit" href="/catalog">{{ t('account.toCatalog') }}</Link>
            <button class="account-secondary" type="button" :disabled="leaving" @click="signOut">{{ t('account.signOut') }}</button>
        </div>
    </AccountShell>
</template>
