<script setup>
import { Link } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import DecodeText from '../DecodeText.vue';
import ProductVisual from '../ProductVisual.vue';
import { cardAttributes } from '../../attributeGroups';
import { useI18n } from '../../i18n';
import { usePrice } from '../../price';

// The bench: one real product from the catalog, large, with its readout. The visitor
// switches products with the row below; nothing turns by itself.
const props = defineProps({ products: { type: Array, required: true } });
const { t, categoryName, translatedText, attributeLabel, attributeValue, attributeUnit } = useI18n();
const { formatPrice } = usePrice();
const index = ref(0);
const product = computed(() => props.products[index.value]);
const titleOf = (item) => translatedText(item.translations, 'title') || item.title;
const readout = computed(() => (product.value ? cardAttributes(product.value.attributes || [], product.value.card_keys || []).slice(0, 4) : []));
const discount = computed(() => product.value?.price !== null && Number(product.value?.compare_at_price) > Number(product.value?.price));
const choose = (next) => { index.value = next; };
const valueOf = (attribute) => [attributeValue(attribute), attributeUnit(attribute)].filter(Boolean).join(' ');

// The plate turns a few degrees after the pointer, as if the laptop were in the hand.
const tilt = ref({ x: 0, y: 0 });
const canTilt = () => window.matchMedia('(hover: hover) and (pointer: fine)').matches
    && !window.matchMedia('(prefers-reduced-motion: reduce)').matches;
const follow = (event) => {
    if (!canTilt()) return;
    const box = event.currentTarget.getBoundingClientRect();
    tilt.value = { x: ((event.clientY - box.top) / box.height - 0.5) * -5, y: ((event.clientX - box.left) / box.width - 0.5) * 7 };
};
const settle = () => { tilt.value = { x: 0, y: 0 }; };
const step = (event, current) => {
    const count = props.products.length;
    const next = event.key === 'ArrowRight' ? (current + 1) % count : event.key === 'ArrowLeft' ? (current - 1 + count) % count : null;
    if (next === null) return;
    event.preventDefault();
    choose(next);
    event.currentTarget.parentElement.children[next]?.focus();
};
</script>

<template>
    <section class="bench" :aria-label="t('home.arrivals')" data-transition-scope>
        <template v-if="product">
            <Link :href="product.url" class="bench__plate" :aria-label="titleOf(product)" data-transition-photo
                :style="{ '--tilt-x': `${tilt.x}deg`, '--tilt-y': `${tilt.y}deg` }" @pointermove="follow" @pointerleave="settle">
                <Transition name="bench-photo">
                    <ProductVisual :key="product.id" :product="product" :title="titleOf(product)" />
                </Transition>
            </Link>
            <Transition name="bench-readout" mode="out-in">
                <div :key="product.id" class="bench__body">
                    <div class="bench__intro">
                        <p class="bench__meta"><span>{{ product.brand || categoryName(product.category) || t('tech') }}</span><span :class="`stock stock--${product.stock_status}`">{{ t(`stock.${product.stock_status}`, product.stock_status) }}</span></p>
                        <h2><Link :href="product.url">{{ titleOf(product) }}</Link></h2>
                    </div>
                    <dl v-if="readout.length" class="bench__readout">
                        <div v-for="(attribute, row) in readout" :key="attribute.key || attribute.label" :style="{ '--row': row }">
                            <dt>{{ attributeLabel(attribute) }}</dt>
                            <dd><DecodeText :text="valueOf(attribute)" :delay="140 + row * 45" /></dd>
                        </div>
                    </dl>
                    <div class="bench__buy">
                        <p class="bench__price"><strong><DecodeText :text="formatPrice(product.price, product.currency)" :delay="320" /></strong><del v-if="discount">{{ formatPrice(product.compare_at_price, product.currency) }}</del><span v-if="product.own_price" class="own-price">{{ t('account.yourPrice') }}</span></p>
                        <Link :href="product.url" class="bench__cta">{{ t('viewProduct') }}</Link>
                    </div>
                </div>
            </Transition>
            <div v-if="products.length > 1" class="bench__selector" role="group" :aria-label="t('home.benchChoose')">
                <button v-for="(item, position) in products" :key="item.id" type="button" :class="{ active: position === index }" :aria-pressed="position === index"
                    :aria-label="titleOf(item)" :tabindex="position === index ? 0 : -1" @click="choose(position)" @keydown="step($event, position)">
                    <img v-if="item.image" :src="item.image" alt="" loading="lazy" decoding="async" />
                </button>
            </div>
        </template>
        <div v-else class="bench__plate bench__plate--empty">
            <svg viewBox="0 0 64 44" aria-hidden="true"><rect x="10" y="4" width="44" height="30" rx="3" /><path d="M4 38h56l-4 4H8z" /></svg>
            <p>{{ t('home.emptyBench') }}</p>
        </div>
    </section>
</template>
