import assert from 'node:assert/strict';
import test from 'node:test';
import { configureCatalogLocales, useI18n } from '../../resources/js/i18n.js';

// The remark about the keyboard in the photos stood under the gallery in Russian, as the
// reviewer wrote it for the operator, and twice when two frames were described in two wordings.
// The server now sends facts and the page says them once, in the visitor's language.
test('a photo notice is said in the visitor\'s language, one line per fact', () => {
    configureCatalogLocales({ storefront: ['cs', 'uk', 'en'], content: ['cs', 'uk'] });
    const { locale, photoNoticeLines } = useI18n();
    const notice = { source_model: '', other_configuration: false, keyboard: 'unverified' };

    locale.value = 'cs';
    assert.deepEqual(photoNoticeLines(notice), ['Rozložení klávesnice na fotografiích nebylo možné porovnat s rozložením tohoto produktu.']);
    locale.value = 'uk';
    assert.deepEqual(photoNoticeLines(notice), ['Розкладку клавіатури на фото не вдалося звірити з розкладкою цього товару.']);
    locale.value = 'en';
    assert.deepEqual(photoNoticeLines({ ...notice, keyboard: 'differs', other_configuration: true, markings_may_differ: true }), [
        'The photos show another configuration in the same body; this product’s configuration is in its specifications.',
        'The keyboard layout in the photos differs from this product’s.',
        'Processor or graphics stickers in the photos may differ from the product configuration.',
    ]);

    // Every language has every line; nothing falls back to a key or to free text.
    for (const code of ['cs', 'uk', 'en']) {
        locale.value = code;
        const lines = photoNoticeLines({ other_configuration: true, keyboard: 'differs', markings_may_differ: true, differences: ['Раскладка на фото неразборчива'] });
        assert.equal(lines.length, 3);
        assert.ok(lines.every((line) => ! line.startsWith('photoNotice.') && ! /[ыэъ]|Раскладка/u.test(line)));
    }
    assert.deepEqual(photoNoticeLines(null), []);
    assert.deepEqual(photoNoticeLines({ keyboard: 'something_new' }), []);
});
