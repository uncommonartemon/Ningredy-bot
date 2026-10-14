<script setup>
import { gsap } from 'gsap';
import { onBeforeUnmount, ref, useId, watch } from 'vue';
const props = defineProps({ title: { type: String, required: true }, openByDefault: { type: Boolean, default: false }, selectedCount: { type: Number, default: 0 }, selectedLabel: { type: String, default: '' }, selectionKey: { type: String, default: '' }, headingLevel: { type: Number, default: 3, validator: (value) => [3, 4].includes(value) } });
const open = ref(props.openByDefault || props.selectedCount > 0);
const contentId = useId();
const motion = ref(null);
watch(() => [props.selectedCount, props.selectionKey], ([count]) => { if (count > 0) open.value = true; });
const reducedMotion = () => window.matchMedia('(prefers-reduced-motion: reduce)').matches;
const enter = (element, done) => {
    gsap.killTweensOf(element);
    if (reducedMotion()) { gsap.set(element, { clearProps: 'height,opacity' }); done(); return; }
    gsap.fromTo(element, { height: 0, opacity: 0 }, { height: 'auto', opacity: 1, duration: .26, ease: 'power2.out', clearProps: 'height,opacity', onComplete: done });
};
const leave = (element, done) => {
    gsap.killTweensOf(element);
    if (reducedMotion()) { done(); return; }
    gsap.to(element, { height: 0, opacity: 0, duration: .2, ease: 'power2.inOut', onComplete: done });
};
onBeforeUnmount(() => { if (motion.value) gsap.killTweensOf(motion.value); });
</script>
<template>
    <section class="filter-section" :class="{ 'filter-section--open': open }">
        <component :is="`h${headingLevel}`" class="filter-section__heading"><button type="button" class="filter-section__trigger" :aria-expanded="open" :aria-controls="contentId" @click="open = !open"><span>{{ title }} <small v-if="selectedCount">{{ selectedLabel ? `${selectedLabel} ${selectedCount}` : selectedCount }}</small></span><svg viewBox="0 0 20 20" aria-hidden="true"><path d="m5 7.5 5 5 5-5" /></svg></button></component>
        <Transition :css="false" @enter="enter" @leave="leave">
            <div v-show="open" :id="contentId" ref="motion" class="filter-section__motion"><div class="filter-section__content"><slot /></div></div>
        </Transition>
    </section>
</template>
