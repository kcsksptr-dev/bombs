/**
 * Shared bits of game vocabulary for the UI.
 */

// What a player names when cutting: a blue number, or 'yellow' / 'red' (translated)
export function kindLabel(t, kind) {
    return typeof kind === 'string' ? t(`game.kind.${kind}`) : String(kind);
}

export const EQUIPMENT_ICON = {
    label: '🏷️',
    post_it: '📝',
    radar: '📡',
    stabilizer: '🛡️',
    rewinder: '⏪',
};

// How using each card works in the UI: straight away, by picking one of your wires, or a number
export const EQUIPMENT_INPUT = {
    label: 'pair',
    post_it: 'wire',
    radar: 'value',
    stabilizer: 'none',
    rewinder: 'none',
};
