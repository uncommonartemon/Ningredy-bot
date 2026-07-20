<script setup>
import { gsap } from 'gsap';
import { ref } from 'vue';

const props = defineProps({
    title: { type: String, required: true },
    openByDefault: { type: Boolean, default: false },
});

const open = ref(props.openByDefault);
const enter = (element, done) => {
    gsap.fromTo(element, { height: 0, opacity: 0 }, {
        height: 'auto', opacity: 1, duration: .38, ease: 'power3.out', onComplete: done,
    });
};
const leave = (element, done) => {
    gsap.to(element, { height: 0, opacity: 0, duration: .25, ease: 'power2.inOut', onComplete: done });
};
</script>

<template>
    <section class="filter-section" :class="{ 'filter-section--open': open }">
        <button type="button" class="filter-section__trigger" @click="open = !open">
            <span>{{ title }}</span>
            <span class="filter-section__icon"><svg viewBox="0 0 20 20" aria-hidden="true"><path d="m5 7.5 5 5 5-5" /></svg></span>
        </button>
        <Transition :css="false" @enter="enter" @leave="leave">
            <div v-if="open" class="filter-section__motion">
                <div class="filter-section__content"><slot /></div>
            </div>
        </Transition>
    </section>
</template>
