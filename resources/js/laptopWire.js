// A laptop drawn as lines in space, for the start page's background. The page's scroll opens
// its lid, takes it apart into layers (lid, keyboard deck, board with its parts, bottom plate)
// and puts it back together. No 3D library: the model is a few hundred line segments and one
// perspective projection, cheap enough to redraw on every scroll frame.

const W = 3.2; // width (x), depth (z) and the back edge the lid turns on
const D = 2.2;
const HINGE = -D / 2;

const loop = (points) => points.map((point, index) => [point, points[(index + 1) % points.length]]);
const flat = (x0, z0, x1, z1, y) => loop([[x0, y, z0], [x1, y, z0], [x1, y, z1], [x0, y, z1]]);
const slab = (x0, z0, x1, z1, y0, y1) => [
    ...flat(x0, z0, x1, z1, y0), ...flat(x0, z0, x1, z1, y1),
    ...[[x0, z0], [x1, z0], [x1, z1], [x0, z1]].map(([x, z]) => [[x, y0, z], [x, y1, z]]),
];
const ring = (cx, cz, radius, y, sides = 20) => loop(Array.from({ length: sides }, (_, index) => {
    const angle = (index / sides) * Math.PI * 2;
    return [cx + Math.cos(angle) * radius, y, cz + Math.sin(angle) * radius];
}));
const lift = (segments, dy, dz = 0) => segments.map(([a, b]) => [[a[0], a[1] + dy, a[2] + dz], [b[0], b[1] + dy, b[2] + dz]]);
const tagged = (segments, tone, alpha = 1) => segments.map(([a, b]) => ({ a, b, tone, alpha }));

// Built once: these never change shape, only their height in the exploded view.
const BOTTOM = slab(-W / 2, -D / 2, W / 2, D / 2, -0.13, -0.09);
const DECK = [
    ...slab(-W / 2, -D / 2, W / 2, D / 2, -0.05, 0),
    ...flat(-0.55, 0.42, 0.55, 0.98, 0), // trackpad
    ...Array.from({ length: 5 }, (_, row) => Array.from({ length: 14 }, (_, column) => {
        const x = -1.44 + column * 0.206;
        const z = -0.86 + row * 0.235;
        return flat(x, z, x + 0.17, z + 0.19, 0);
    })).flat(2),
];
const PARTS = [
    { key: 'battery', label: null, lines: flat(-1.35, 0.25, 1.35, 0.95, 0) },
    { key: 'ssd', label: 'SSD', at: [-0.95, 0, -0.38], lines: [...flat(-1.35, -0.5, -0.55, -0.27, 0), ...flat(-1.31, -0.46, -1.05, -0.31, 0)] },
    { key: 'ram', label: 'RAM', at: [0.05, 0, -0.5], lines: [...flat(-0.35, -0.72, 0.45, -0.52, 0), ...flat(-0.35, -0.47, 0.45, -0.27, 0)] },
    { key: 'cpu', label: 'CPU', at: [0.95, 0, -0.36], lines: [...flat(0.72, -0.6, 1.18, -0.14, 0), ...flat(0.82, -0.5, 1.08, -0.24, 0)] },
    { key: 'fan', label: null, lines: [...ring(0.98, -0.82, 0.2, 0), ...ring(0.98, -0.82, 0.07, 0, 10), [[0.6, 0, -0.82], [-1.2, 0, -0.82]]] },
];
const BOARD = flat(-1.45, -0.98, 1.45, 0.15, 0);

// The lid in its own space: x across, y through its thickness, z from the hinge to its free edge.
const LID_LOCAL = [
    ...slab(-W / 2, 0, W / 2, D, 0, 0.06),
    ...flat(-W / 2 + 0.1, 0.1, W / 2 - 0.1, D - 0.14, 0), // the screen
];
const turnLid = ([x, y, z], angle) => [x, y * Math.cos(angle) + z * Math.sin(angle), HINGE + z * Math.cos(angle) - y * Math.sin(angle)];

/**
 * The laptop's lines for a pose.
 * @param {number} open lid angle in degrees: 0 closed, about 105 in use
 * @param {number} explode 0 assembled, 1 taken apart
 * @returns {{segments: {a: number[], b: number[], tone: string, alpha: number}[], labels: {text: string, at: number[], alpha: number}[]}}
 */
export function laptopLines(open, explode) {
    const angle = (Math.max(0, Math.min(open, 135)) * Math.PI) / 180;
    const e = Math.max(0, Math.min(explode, 1));
    const boardY = -0.07 - e * 0.42;
    const lid = LID_LOCAL.map(([a, b]) => [turnLid(a, angle), turnLid(b, angle)]);
    const partAlpha = Math.min(1, e * 1.6);

    return {
        segments: [
            ...tagged(lift(BOTTOM, -e * 1.05), 'line', 0.7),
            ...tagged(lift(BOARD, boardY), 'line', 0.35 + partAlpha * 0.4),
            ...PARTS.flatMap((part) => tagged(lift(part.lines, boardY), part.label ? 'accent' : 'line', partAlpha * (part.label ? 1 : 0.7))),
            ...tagged(lift(DECK, e * 0.42), 'line', 0.8),
            ...tagged(lift(lid, e * 1.0, -e * 0.25), 'accent', 0.95),
        ],
        labels: PARTS.filter((part) => part.label).map((part) => ({
            text: part.label, at: [part.at[0], boardY, part.at[2]], alpha: Math.max(0, (e - 0.45) / 0.55),
        })),
    };
}

/**
 * A point in the laptop's space onto the screen.
 * @param {number[]} point
 * @param {{yaw: number, pitch: number, size: number, cx: number, cy: number}} view size in pixels per unit at the origin
 * @returns {[number, number, number]} x, y and depth (larger is farther)
 */
export function project([x, y, z], view) {
    const cosYaw = Math.cos(view.yaw);
    const sinYaw = Math.sin(view.yaw);
    const x1 = x * cosYaw + z * sinYaw;
    const z1 = -x * sinYaw + z * cosYaw;
    const cosPitch = Math.cos(view.pitch);
    const sinPitch = Math.sin(view.pitch);
    const y1 = y * cosPitch - z1 * sinPitch;
    const z2 = y * sinPitch + z1 * cosPitch;
    const camera = 9;
    const scale = (camera / (camera + z2)) * view.size;

    return [view.cx + x1 * scale, view.cy - y1 * scale, z2];
}
