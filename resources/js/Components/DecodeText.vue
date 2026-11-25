<script setup>
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';

// A value that is read in like an instrument's display: its characters run through
// others of the same kind and settle from left to right. Digits stay digits, so a
// number set in tabular figures keeps its width while it settles. Screen readers get
// the plain text; a visitor who asked for less motion sees it at once.
const props = defineProps({ text: { type: [String, Number], default: '' }, duration: { type: Number, default: 520 }, delay: { type: Number, default: 0 } });
const shown = ref(String(props.text ?? ''));
const DIGITS = '0123456789';
const LETTERS = 'ABCDEFGHJKLMNPRSTUVXYZ';
let frame;
let timer;

const scrambled = (char) => {
    if (/\d/.test(char)) return DIGITS[Math.floor(Math.random() * DIGITS.length)];
    if (/\p{L}/u.test(char)) {
        const letter = LETTERS[Math.floor(Math.random() * LETTERS.length)];
        return char === char.toLowerCase() ? letter.toLowerCase() : letter;
    }
    return char;
};

const run = () => {
    cancelAnimationFrame(frame);
    clearTimeout(timer);
    const target = String(props.text ?? '');
    if (typeof window === 'undefined' || window.matchMedia('(prefers-reduced-motion: reduce)').matches || !target) {
        shown.value = target;
        return;
    }
    const chars = [...target];
    shown.value = chars.map(scrambled).join('');
    timer = setTimeout(() => {
        const start = performance.now();
        const tick = (now) => {
            const settled = Math.floor(((now - start) / props.duration) * chars.length);
            shown.value = chars.map((char, index) => (index < settled ? char : scrambled(char))).join('');
            if (settled < chars.length) frame = requestAnimationFrame(tick);
            else shown.value = target;
        };
        frame = requestAnimationFrame(tick);
    }, props.delay);
};

onMounted(run);
watch(() => props.text, run);
onBeforeUnmount(() => { cancelAnimationFrame(frame); clearTimeout(timer); });
</script>

<template>
    <span class="decode-text"><span class="sr-only">{{ text }}</span><span aria-hidden="true">{{ shown }}</span></span>
</template>
