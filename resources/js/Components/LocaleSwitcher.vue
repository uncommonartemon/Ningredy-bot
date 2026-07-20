<script setup>
import { useI18n } from '../i18n';

const { locale, setLocale, t } = useI18n();
</script>

<template>
    <div class="locale-switcher" role="group" :aria-label="t('language')">
        <button type="button" :class="{ active: locale === 'cs' }" aria-label="Čeština" @click="setLocale('cs')">CZ</button>
        <button type="button" :class="{ active: locale === 'uk' }" aria-label="Українська" @click="setLocale('uk')">UA</button>
        <button type="button" :class="{ active: locale === 'en' }" aria-label="English" @click="setLocale('en')">EN</button>
    </div>
</template>
