import assert from 'node:assert/strict';
import test from 'node:test';
import { groupAttributeBlocks, orderedAttributes } from '../../resources/js/attributeGroups.js';

test('groups stay adjacent and follow group order before attribute order', () => {
    const storage = { id: 20, name: 'Storage', sort_order: 20, highlight_in_filters: false };
    const processor = { id: 10, name: 'Processor', sort_order: 10, highlight_in_filters: true };
    const attrs = [
        { key: 'ssd', sort_order: 1, group: storage },
        { key: 'plain', sort_order: 0, group: null },
        { key: 'cpu-cores', sort_order: 20, group: processor },
        { key: 'cpu-model', sort_order: 10, group: processor },
        { key: 'storage-type', sort_order: 0, group: storage },
    ];

    const blocks = groupAttributeBlocks(attrs);

    assert.deepEqual(blocks.map((block) => block.key), ['group-10', 'group-20', 'ungrouped']);
    assert.deepEqual(orderedAttributes(attrs).map((attr) => attr.key), [
        'cpu-model', 'cpu-cores', 'storage-type', 'ssd', 'plain',
    ]);
    assert.equal(blocks[0].group.highlight_in_filters, true);
    assert.equal(blocks[1].group.highlight_in_filters, false);
});

test('group sorting uses stable base names and IDs rather than translated labels', () => {
    const attrs = [
        { key: 'z', group: { id: 9, name: 'Beta', sort_order: 0, translations: { cs: 'A' } } },
        { key: 'a', group: { id: 3, name: 'Alpha', sort_order: 0, translations: { cs: 'Z' } } },
        { key: 'b', group: { id: 1, name: 'Alpha', sort_order: 0 } },
    ];

    assert.deepEqual(groupAttributeBlocks(attrs).map((block) => block.group.id), [1, 3, 9]);
});

test('equal sort orders use the same lexical numeric-looking keys as the server', () => {
    const first = { id: 2, name: 'Group 10', sort_order: 0 };
    const second = { id: 1, name: 'Group 2', sort_order: 0 };
    const attrs = [
        { key: 'port-2', group: first },
        { key: 'second-group-feature', group: second },
        { key: 'port-10', group: first },
    ];

    assert.deepEqual(orderedAttributes(attrs).map((attr) => attr.key), ['port-10', 'port-2', 'second-group-feature']);
});

test('ungrouped attributes keep their supplied order and never get an invented title', () => {
    const attrs = [{ key: 'z', sort_order: 5 }, { key: 'a', sort_order: 0, group: null }];
    const blocks = groupAttributeBlocks(attrs);

    assert.equal(blocks.length, 1);
    assert.equal(blocks[0].group, null);
    assert.deepEqual(orderedAttributes(attrs), attrs);
    assert.deepEqual(groupAttributeBlocks(), []);
    assert.deepEqual(orderedAttributes([]), []);
});

test('empty or missing group identities do not produce empty headings', () => {
    const attrs = [
        { key: 'no-id', group: { name: 'Unidentified group' } },
        { key: 'no-name', group: { id: 3, name: '   ' } },
        { key: 'null-id', group: { id: null, name: 'Missing group' } },
    ];

    assert.deepEqual(groupAttributeBlocks(attrs), [{ key: 'ungrouped', group: null, attributes: attrs }]);
});

test('group identity stays unique when JSON inputs use numeric or string IDs', () => {
    const attrs = [
        { key: 'one', sort_order: 0, group: { id: 7, name: 'Memory' } },
        { key: 'two', sort_order: 1, group: { id: '7', name: 'Memory' } },
    ];

    assert.equal(groupAttributeBlocks(attrs).length, 1);
    assert.deepEqual(orderedAttributes(attrs).map((attr) => attr.key), ['one', 'two']);
});

test('grouping preserves attribute values and never mutates caller arrays or metadata', () => {
    const group = Object.freeze({ id: 1, name: 'Memory', sort_order: 0 });
    const first = Object.freeze({ key: 'ram', value: '32 GB', sort_order: 5, group });
    const second = Object.freeze({ key: 'capacity', value: '512 GB', sort_order: 0, group });
    const attrs = Object.freeze([first, second]);

    const result = orderedAttributes(attrs);

    assert.deepEqual(result, [second, first]);
    assert.equal(result[0], second);
    assert.deepEqual(attrs, [first, second]);
});
