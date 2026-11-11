import assert from 'node:assert/strict';
import test from 'node:test';
import { configureCatalogLocales, useI18n } from '../../resources/js/i18n.js';

// Operator, 2026-11-11: Czech group names between Ukrainian filters. Every name the catalog
// shows follows one order of languages: the visitor's, the shop's main one, English, the stored name.
test('a category, a group and a characteristic follow the same order of languages', () => {
    configureCatalogLocales({ storefront: ['cs', 'uk'], content: ['cs', 'uk'] });
    const { locale, attributeGroupName, attributeLabel, facetLabel, categoryName } = useI18n();
    locale.value = 'uk';

    const named = { cs: 'Paměť', uk: 'Пам’ять', en: 'Memory' };
    assert.equal(attributeGroupName({ name: 'Memory', translations: named }), 'Пам’ять');
    assert.equal(categoryName({ name: 'Память', translations: named }), 'Пам’ять');
    assert.equal(attributeLabel({ key: 'memory_slots', label: 'Память', label_translations: named }), 'Пам’ять');
    assert.equal(facetLabel({ key: 'memory_slots', label: 'Память', label_translations: named }), 'Пам’ять');

    // Without the visitor's language: the shop's main language, for all four alike.
    const czechOnly = { cs: 'Paměť', en: 'Memory' };
    assert.equal(attributeGroupName({ name: 'Memory', translations: czechOnly }), 'Paměť');
    assert.equal(categoryName({ name: 'Память', translations: czechOnly }), 'Paměť');
    assert.equal(attributeLabel({ key: 'memory_slots', label: 'Память', label_translations: czechOnly }), 'Paměť');
    assert.equal(facetLabel({ key: 'memory_slots', label: 'Память', label_translations: czechOnly }), 'Paměť');

    // Then English, and only then the name as the admin typed it.
    assert.equal(categoryName({ name: 'Память', translations: { en: 'Memory' } }), 'Memory');
    assert.equal(attributeGroupName({ name: 'Memory', translations: {} }), 'Memory');
    assert.equal(categoryName({ name: 'Память', translations: {} }), 'Память');
});

test('a starter characteristic without a stored name takes the interface\'s own in the same order', () => {
    configureCatalogLocales({ storefront: ['cs', 'uk'], content: ['cs', 'uk'] });
    const { locale, attributeLabel, facetLabel } = useI18n();
    locale.value = 'uk';

    assert.equal(attributeLabel({ key: 'cpu', label: 'Процессор', label_translations: {} }), 'Процесор');
    assert.equal(facetLabel({ key: 'cpu', label: 'Процессор', label_translations: { cs: 'Procesor' } }), 'Процесор');
    // A stored name in the visitor's language comes before the interface's.
    assert.equal(facetLabel({ key: 'cpu', label: 'Процессор', label_translations: { uk: 'Чип' } }), 'Чип');
    assert.equal(attributeLabel({ key: 'unknown_key', label: '', label_translations: {} }), 'unknown_key');
});
