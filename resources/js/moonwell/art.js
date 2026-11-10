/**
 * Moonwell Garden's look, shared by every tile drawing. Tiles are drawn in a 60×60 box and scaled.
 * Each colour has its own shape, so the game also plays colour-blind. Colours must match
 * config/moonwell.php → colors.
 */
export const COLORS = {
    blue: { shape: 'circle', a: '#4fb8f2', b: '#2377c9', glyph: '●' },
    orange: { shape: 'triangle', a: '#ffb251', b: '#e0661a', glyph: '▲' },
    pink: { shape: 'diamond', a: '#ff7fa8', b: '#d1356d', glyph: '◆' },
    green: { shape: 'square', a: '#5fd99a', b: '#1c9560', glyph: '■' },
};

export const INK = '#16112e';

// Wishes fill the corners in this order (top-left, bottom-right, top-right, bottom-left)
export const CORNERS = [
    [12, 12],
    [48, 48],
    [48, 12],
    [12, 48],
];

// Where the 1–3 shapes of a wish sit inside its circle: [dx, dy, size]
export const SEEDS = {
    1: [[0, 0, 3.2]],
    2: [
        [-2.9, 0, 2.4],
        [2.9, 0, 2.4],
    ],
    3: [
        [0, -2.6, 2.15],
        [-3, 2.1, 2.15],
        [3, 2.1, 2.15],
    ],
};

// The big shape in the middle of a tile: [cx, cy, size], tuned to stay clear of the corners
export const CENTER = {
    circle: [30, 30, 12.5],
    square: [30, 30, 11],
    diamond: [30, 30, 12.5],
    triangle: [30, 31.8, 11.2],
};

/**
 * SVG element and attributes for a colour's shape centred on (cx, cy).
 */
export function glyph(color, cx, cy, r) {
    switch (COLORS[color]?.shape) {
        case 'square':
            return { tag: 'rect', attrs: { x: cx - r * 0.85, y: cy - r * 0.85, width: r * 1.7, height: r * 1.7, rx: r * 0.22 } };
        case 'diamond':
            return {
                tag: 'path',
                attrs: { d: `M${cx} ${cy - r * 1.12}L${cx + r * 1.12} ${cy}L${cx} ${cy + r * 1.12}L${cx - r * 1.12} ${cy}Z`, 'stroke-linejoin': 'round' },
            };
        case 'triangle':
            return {
                tag: 'path',
                attrs: { d: `M${cx} ${cy - r * 1.05}L${cx + r * 1.1} ${cy + r * 0.85}L${cx - r * 1.1} ${cy + r * 0.85}Z`, 'stroke-linejoin': 'round' },
            };
        default:
            return { tag: 'circle', attrs: { cx, cy, r } };
    }
}

// Pawn colours on the time path follow the seat avatars (resources/js/avatars.js)
export const SEAT_COLORS = ['#ffcb3d', '#ff5d8f', '#4cc3ff', '#ff9f43', '#4fdc9b', '#9d7bff', '#d4f0ff', '#ffe0ea'];

export function seatColor(seat) {
    return SEAT_COLORS[(Math.max(1, seat ?? 1) - 1) % SEAT_COLORS.length];
}
