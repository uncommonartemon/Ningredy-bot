<script setup>
import { ref } from 'vue';

defineProps({
    product: { type: Object, required: true },
});

const imageFailed = ref(false);
</script>

<template>
    <div class="product-visual" :class="`product-visual--${product.type}`">
        <img
            v-if="product.image && !imageFailed"
            :src="product.image"
            :alt="product.title"
            loading="lazy"
            @error="imageFailed = true"
        />
        <svg v-else-if="product.type === 'laptop'" viewBox="0 0 180 130" aria-hidden="true">
            <rect x="29" y="18" width="122" height="82" rx="7" fill="currentColor" opacity=".18" />
            <rect x="36" y="25" width="108" height="68" rx="3" fill="currentColor" opacity=".8" />
            <path d="M13 104h154l-13 13H26z" fill="currentColor" opacity=".35" />
            <path d="M65 104h50l-4 6H69z" fill="currentColor" opacity=".9" />
        </svg>
        <svg v-else-if="product.type === 'desktop'" viewBox="0 0 180 130" aria-hidden="true">
            <rect x="48" y="12" width="84" height="108" rx="8" fill="currentColor" opacity=".24" />
            <rect x="58" y="22" width="64" height="54" rx="4" fill="currentColor" opacity=".7" />
            <circle cx="90" cy="49" r="19" fill="none" stroke="currentColor" stroke-width="5" opacity=".9" />
            <circle cx="90" cy="95" r="6" fill="currentColor" />
        </svg>
        <svg v-else viewBox="0 0 180 130" aria-hidden="true">
            <rect x="24" y="29" width="132" height="73" rx="9" fill="currentColor" opacity=".25" />
            <circle cx="61" cy="65" r="24" fill="none" stroke="currentColor" stroke-width="7" opacity=".85" />
            <path d="M98 48h38M98 64h29M98 80h35" stroke="currentColor" stroke-width="7" stroke-linecap="round" opacity=".75" />
            <path d="M35 21v8M61 21v8M87 21v8M113 21v8M139 21v8M35 102v8M61 102v8M87 102v8M113 102v8M139 102v8" stroke="currentColor" stroke-width="4" />
        </svg>
        <span class="visual-glow" />
    </div>
</template>
