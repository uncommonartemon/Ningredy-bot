<script setup>
import { useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import AccountShell from '../../Components/AccountShell.vue';
import { accountError } from '../../accountErrors';
import { useI18n } from '../../i18n';

const { t } = useI18n();
const form = useForm({ email: '', password: '' });
const error = computed(() => accountError(form.errors.email || form.errors.password, t));
const submit = () => form.post('/account/login', { preserveScroll: true, onFinish: () => form.reset('password') });
</script>

<template>
    <AccountShell :title="t('account.signInTitle')" :eyebrow="t('account.partners')" :lead="t('account.signInLead')">
        <form class="account-form" @submit.prevent="submit">
            <label class="account-field"><span>{{ t('account.email') }}</span>
                <input v-model="form.email" type="email" name="email" autocomplete="username" required autofocus></label>
            <label class="account-field"><span>{{ t('account.password') }}</span>
                <input v-model="form.password" type="password" name="password" autocomplete="current-password" required></label>
            <p v-if="error" class="account-field__error" role="alert">{{ error }}</p>
            <button class="account-submit" type="submit" :disabled="form.processing">{{ t('account.signIn') }}</button>
        </form>
        <p class="account-note">{{ t('account.inviteOnly') }}</p>
    </AccountShell>
</template>
