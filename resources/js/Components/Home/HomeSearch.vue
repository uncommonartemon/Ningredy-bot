<script setup>
import { router } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useI18n } from '../../i18n';
import { usePrice } from '../../price';

// The start page's search works like a launcher: answers while typing, arrows to move,
// Enter opens the highlighted product or the whole catalog list for the same words.
const { t, translatedText } = useI18n();
const { formatPrice } = usePrice();
const query = ref('');
const items = ref([]);
const total = ref(0);
const open = ref(false);
const busy = ref(false);
const active = ref(-1);
const input = ref(null);
let timer;
let controller;

const term = computed(() => query.value.trim());
const titleOf = (item) => translatedText(item.translations, 'title') || item.title;
const showList = computed(() => open.value && term.value.length >= 2);

const fetchSuggestions = async () => {
    controller?.abort();
    if (term.value.length < 2) { items.value = []; total.value = 0; busy.value = false; return; }
    controller = new AbortController();
    busy.value = true;
    try {
        const { data } = await axios.get('/search/suggest', { params: { q: term.value }, signal: controller.signal });
        items.value = data.items;
        total.value = data.total;
        active.value = -1;
    } catch (error) {
        if (axios.isCancel(error)) return;
        items.value = [];
        total.value = 0;
    }
    busy.value = false;
};

watch(query, () => {
    open.value = true;
    clearTimeout(timer);
    timer = setTimeout(fetchSuggestions, 180);
});

const openCatalog = () => router.get('/catalog', term.value ? { search: term.value } : {});
const submit = () => {
    const item = items.value[active.value];
    if (item) router.visit(item.url);
    else openCatalog();
};
// The highlight runs through the products and back to the field itself (-1).
const move = (step) => {
    const count = items.value.length;
    if (!count) return;
    open.value = true;
    const next = active.value + step;
    active.value = next < -1 ? count - 1 : next >= count ? -1 : next;
};
const close = () => { open.value = false; active.value = -1; };

// "/" anywhere on the page puts the cursor here, unless the visitor is already typing.
const focusOnSlash = (event) => {
    if (event.key !== '/' || event.metaKey || event.ctrlKey || event.altKey) return;
    if (event.target.closest?.('input, textarea, select, [contenteditable="true"]')) return;
    event.preventDefault();
    input.value?.focus();
};
onMounted(() => window.addEventListener('keydown', focusOnSlash));
onBeforeUnmount(() => { window.removeEventListener('keydown', focusOnSlash); clearTimeout(timer); controller?.abort(); });
</script>

<template>
    <form class="home-search" role="search" @submit.prevent="submit" @focusout="(event) => !event.currentTarget.contains(event.relatedTarget) && close()">
        <label class="home-search__field">
            <span class="sr-only">{{ t('searchLabel') }}</span>
            <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="10.5" cy="10.5" r="6.5" /><path d="m15.5 15.5 5 5" /></svg>
            <input ref="input" v-model="query" type="search" autocomplete="off" spellcheck="false" maxlength="120"
                :placeholder="t('home.searchPlaceholder')" role="combobox" aria-autocomplete="list" aria-controls="home-search-results"
                :aria-expanded="showList" :aria-activedescendant="active >= 0 ? `home-search-${active}` : undefined"
                @focus="open = true" @keydown.down.prevent="move(1)" @keydown.up.prevent="move(-1)" @keydown.esc="close" />
            <kbd :title="t('home.searchHint')" aria-hidden="true">/</kbd>
        </label>
        <div v-show="showList" id="home-search-results" class="home-search__results" role="listbox" :aria-label="t('searchLabel')" :aria-busy="busy">
            <p v-if="busy && !items.length" class="home-search__note" role="status">{{ t('home.searching') }}</p>
            <p v-else-if="!items.length" class="home-search__note" role="status">{{ t('home.searchNone') }}</p>
            <a v-for="(item, index) in items" :id="`home-search-${index}`" :key="item.id" :href="item.url" role="option" :aria-selected="index === active"
                class="home-search__item" :class="{ active: index === active }" @mouseenter="active = index" @click.prevent="router.visit(item.url)">
                <span class="home-search__thumb"><img v-if="item.image" :src="item.image" alt="" loading="lazy" decoding="async" /></span>
                <span class="home-search__name"><small>{{ item.brand }}</small>{{ titleOf(item) }}</span>
                <strong>{{ formatPrice(item.price, item.currency) }}</strong>
            </a>
            <button v-if="total > items.length" type="submit" class="home-search__all" @mousedown.prevent @click="active = -1">{{ t('home.searchAll') }} ({{ total }})</button>
        </div>
    </form>
</template>
