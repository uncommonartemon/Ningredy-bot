<script setup>
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';
import { A11y } from 'swiper/modules';
import { Swiper, SwiperSlide } from 'swiper/vue';
import 'swiper/css';
import { useI18n } from '../i18n';

const props = defineProps({ media: { type: Array, default: () => [] }, title: { type: String, default: '' } });
const { locale, t } = useI18n();
const labels = computed(() => ({
    cs: { gallery: 'Fotografie produktu', previous: 'Předchozí fotografie', next: 'Další fotografie', expand: 'Zvětšit fotografii', close: 'Zavřít galerii', photo: 'Fotografie', hint: 'Kliknutím zvětšíte', notice: 'Poznámka k fotografiím', differences: 'Rozdíly' },
    uk: { gallery: 'Фотографії товару', previous: 'Попереднє фото', next: 'Наступне фото', expand: 'Збільшити фото', close: 'Закрити галерею', photo: 'Фото', hint: 'Натисніть, щоб збільшити', notice: 'Примітка до фотографій', differences: 'Відмінності' },
    en: { gallery: 'Product photos', previous: 'Previous photo', next: 'Next photo', expand: 'Enlarge photo', close: 'Close gallery', photo: 'Photo', hint: 'Click to enlarge', notice: 'Photo details', differences: 'Differences' },
}[locale.value]));
const selected = ref(0);
const swiper = ref(null);
const thumbnails = ref(null);
const dialog = ref(null);
const closeButton = ref(null);
const isOpen = ref(false);
const failed = ref(new Set());
const current = computed(() => props.media[selected.value]);
let previousOverflow;
let opener;
const imageFailed = (id) => { failed.value = new Set([...failed.value, id]); };
const rememberSwiper = (value) => { swiper.value = value; };
const select = (index) => {
    selected.value = Math.max(0, Math.min(index, props.media.length - 1));
    swiper.value?.slideTo(selected.value, window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 0 : 320);
};
const onSlide = (value) => { selected.value = value.activeIndex; };
watch(selected, async () => {
    await nextTick();
    const strip = thumbnails.value;
    const thumb = strip?.children[selected.value];
    if (thumb) strip.scrollTo({ left: thumb.offsetLeft - strip.offsetLeft - (strip.clientWidth - thumb.clientWidth) / 2, behavior: 'instant' });
});
const open = () => {
    if (!current.value || failed.value.has(current.value.id)) return;
    opener = document.activeElement;
    previousOverflow = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
    isOpen.value = true;
    dialog.value.showModal();
    closeButton.value?.focus();
};
const afterClose = () => {
    if (!isOpen.value) return;
    isOpen.value = false;
    document.body.style.overflow = previousOverflow ?? '';
    opener?.focus({ preventScroll: true });
};
const close = () => dialog.value?.close();
const keys = (event) => {
    if (isOpen.value && event.key === 'Tab') {
        const buttons = [...dialog.value.querySelectorAll('button:not([disabled])')];
        const first = buttons[0];
        const last = buttons.at(-1);
        if (event.shiftKey && document.activeElement === first) {
            event.preventDefault(); last?.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault(); first?.focus();
        }
    }
    if (event.key === 'ArrowLeft' || event.key === 'ArrowRight') {
        event.preventDefault();
        select(selected.value + (event.key === 'ArrowLeft' ? -1 : 1));
    }
};
onBeforeUnmount(() => { if (isOpen.value) document.body.style.overflow = previousOverflow ?? ''; });
</script>

<template>
    <section class="product-gallery" :aria-label="labels.gallery" @keydown="keys">
        <div class="product-gallery__frame">
            <Swiper v-if="media.length" :modules="[A11y]" :slides-per-view="1" :space-between="16" :a11y="{ containerMessage: labels.gallery }" class="product-gallery__swiper" @swiper="rememberSwiper" @slide-change="onSlide">
                <SwiperSlide v-for="(image, index) in media" :key="image.id" :aria-hidden="selected !== index">
                    <button v-if="!failed.has(image.id)" class="product-gallery__image" type="button" :aria-label="`${labels.expand}: ${image.alt || title}`" :tabindex="selected === index ? 0 : -1" @click="open">
                        <img :src="image.url" :alt="image.alt || title" :loading="index === 0 ? 'eager' : 'lazy'" decoding="async" @error="imageFailed(image.id)" />
                    </button>
                    <div v-else class="product-gallery__placeholder"><span aria-hidden="true">◇</span>{{ t('photoUnavailable') }}</div>
                </SwiperSlide>
            </Swiper>
            <div v-else class="product-gallery__placeholder"><svg viewBox="0 0 48 48" aria-hidden="true"><rect x="6" y="9" width="36" height="30" rx="4" /><circle cx="17" cy="19" r="3" /><path d="m7 34 11-10 8 7 7-6 9 9" /></svg>{{ t('photoUnavailable') }}</div>
            <button v-if="current && !failed.has(current.id)" class="product-gallery__expand" type="button" :aria-label="labels.expand" @click="open"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 4H4v5M15 4h5v5M4 15v5h5M20 15v5h-5" /></svg></button>
        </div>
        <div v-if="media.length" class="product-gallery__controls">
            <div class="product-gallery__caption"><span>{{ labels.gallery }}</span><span class="product-gallery__count" role="status" aria-live="polite">{{ String(selected + 1).padStart(2, '0') }} <i>/ {{ String(media.length).padStart(2, '0') }}</i></span></div>
            <div v-if="media.length > 1" class="product-gallery__arrows"><button type="button" :aria-label="labels.previous" :disabled="selected === 0" @click="select(selected - 1)">←</button><button type="button" :aria-label="labels.next" :disabled="selected === media.length - 1" @click="select(selected + 1)">→</button></div>
            <span v-else class="product-gallery__hint">{{ labels.hint }}</span>
        </div>
        <div v-if="media.length > 1" ref="thumbnails" class="product-gallery__thumbs" :aria-label="labels.gallery">
            <button v-for="(image, index) in media" :key="image.id" type="button" :class="{ active: selected === index }" :aria-label="`${labels.photo} ${index + 1}`" :aria-pressed="selected === index" @click="select(index)"><img v-if="!failed.has(image.id)" :src="image.url" alt="" loading="lazy" @error="imageFailed(image.id)" /><span v-else>{{ index + 1 }}</span></button>
        </div>
        <aside v-if="current?.photo_notice" class="product-gallery__notice" aria-live="polite">
            <p>{{ labels.notice }}<template v-if="current.photo_notice.source_model">: <strong>{{ current.photo_notice.source_model }}</strong></template>.</p>
            <p v-if="current.photo_notice.differences?.length">{{ labels.differences }}: {{ current.photo_notice.differences.join(' ') }}</p>
        </aside>
        <dialog ref="dialog" class="product-lightbox" :aria-label="`${labels.gallery}: ${title}`" @close="afterClose" @keydown.stop="keys" @click="($event.target === dialog) && close()">
            <div class="product-lightbox__header"><div><span>{{ title }}</span><small>{{ labels.photo }} {{ selected + 1 }} / {{ media.length }}</small></div><button ref="closeButton" type="button" :aria-label="labels.close" @click="close">×</button></div>
            <div class="product-lightbox__image"><img v-if="current && !failed.has(current.id)" :src="current.url" :alt="current.alt || title" @error="imageFailed(current.id)" /><p v-else>{{ t('photoUnavailable') }}</p></div>
            <aside v-if="current?.photo_notice" class="product-gallery__notice" aria-live="polite">
                <p>{{ labels.notice }}<template v-if="current.photo_notice.source_model">: <strong>{{ current.photo_notice.source_model }}</strong></template>.</p>
                <p v-if="current.photo_notice.differences?.length">{{ labels.differences }}: {{ current.photo_notice.differences.join(' ') }}</p>
            </aside>
            <div v-if="media.length > 1" class="product-lightbox__controls"><button type="button" :aria-label="labels.previous" :disabled="selected === 0" @click="select(selected - 1)">← <span>{{ labels.previous }}</span></button><span role="status" aria-live="polite">{{ selected + 1 }} / {{ media.length }}</span><button type="button" :aria-label="labels.next" :disabled="selected === media.length - 1" @click="select(selected + 1)"><span>{{ labels.next }}</span> →</button></div>
        </dialog>
    </section>
</template>
