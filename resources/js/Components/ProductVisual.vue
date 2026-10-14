<script setup>
import { ref, watch } from 'vue';
import { useI18n } from '../i18n';
const props = defineProps({ product: { type: Object, required: true }, title: { type: String, default: '' } });
const imageFailed = ref(false);
const { t } = useI18n();
watch(() => props.product.image, () => { imageFailed.value = false; });
</script>
<template>
    <div class="product-visual" :class="{ 'product-visual--missing': !product.image || imageFailed }">
        <img v-if="product.image && !imageFailed" :src="product.image" :alt="title || product.title" loading="lazy" decoding="async" @error="imageFailed = true" />
        <div v-else class="product-visual__placeholder"><svg viewBox="0 0 48 48" aria-hidden="true"><rect x="6" y="9" width="36" height="30" rx="4" /><circle cx="17" cy="19" r="3" /><path d="m7 34 11-10 8 7 7-6 9 9" /></svg><span>{{ t('photoUnavailable') }}</span></div>
    </div>
</template>