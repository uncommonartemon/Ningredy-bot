const order = (value) => Number.isFinite(Number(value)) ? Number(value) : 0;
const compareText = (left, right) => {
    const a = Array.from(String(left ?? ''), (character) => character.codePointAt(0));
    const b = Array.from(String(right ?? ''), (character) => character.codePointAt(0));
    for (let index = 0; index < Math.min(a.length, b.length); index++) {
        if (a[index] !== b[index]) return a[index] - b[index];
    }
    return a.length - b.length;
};
const compareAttributes = (left, right) => order(left.sort_order) - order(right.sort_order)
    || compareText(left.key, right.key);
const compareGroups = (left, right) => order(left.group.sort_order) - order(right.group.sort_order)
    || compareText(String(left.group.name).toLowerCase(), String(right.group.name).toLowerCase())
    || order(left.group.id) - order(right.group.id);

// Groups are presentation metadata, never an additional filter or a recursive
// hierarchy. Preserve the server's regular order for attributes without a group.
export const groupAttributeBlocks = (attributes = []) => {
    const groups = new Map();
    const ungrouped = [];
    for (const attribute of attributes) {
        const group = attribute.group;
        if (group?.id === null || group?.id === undefined || !String(group.name ?? '').trim()) {
            ungrouped.push(attribute);
            continue;
        }
        const key = String(group.id);
        if (!groups.has(key)) groups.set(key, { key: `group-${key}`, group, attributes: [] });
        groups.get(key).attributes.push(attribute);
    }
    const blocks = [...groups.values()].sort(compareGroups).map((block) => ({
        ...block, attributes: [...block.attributes].sort(compareAttributes),
    }));
    if (ungrouped.length) blocks.push({ key: 'ungrouped', group: null, attributes: ungrouped });
    return blocks;
};

export const orderedAttributes = (attributes = []) => groupAttributeBlocks(attributes).flatMap((block) => block.attributes);
