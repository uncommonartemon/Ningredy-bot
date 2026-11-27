import assert from 'node:assert/strict';
import test from 'node:test';
import { configureCatalogLocales, useI18n } from '../../resources/js/i18n.js';

// Decision of 2026-11-27: a buyer reads a card only in the shop's languages. A configuration's name
// and colour and a brand's country stood on the page as stored, in English or Russian.
test('a configuration\'s name and colour and a brand\'s country are read in the visitor\'s language', () => {
    configureCatalogLocales({ storefront: ['cs', 'uk'], content: ['cs', 'uk'] });
    const { locale, variantText, countryName } = useI18n();
    const variant = {
        name: 'Matte black', color: 'Matte black',
        translations: { cs: { title: 'Notebook', name: 'Matně černá', color: 'Matně černá' }, uk: { name: 'Матовий чорний', color: 'Матовий чорний' } },
    };
    const brand = { country: 'Тайвань', country_translations: { cs: 'Tchaj-wan', uk: 'Тайвань', en: 'Taiwan' } };

    locale.value = 'cs';
    assert.equal(variantText(variant, 'color'), 'Matně černá');
    assert.equal(countryName(brand), 'Tchaj-wan');
    locale.value = 'uk';
    assert.equal(variantText(variant, 'name'), 'Матовий чорний');
    assert.equal(countryName(brand), 'Тайвань');
    // Without a translation the stored words are the last resort, never another field.
    assert.equal(variantText({ color: 'Silver', translations: {} }, 'color'), 'Silver');
    assert.equal(variantText({ translations: {} }, 'color'), null);
    assert.equal(countryName({ country: null }), '');
});

// «16» with the unit «ГБ» typed in Cyrillic is translated with its unit; the translation carries
// the unit, so the page does not add the Russian one after it.
test('a bare number translated with its unit is not followed by the stored unit', () => {
    configureCatalogLocales({ storefront: ['cs', 'uk'], content: ['cs', 'uk'] });
    const { locale, attributeValue, attributeUnit } = useI18n();
    locale.value = 'cs';
    const typed = { value: '512', unit: 'ГБ', translations: { cs: '512 GB', uk: '512 ГБ' } };
    assert.equal(attributeValue(typed), '512 GB');
    assert.equal(attributeUnit(typed), null);
    // A number with a Latin unit has no translation and keeps its unit.
    const plain = { value: '16', unit: 'GB', translations: {} };
    assert.equal(attributeValue(plain), '16');
    assert.equal(attributeUnit(plain), 'GB');
});

// The storefront switches its language without a reload: the page holds every language the
// server sent, and the same objects read again in the new language at once.
test('switching the language reads the same page data again in the new language', () => {
    configureCatalogLocales({ storefront: ['cs', 'uk'], content: ['cs', 'uk'] });
    const { locale, setLocale, translatedText, attributeValue, categoryName, facetOptionLabel } = useI18n();
    const card = { cs: { title: 'Notebook Lenovo' }, uk: { title: 'Ноутбук Lenovo' } };
    const value = { value: 'Up to 16 GB', translations: { cs: 'Až 16 GB', uk: 'До 16 ГБ' } };
    const category = { name: 'Ноутбуки', translations: { cs: 'Notebooky', uk: 'Ноутбуки', en: 'Laptops' } };
    const colour = { label: 'Black', value: 'Black', translations: { cs: 'Černá', uk: 'Чорна' } };

    setLocale('cs');
    assert.deepEqual([translatedText(card, 'title'), attributeValue(value), categoryName(category), facetOptionLabel(colour)],
        ['Notebook Lenovo', 'Až 16 GB', 'Notebooky', 'Černá']);
    setLocale('uk');
    assert.equal(locale.value, 'uk');
    assert.deepEqual([translatedText(card, 'title'), attributeValue(value), categoryName(category), facetOptionLabel(colour)],
        ['Ноутбук Lenovo', 'До 16 ГБ', 'Ноутбуки', 'Чорна']);
});
