<script setup>
import { Link } from '@inertiajs/vue3';
import { nextTick, onBeforeUnmount, onMounted, ref } from 'vue';
import ProductCard from '../ProductCard.vue';
import { useI18n } from '../../i18n';

// A row of real cards the visitor scrolls sideways; the arrows move it by one screen.
defineProps({ title: { type: String, required: true }, products: { type: Array, required: true }, href: { type: String, default: '/catalog' } });
const { t } = useI18n();
const track = ref(null);
const atStart = ref(true);
const atEnd = ref(true);
const measure = () => {
    const element = track.value;
    if (!element) return;
    atStart.value = element.scrollLeft <= 2;
    atEnd.value = element.scrollLeft + element.clientWidth >= element.scrollWidth - 2;
};
const scroll = (direction) => {
    const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    track.value?.scrollBy({ left: direction * track.value.clientWidth * 0.9, behavior: reduce ? 'auto' : 'smooth' });
};
onMounted(() => { nextTick(measure); window.addEventListener('resize', measure); });
onBeforeUnmount(() => window.removeEventListener('resize', measure));
</script>

<template>
    <section class="home-section rail">
        <header class="home-section__head">
            <h2>{{ title }}</h2>
            <div class="rail__actions">
                <Link :href="href" class="home-section__more">{{ t('home.openCatalog') }}</Link>
                <button type="button" :disabled="atStart" :aria-label="t('home.scrollPrevious')" @click="scroll(-1)"><svg viewBox="0 0 20 20" aria-hidden="true"><path d="m12 4-6 6 6 6" /></svg></button>
                <button type="button" :disabled="atEnd" :aria-label="t('home.scrollNext')" @click="scroll(1)"><svg viewBox="0 0 20 20" aria-hidden="true"><path d="m8 4 6 6-6 6" /></svg></button>
            </div>
        </header>
        <div ref="track" class="rail__track" @scroll.passive="measure">
            <ProductCard v-for="product in products" :key="product.id" :product="product" />
        </div>
    </section>
</template>
