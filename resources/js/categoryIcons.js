// One set of line icons for categories: a 24-unit grid, one stroke width, round joins,
// the same hand as the line laptop behind the start page. A category names its icon in its
// `icon` field; without one its address is tried (the starter kinds are addressed `ssd`,
// `ram`, `cpu`…), then its parents', and a plain box stands for anything unknown.
export const CATEGORY_ICONS = {
    laptop: ['M5 5.5h14a1 1 0 0 1 1 1V16H4V6.5a1 1 0 0 1 1-1z', 'M2.5 16h19l-1.2 2.5H3.7z'],
    computer: ['M7 3.5h10a1 1 0 0 1 1 1v15a1 1 0 0 1-1 1H7a1 1 0 0 1-1-1v-15a1 1 0 0 1 1-1z', 'M9 7h6', 'M9 10h6', 'M12 15.2a1.3 1.3 0 1 0 0 2.6 1.3 1.3 0 0 0 0-2.6z'],
    component: ['M7 7h10v10H7z', 'M10 4v3M14 4v3M10 17v3M14 17v3M4 10h3M4 14h3M17 10h3M17 14h3'],
    devices: ['M4.5 4.5h10a1 1 0 0 1 1 1v13a1 1 0 0 1-1 1h-10a1 1 0 0 1-1-1v-13a1 1 0 0 1 1-1z', 'M15.5 9h4a1 1 0 0 1 1 1v8.5a1 1 0 0 1-1 1h-4', 'M9.5 16.8h.01'],
    ssd: ['M2.5 9H21v2.2h-1.2v1.6H21V15H2.5z', 'M5.5 10.8h4v2.4h-4z', 'M11.5 10.8h4v2.4h-4z', 'M17.8 12h.01'],
    hdd: ['M5 3.5h14a1 1 0 0 1 1 1v15a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1v-15a1 1 0 0 1 1-1z', 'M12 6.5a4.5 4.5 0 1 0 0 9 4.5 4.5 0 0 0 0-9z', 'M12 11h.01', 'M15.8 17.6l-2.7-4.6'],
    ram: ['M2.5 7.5h19v8h-19z', 'M5 9.5h3V13H5zM10.5 9.5h3V13h-3zM16 9.5h3V13h-3z', 'M5 15.5V18M8 15.5V18M11 15.5V18M14 15.5V18M17 15.5V18M19 15.5V18'],
    cpu: ['M5.5 5.5h13v13h-13z', 'M9 9h6v6H9z', 'M8 3v2.5M12 3v2.5M16 3v2.5M8 18.5V21M12 18.5V21M16 18.5V21M3 8h2.5M3 12h2.5M3 16h2.5M18.5 8H21M18.5 12H21M18.5 16H21'],
    gpu: ['M3 5v14', 'M3 7h17.5v9.5H3', 'M8.5 9.2a2.6 2.6 0 1 0 0 5.2 2.6 2.6 0 0 0 0-5.2z', 'M15.5 9.2a2.6 2.6 0 1 0 0 5.2 2.6 2.6 0 0 0 0-5.2z', 'M6 16.5v2h7v-2'],
    monitor: ['M3.5 4.5h17V16h-17z', 'M12 16v3.5', 'M8 19.5h8'],
    motherboard: ['M4 4h16v16H4z', 'M7 7h5v5H7z', 'M15 7v6M17.5 7v6', 'M7 15.5h6M7 17.5h6', 'M16.8 16.5h.01'],
    psu: ['M3.5 6h17v12h-17z', 'M9.5 8.5a3.5 3.5 0 1 0 0 7 3.5 3.5 0 0 0 0-7z', 'M9.5 12h.01', 'M15.5 10h3v4h-3z'],
    box: ['M4 8l8-4 8 4v8l-8 4-8-4z', 'M4 8l8 4 8-4', 'M12 12v8'],
};

// A name as written in a category (`Laptops`, `other-tech`, `graphics_card`) to a key of the set.
const SAME = { laptops: 'laptop', notebook: 'laptop', notebooks: 'laptop', desktop: 'computer', computers: 'computer', pc: 'computer',
    components: 'component', 'other-tech': 'devices', memory: 'ram', processor: 'cpu', processors: 'cpu', 'graphics-card': 'gpu',
    'graphics-cards': 'gpu', display: 'monitor', monitors: 'monitor', motherboards: 'motherboard', 'power-supply': 'psu' };

/**
 * The first name of the chain the set can draw.
 * @param {(string|null|undefined)[]} names the category's own icon and address, then its parents'
 * @returns {string} a key of CATEGORY_ICONS
 */
export function categoryIcon(names = []) {
    for (const name of names) {
        const key = String(name ?? '').trim().toLowerCase().replace(/[\s_]+/g, '-');
        if (CATEGORY_ICONS[key]) return key;
        if (SAME[key]) return SAME[key];
    }
    return 'box';
}
