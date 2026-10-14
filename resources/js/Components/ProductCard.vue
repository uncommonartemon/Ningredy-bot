<script setup>
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import ProductVisual from './ProductVisual.vue';
import { useI18n } from '../i18n';
import { orderedAttributes } from '../attributeGroups';
const props = defineProps({ product: { type: Object, required: true }, view: { type: String, default: 'grid' } });
const { localeTag, t, categoryName, contentLocale, attributeLabel, attributeValue, attributeUnit } = useI18n();
const title = computed(() => props.product.translations?.[contentLocale.value]?.title || props.product.title);
const highlights = computed(() => orderedAttributes(props.product.attributes || []).slice(0, 4));
const formatPrice = (value, currency) => {
    if (value === null || value === undefined) return t('priceUnavailable');
    try {
        return new Intl.NumberFormat(localeTag.value, { style: 'currency', currency: currency || 'CZK', maximumFractionDigits: 0 }).format(value);
    } catch {
        return `${new Intl.NumberFormat(localeTag.value).format(value)} ${currency || ''}`.trim();
    }
};
const discount = computed(() => props.product.price !== null && Number(props.product.compare_at_price) > Number(props.product.price));
</script>
<template>
    <Link :href="product.url || `/products/${product.slug}`" class="product-card-link" :aria-label="title" prefetch>
        <article class="product-card" :class="{ 'product-card--list': view === 'list' }">
            <div class="product-card__media"><ProductVisual :product="product" :title="title" /><span v-if="product.condition" class="product-card__condition">{{ t(`condition.${product.condition}`, product.condition) }}</span></div>
            <div class="product-card__body">
                <div class="product-card__intro"><div class="product-card__meta"><p>{{ product.brand || categoryName(product.category) || t('tech') }}</p><span :class="`stock stock--${product.stock_status}`">{{ t(`stock.${product.stock_status}`, product.stock_status) }}</span></div><h2>{{ title }}</h2></div>
                <ul v-if="highlights.length" class="product-card__specs"><li v-for="attribute in highlights" :key="attribute.key || attribute.label"><span>{{ attributeLabel(attribute) }}</span><strong>{{ attributeValue(attribute) }}<template v-if="attributeUnit(attribute)"> {{ attributeUnit(attribute) }}</template></strong></li></ul>
                <div class="product-card__bottom"><div class="product-card__price"><strong>{{ formatPrice(product.price, product.currency) }}</strong><del v-if="discount">{{ formatPrice(product.compare_at_price, product.currency) }}</del></div><span class="product-card__cta">{{ t('viewProduct') }}<svg viewBox="0 0 20 20" aria-hidden="true"><path d="M3 10h13m-5-5 5 5-5 5" /></svg></span></div>
            </div>
        </article>
    </Link>
</template>
