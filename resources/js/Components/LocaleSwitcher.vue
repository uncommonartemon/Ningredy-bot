<script setup>
import { useI18n } from '../i18n';

const { locale, setLocale, t, storefrontLocales } = useI18n();

// The buttons are the shop's languages, chosen in the admin («Языки каталога»).
const buttons = { cs: { short: 'CZ', name: 'Čeština' }, uk: { short: 'UA', name: 'Українська' }, en: { short: 'EN', name: 'English' } };
</script>

<template>
    <div v-if="storefrontLocales.length > 1" class="locale-switcher" role="group" :aria-label="t('language')">
        <button v-for="code in storefrontLocales" :key="code" type="button" :class="{ active: locale === code }" :aria-pressed="locale === code"
            :aria-label="buttons[code]?.name || code" @click="setLocale(code)">{{ buttons[code]?.short || code.toUpperCase() }}</button>
    </div>
</template>
