<script setup>
import { Link, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AccountShell from '../../Components/AccountShell.vue';
import { accountError } from '../../accountErrors';
import { useI18n } from '../../i18n';

const props = defineProps({ invitation: { type: Object, required: true } });
const { t } = useI18n();
const form = useForm({ name: '', email: '', company: '', phone: '', password: '', password_confirmation: '' });
const mismatch = ref(false);
const errorOf = (field) => accountError(form.errors[field], t);
const passwordError = computed(() => (mismatch.value ? t('account.passwordMismatch') : errorOf('password')));
const submit = () => {
    mismatch.value = form.password !== form.password_confirmation;
    if (mismatch.value) return;
    form.post(`/account/join/${props.invitation.code}`, { preserveScroll: true, onError: () => form.reset('password', 'password_confirmation') });
};
</script>

<template>
    <AccountShell :title="t('account.joinTitle')" :eyebrow="invitation.group" :lead="t('account.joinLead')">
        <form class="account-form" @submit.prevent="submit">
            <label class="account-field"><span>{{ t('account.name') }}</span>
                <input v-model="form.name" type="text" name="name" autocomplete="name" maxlength="120" required autofocus>
                <small v-if="errorOf('name')" class="account-field__error" role="alert">{{ errorOf('name') }}</small></label>
            <label class="account-field"><span>{{ t('account.email') }}</span>
                <input v-model="form.email" type="email" name="email" autocomplete="username" maxlength="255" required>
                <small v-if="errorOf('email')" class="account-field__error" role="alert">{{ errorOf('email') }}</small></label>
            <label class="account-field"><span>{{ t('account.company') }}</span>
                <input v-model="form.company" type="text" name="company" autocomplete="organization" maxlength="160"></label>
            <label class="account-field"><span>{{ t('account.phone') }}</span>
                <input v-model="form.phone" type="tel" name="phone" autocomplete="tel" maxlength="40"></label>
            <label class="account-field"><span>{{ t('account.password') }}</span>
                <input v-model="form.password" type="password" name="password" autocomplete="new-password" minlength="8" maxlength="255" required>
                <small class="account-field__hint">{{ t('account.passwordHint') }}</small></label>
            <label class="account-field"><span>{{ t('account.passwordConfirm') }}</span>
                <input v-model="form.password_confirmation" type="password" name="password_confirmation" autocomplete="new-password" minlength="8" maxlength="255" required>
                <small v-if="passwordError" class="account-field__error" role="alert">{{ passwordError }}</small></label>
            <button class="account-submit" type="submit" :disabled="form.processing">{{ t('account.join') }}</button>
        </form>
        <p class="account-note"><Link href="/account/login">{{ t('account.haveAccount') }}</Link></p>
    </AccountShell>
</template>
