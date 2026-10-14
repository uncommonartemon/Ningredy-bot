<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import LocaleSwitcher from '../../Components/LocaleSwitcher.vue';
import ProductGallery from '../../Components/ProductGallery.vue';
import { useI18n } from '../../i18n';
import { groupAttributeBlocks, orderedAttributes } from '../../attributeGroups';
import { backToCatalog } from '../../navigationTransitions';
import '../../../css/product.css';

const props = defineProps({ product: { type: Object, required: true } });
const { locale, localeTag, t, categoryName, contentLocale, attributeLabel, attributeValue, attributeUnit, attributeGroupName } = useI18n();
const requestedVariant = props.product.variants.findIndex((item) => item.id === props.product.selected_variant_id);
const selectedVariant = ref(requestedVariant >= 0 ? requestedVariant : Math.max(0, props.product.variants.findIndex((item) => item.is_default)));
const productCopy = {
    uk: {
        back: 'До каталогу', details: 'Дані товару', specifications: 'Характеристики', description: 'Про товар',
        model: 'Модель', category: 'Категорія', type: 'Тип', country: 'Країна бренду', color: 'Колір',
        condition: 'Стан', warranty: 'Гарантія', months: 'міс.', quantity: 'Кількість',
        configuration: 'Комплектація', highlights: 'Коротко про головне', allSpecs: 'Усі характеристики',
        onPage: 'На цій сторінці', skip: 'До інформації про товар',
    },
    cs: {
        back: 'Zpět do katalogu', details: 'Údaje o produktu', specifications: 'Specifikace', description: 'O produktu',
        model: 'Model', category: 'Kategorie', type: 'Typ', country: 'Země značky', color: 'Barva',
        condition: 'Stav', warranty: 'Záruka', months: 'měs.', quantity: 'Množství',
        configuration: 'Konfigurace', highlights: 'To nejdůležitější', allSpecs: 'Všechny specifikace',
        onPage: 'Na této stránce', skip: 'Přejít k informacím o produktu',
    },
    en: {
        back: 'Back to catalog', details: 'Product details', specifications: 'Specifications', description: 'About this product',
        model: 'Model', category: 'Category', type: 'Type', country: 'Brand country', color: 'Color',
        condition: 'Condition', warranty: 'Warranty', months: 'months', quantity: 'Quantity',
        configuration: 'Configuration', highlights: 'At a glance', allSpecs: 'All specifications',
        onPage: 'On this page', skip: 'Skip to product information',
    },
};
const copy = computed(() => productCopy[locale.value] || productCopy.en);
const variant = computed(() => props.product.variants[selectedVariant.value] || null);
const translated = computed(() => ({ ...props.product.translations?.[contentLocale.value],
    ...Object.fromEntries(Object.entries(variant.value?.translations?.[contentLocale.value] || {}).filter(([, value]) => value)) }));
const title = computed(() => translated.value.title || props.product.title);
const description = computed(() => translated.value.description || props.product.description);
const paragraphs = computed(() => (description.value || '').split(/\n\s*\n/).filter((part) => part.trim()));
const conditionLabel = computed(() => variant.value?.condition ? t(`condition.${variant.value.condition}`, variant.value.condition) : null);
const gallery = computed(() => {
    const selected = props.product.media.filter((media) => media.variant_id === variant.value?.id);
    return selected.length ? selected : props.product.media.filter((media) => media.variant_id == null);
});
const hasValue = (value) => value !== null && value !== undefined && value !== '';
const allAttributes = computed(() => {
    const values = [...props.product.attributes, ...(variant.value?.attributes || [])];
    return [...new Map(values.map((item) => [item.key, item])).values()].filter((item) => hasValue(attributeValue(item)));
});
const attributeBlocks = computed(() => groupAttributeBlocks(allAttributes.value));
const highlights = computed(() => {
    const core = ['cpu', 'processor', 'gpu', 'graphics', 'ram', 'memory', 'storage', 'display'];
    const rank = (item) => core.includes(item.key) ? core.indexOf(item.key) : core.length;
    return orderedAttributes([...allAttributes.value].sort((a, b) => rank(a) - rank(b)).slice(0, 4));
});
const facts = computed(() => [
    [copy.value.model, props.product.model], ['SKU', variant.value?.sku], ['MPN', variant.value?.mpn], ['GTIN', variant.value?.gtin],
    [copy.value.category, categoryName(props.product.category)], [copy.value.type, t(`types.${props.product.type}`, props.product.type)],
    [copy.value.color, variant.value?.color], [copy.value.condition, conditionLabel.value],
    [copy.value.quantity, variant.value?.quantity],
    [copy.value.warranty, hasValue(variant.value?.warranty_months) ? `${variant.value.warranty_months} ${copy.value.months}` : null],
    [copy.value.country, props.product.brand?.country],
].filter(([, value]) => hasValue(value)));
const hasDiscount = computed(() => hasValue(variant.value?.price) && Number(variant.value?.compare_at_price) > Number(variant.value.price));
const formatPrice = (value, currency) => {
    if (!hasValue(value)) return t('priceUnavailable');
    try {
        return new Intl.NumberFormat(localeTag.value, { style: 'currency', currency: currency || 'CZK', maximumFractionDigits: 0 }).format(value);
    } catch {
        return `${new Intl.NumberFormat(localeTag.value).format(value)} ${currency || 'CZK'}`;
    }
};
const selectVariant = () => {
    const url = new URL(window.location.href);
    url.searchParams.set('variant', variant.value.id);
    router.replace({
        url: `${url.pathname}${url.search}${url.hash}`, preserveState: true, preserveScroll: true,
        props: (page) => ({ ...page, product: { ...page.product, selected_variant_id: variant.value.id } }),
    });
};
watch(() => [props.product.id, props.product.selected_variant_id], () => {
    const index = props.product.variants.findIndex((item) => item.id === props.product.selected_variant_id);
    selectedVariant.value = index >= 0 ? index : Math.max(0, props.product.variants.findIndex((item) => item.is_default));
});
const goBack = () => {
    const navigation = window.navigation;
    const previous = navigation?.entries().find((entry) => entry.index === navigation.currentEntry?.index - 1);
    const url = previous?.url ? new URL(previous.url) : null;
    if (url?.origin === window.location.origin && url.pathname === '/catalog') backToCatalog();
    else router.visit('/catalog');
};
const jumpTo = (id) => {
    const section = document.getElementById(id);
    section?.focus({ preventScroll: true });
    section?.scrollIntoView({ behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'instant' : 'smooth', block: 'start' });
};
</script>

<template>
    <Head :title="title" />
    <main class="catalog-page product-show">
        <a class="skip-link" href="#product-information">{{ copy.skip }}</a>
        <header class="catalog-masthead">
            <nav :aria-label="t('navigation')"><Link href="/catalog">{{ t('catalog') }}</Link></nav>
            <LocaleSwitcher />
        </header>
        <nav class="product-show__breadcrumb" :aria-label="t('navigation')">
            <button type="button" @click="goBack"><span aria-hidden="true">←</span> {{ copy.back }}</button>
            <template v-if="product.category"><span aria-hidden="true">/</span><Link :href="`/catalog?category=${encodeURIComponent(product.category.slug)}`">{{ categoryName(product.category) }}</Link></template>
            <template v-if="product.model"><span aria-hidden="true">/</span><span class="product-show__breadcrumb-model">{{ product.model }}</span></template>
        </nav>
        <div class="product-show__layout">
            <header class="product-show__intro">
                <p class="product-show__eyebrow">{{ product.brand?.name || categoryName(product.category) }}</p>
                <h1>{{ title }}</h1>
                <div class="product-show__badges">
                    <span :class="['stock', `stock--${variant?.stock_status || 'unknown'}`]">{{ t(`stock.${variant?.stock_status || 'unknown'}`) }}</span>
                    <span v-if="conditionLabel" class="product-show__condition">{{ conditionLabel }}</span>
                    <span v-if="variant?.color" class="product-show__color">{{ variant.color }}</span>
                </div>
            </header>
            <ProductGallery :key="`${product.id}-${variant?.id || 'shared'}`" class="product-show__gallery" :media="gallery" :title="title" />
            <div id="product-information" class="product-show__information" tabindex="-1">
                <section class="product-show__offer" :aria-label="copy.configuration">
                    <div class="product-show__price"><span>{{ t('price') }}</span><div><strong>{{ formatPrice(variant?.price ?? null, variant?.currency) }}</strong><del v-if="hasDiscount">{{ formatPrice(variant.compare_at_price, variant.currency) }}</del></div></div>
                    <fieldset v-if="product.variants.length > 1" class="product-show__variant-picker">
                        <legend>{{ copy.configuration }}</legend>
                        <label v-for="(item, index) in product.variants" :key="item.id" :class="{ active: selectedVariant === index }">
                            <input v-model="selectedVariant" type="radio" name="product-variant" :value="index" @change="selectVariant" />
                            <span>{{ item.name || item.sku || item.color || `#${item.id}` }}</span><span class="product-show__variant-check" aria-hidden="true">✓</span>
                        </label>
                    </fieldset>
                    <div v-else-if="variant?.name" class="product-show__configuration"><span>{{ copy.configuration }}</span><strong>{{ variant.name }}</strong></div>
                    <div v-if="variant?.sku || variant?.warranty_months" class="product-show__offer-meta"><span v-if="variant?.sku">SKU <b>{{ variant.sku }}</b></span><span v-if="variant?.warranty_months">{{ copy.warranty }} <b>{{ variant.warranty_months }} {{ copy.months }}</b></span></div>
                </section>
                <section v-if="highlights.length" class="product-show__highlights" :aria-label="copy.highlights">
                    <div class="product-show__section-heading"><h2>{{ copy.highlights }}</h2><button type="button" @click="jumpTo('product-specifications')">{{ copy.allSpecs }} <span aria-hidden="true">↗</span></button></div>
                    <dl><div v-for="attribute in highlights" :key="attribute.key"><dt>{{ attributeLabel(attribute) }}</dt><dd>{{ attributeValue(attribute) }}<small v-if="attributeUnit(attribute)"> {{ attributeUnit(attribute) }}</small></dd></div></dl>
                </section>
                <nav class="product-show__section-nav" :aria-label="copy.onPage">
                    <button v-if="description" type="button" @click="jumpTo('product-description')">{{ copy.description }} <span aria-hidden="true">↓</span></button>
                    <button v-if="allAttributes.length" type="button" @click="jumpTo('product-specifications')">{{ copy.specifications }} <span aria-hidden="true">↓</span></button>
                    <button v-if="facts.length" type="button" @click="jumpTo('product-details')">{{ copy.details }} <span aria-hidden="true">↓</span></button>
                </nav>
                <section v-if="description" id="product-description" class="product-show__section" tabindex="-1">
                    <div class="product-show__section-heading"><h2>{{ copy.description }}</h2></div>
                    <div class="product-show__description"><p v-for="(paragraph, index) in paragraphs" :key="index">{{ paragraph }}</p></div>
                </section>
                <section v-if="allAttributes.length" id="product-specifications" class="product-show__section" tabindex="-1">
                    <div class="product-show__section-heading"><h2>{{ copy.specifications }}</h2><span class="product-show__attribute-count">{{ allAttributes.length }}</span></div>
                    <div v-for="block in attributeBlocks" :key="block.key" class="product-show__spec-group" :role="block.group ? 'group' : undefined" :aria-labelledby="block.group ? `product-specs-${block.key}` : undefined">
                        <h3 v-if="block.group" :id="`product-specs-${block.key}`">{{ attributeGroupName(block.group) }}</h3>
                        <dl class="product-show__specs"><div v-for="attribute in block.attributes" :key="attribute.key"><dt>{{ attributeLabel(attribute) }}</dt><dd>{{ attributeValue(attribute) }}<small v-if="attributeUnit(attribute)"> {{ attributeUnit(attribute) }}</small></dd></div></dl>
                    </div>
                </section>
                <section v-if="facts.length" id="product-details" class="product-show__section" tabindex="-1">
                    <div class="product-show__section-heading"><h2>{{ copy.details }}</h2></div>
                    <dl class="product-show__specs"><div v-for="[label, value] in facts" :key="label"><dt>{{ label }}</dt><dd>{{ value }}</dd></div></dl>
                </section>
            </div>
        </div>
        <footer class="catalog-footer"><Link href="/catalog">{{ copy.back }} <span aria-hidden="true">↗</span></Link></footer>
    </main>
</template>
