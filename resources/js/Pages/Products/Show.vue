<script setup>
import { Head, router } from '@inertiajs/vue3';
import { gsap } from 'gsap';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { A11y, Keyboard, Navigation, Pagination } from 'swiper/modules';
import { Swiper, SwiperSlide } from 'swiper/vue';
import 'swiper/css';
import 'swiper/css/navigation';
import 'swiper/css/pagination';
import { useI18n } from '../../i18n';

const props = defineProps({ product: { type: Object, required: true } });
const { locale, localeTag, t, categoryName } = useI18n();
const page = ref(null);
const selectedImage = ref(0);
const gallerySwiper = ref(null);
const selectedVariant = ref(Math.max(0, props.product.variants.findIndex((item) => item.is_default)));
const swiperModules = [A11y, Keyboard, Navigation, Pagination];
let context;

const productCopy = {
    uk: {
        back: 'Назад до каталогу', details: 'Дані товару', specifications: 'Характеристики', sources: 'Джерела',
        model: 'Модель', category: 'Категорія', type: 'Тип', country: 'Країна бренду', color: 'Колір',
        condition: 'Стан', warranty: 'Гарантія', months: 'міс.', quantity: 'Кількість', noImage: 'Зображення ще не додано',
    },
    cs: {
        back: 'Zpět do katalogu', details: 'Údaje o produktu', specifications: 'Specifikace', sources: 'Zdroje',
        model: 'Model', category: 'Kategorie', type: 'Typ', country: 'Země značky', color: 'Barva',
        condition: 'Stav', warranty: 'Záruka', months: 'měs.', quantity: 'Množství', noImage: 'Obrázek zatím nebyl přidán',
    },
    en: {
        back: 'Back to catalog', details: 'Product details', specifications: 'Specifications', sources: 'Sources',
        model: 'Model', category: 'Category', type: 'Type', country: 'Brand country', color: 'Color',
        condition: 'Condition', warranty: 'Warranty', months: 'months', quantity: 'Quantity', noImage: 'No image has been added yet',
    },
};
const copy = computed(() => productCopy[locale.value] || productCopy.en);
const variant = computed(() => props.product.variants[selectedVariant.value] || null);
const currentImage = computed(() => props.product.media[selectedImage.value] || null);
const allAttributes = computed(() => {
    const values = [...props.product.attributes, ...(variant.value?.attributes || [])];
    return [...new Map(values.map((item) => [item.key, item])).values()];
});
const formatPrice = (value, currency) => value === null
    ? t('priceUnavailable')
    : new Intl.NumberFormat(localeTag.value, { style: 'currency', currency: currency || 'CZK', maximumFractionDigits: 0 }).format(value);
const goBack = () => window.history.length > 1 ? window.history.back() : router.visit('/catalog');
const rememberSwiper = (swiper) => { gallerySwiper.value = swiper; };
const syncImage = (swiper) => { selectedImage.value = swiper.activeIndex; };
const selectImage = (index) => {
    selectedImage.value = index;
    gallerySwiper.value?.slideTo(index);
};

onMounted(() => {
    context = gsap.context(() => {
        gsap.timeline({ defaults: { ease: 'power3.out' } })
            .from('.product-show__back', { opacity: 0, x: -18, duration: .45 })
            .from('.product-show__gallery', { opacity: 0, x: -28, scale: .98, duration: .72 }, '-=.2')
            .from('.product-show__summary > *', { opacity: 0, y: 20, duration: .55, stagger: .07 }, '-=.55')
            .from('.product-show__section', { opacity: 0, y: 28, duration: .55, stagger: .08 }, '-=.25');
    }, page.value);
});
onBeforeUnmount(() => context?.revert());
</script>

<template>
    <Head :title="product.title" />
    <main ref="page" class="product-show">
        <span class="ambient-glow ambient-glow--cyan" aria-hidden="true" />
        <span class="ambient-glow ambient-glow--violet" aria-hidden="true" />
        <button class="product-show__back" type="button" @click="goBack"><span>←</span>{{ copy.back }}</button>

        <div class="product-show__hero">
            <section class="product-show__gallery">
                <div class="product-show__main-image">
                    <Swiper
                        v-if="product.media.length > 1"
                        class="product-show__swiper"
                        :modules="swiperModules"
                        :slides-per-view="1"
                        :space-between="12"
                        navigation
                        :pagination="{ clickable: true }"
                        :keyboard="{ enabled: true }"
                        @swiper="rememberSwiper"
                        @slide-change="syncImage"
                    >
                        <SwiperSlide v-for="media in product.media" :key="media.id">
                            <img :src="media.url" :alt="media.alt" />
                        </SwiperSlide>
                    </Swiper>
                    <Transition v-else name="image-fade" mode="out-in">
                        <img v-if="currentImage" :key="currentImage.id" :src="currentImage.url" :alt="currentImage.alt" />
                        <div v-else class="product-show__placeholder">{{ copy.noImage }}</div>
                    </Transition>
                </div>
                <div v-if="product.media.length > 1" class="product-show__thumbs">
                    <button v-for="(media, index) in product.media" :key="media.id" type="button" :class="{ active: selectedImage === index }" @click="selectImage(index)">
                        <img :src="media.url" :alt="media.alt" />
                    </button>
                </div>
            </section>

            <section class="product-show__summary">
                <div class="product-show__eyebrow">
                    <span>{{ product.brand?.name || categoryName(product.category) }}</span>
                    <span :class="`stock stock--${variant?.stock_status || 'unknown'}`">{{ t(`stock.${variant?.stock_status || 'unknown'}`) }}</span>
                </div>
                <h1>{{ product.title }}</h1>
                <p v-if="product.description" class="product-show__description">{{ product.description }}</p>
                <div class="product-show__price">
                    <strong>{{ formatPrice(variant?.price ?? null, variant?.currency) }}</strong>
                    <del v-if="variant?.compare_at_price">{{ formatPrice(variant.compare_at_price, variant.currency) }}</del>
                </div>
                <div v-if="product.variants.length > 1" class="product-show__variant-picker">
                    <button v-for="(item, index) in product.variants" :key="item.id" type="button" :class="{ active: selectedVariant === index }" @click="selectedVariant = index">
                        {{ item.name || item.color || `#${item.id}` }}
                    </button>
                </div>
                <dl class="product-show__facts">
                    <div v-if="product.model"><dt>{{ copy.model }}</dt><dd>{{ product.model }}</dd></div>
                    <div v-if="product.category"><dt>{{ copy.category }}</dt><dd>{{ categoryName(product.category) }}</dd></div>
                    <div><dt>{{ copy.type }}</dt><dd>{{ t(`types.${product.type}`, product.type) }}</dd></div>
                    <div v-if="product.brand?.country"><dt>{{ copy.country }}</dt><dd>{{ product.brand.country }}</dd></div>
                </dl>
            </section>
        </div>

        <div class="product-show__content">
            <section v-if="allAttributes.length" class="product-show__section">
                <h2>{{ copy.specifications }}</h2>
                <dl class="product-show__specs">
                    <div v-for="attribute in allAttributes" :key="attribute.key"><dt>{{ t(`attributes.${attribute.key}`, attribute.label) }}</dt><dd>{{ attribute.value }}<small v-if="attribute.unit"> {{ attribute.unit }}</small></dd></div>
                </dl>
            </section>
            <section v-if="variant" class="product-show__section">
                <h2>{{ copy.details }}</h2>
                <dl class="product-show__specs">
                    <div v-for="[label, value] in [['SKU', variant.sku], ['MPN', variant.mpn], ['GTIN', variant.gtin], [copy.color, variant.color], [copy.condition, variant.condition], [copy.quantity, variant.quantity], [copy.warranty, variant.warranty_months ? `${variant.warranty_months} ${copy.months}` : null]]" :key="label" v-show="value !== null && value !== ''">
                        <dt>{{ label }}</dt><dd>{{ value }}</dd>
                    </div>
                </dl>
            </section>
        </div>
    </main>
</template>
