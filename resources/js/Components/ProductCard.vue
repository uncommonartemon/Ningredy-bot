<script setup>
import { gsap } from 'gsap';
import { Link } from '@inertiajs/vue3';
import { onBeforeUnmount, onMounted, ref } from 'vue';
import ProductVisual from './ProductVisual.vue';
import { useI18n } from '../i18n';

const props = defineProps({
    product: { type: Object, required: true },
    view: { type: String, default: 'grid' },
});

const card = ref(null);
const media = ref(null);
let context;
let rotateX;
let rotateY;
let lift;
const { localeTag, t, categoryName } = useI18n();

const formatPrice = (value, currency) => value === null
    ? t('priceUnavailable')
    : new Intl.NumberFormat(localeTag.value, {
        style: 'currency', currency: currency || 'CZK', maximumFractionDigits: 0,
    }).format(value);

const stockLabel = (status) => t(`stock.${status}`, status);
const attributeLabel = (attribute) => t(`attributes.${attribute.key}`, attribute.label);
const productFamily = () => props.product.brand
    || categoryName(props.product.category)
    || t('tech');

const moveCard = (event) => {
    if (props.view === 'list' || !card.value) return;
    const bounds = card.value.getBoundingClientRect();
    rotateY(((event.clientX - bounds.left) / bounds.width - .5) * 4);
    rotateX(-((event.clientY - bounds.top) / bounds.height - .5) * 4);
    card.value.style.setProperty('--pointer-x', `${event.clientX - bounds.left}px`);
    card.value.style.setProperty('--pointer-y', `${event.clientY - bounds.top}px`);
};

const resetCard = () => {
    rotateX?.(0);
    rotateY?.(0);
    lift?.(0);
};

const hoverCard = () => lift?.(-6);

onMounted(() => {
    context = gsap.context(() => {
        rotateX = gsap.quickTo(card.value, 'rotationX', { duration: .55, ease: 'power3.out' });
        rotateY = gsap.quickTo(card.value, 'rotationY', { duration: .55, ease: 'power3.out' });
        lift = gsap.quickTo(card.value, 'y', { duration: .45, ease: 'power3.out' });
        gsap.fromTo(media.value, { scale: .94, opacity: .65 }, { scale: 1, opacity: 1, duration: .75, ease: 'power3.out' });
    }, card.value);
});

onBeforeUnmount(() => context?.revert());
</script>

<template>
    <Link
        :href="`/products/${product.slug}`"
        class="product-card-link"
        :aria-label="product.title"
        prefetch
    >
      <article
          ref="card"
          class="product-card"
          :class="{ 'product-card--list': view === 'list' }"
          @pointerenter="hoverCard"
          @pointermove="moveCard"
          @pointerleave="resetCard"
      >
        <span class="product-card__pointer-glow" />
        <div ref="media" class="product-card__media">
            <ProductVisual :product="product" />
            <span class="product-card__media-shine" />
        </div>
        <div class="product-card__body">
            <div class="product-card__meta">
                <p>{{ productFamily() }}</p>
                <span :class="`stock stock--${product.stock_status}`">{{ stockLabel(product.stock_status) }}</span>
            </div>
            <h2>{{ product.title }}</h2>
            <ul v-if="product.attributes.length">
                <li v-for="attribute in product.attributes.slice(0, 4)" :key="`${attribute.label}-${attribute.value}`">
                    <span>{{ attributeLabel(attribute) }}</span>
                    <strong>{{ attribute.value }}</strong>
                </li>
            </ul>
            <div class="product-card__price">
                <strong>{{ formatPrice(product.price, product.currency) }}</strong>
                <del v-if="product.compare_at_price">{{ formatPrice(product.compare_at_price, product.currency) }}</del>
            </div>
        </div>
      </article>
    </Link>
</template>
