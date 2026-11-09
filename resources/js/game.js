/**
 * Shared bits of game vocabulary for the UI.
 */

// What a player names when cutting: a blue number, or 'yellow' / 'red' (translated)
export function kindLabel(t, kind) {
    return typeof kind === 'string' ? t(`game.kind.${kind}`) : String(kind);
}

export const EQUIPMENT_ICON = {
    label: '🏷️', // older games only
    label_different: '🏷️',
    label_equal: '🔖',
    walkie_talkies: '📻',
    triple_detector: '🔬',
    post_it: '📝',
    super_detector: '🛰️',
    rewinder: '⏪',
    emergency_batteries: '🔋',
    radar: '📡',
    stabilizer: '🛡️',
    xy_ray: '🩻',
    coffee_mug: '☕',
};

/*
 * How using each card works in the UI:
 *   none     straight away (after "Use it?")
 *   wire     one of your blue wires, then confirm          pair     two neighbouring wires of yours
 *   value    a number                                       swap     one of your wires, then a teammate
 *   detector three wires on one teammate's stand, then a kind (like the Double Detector)
 *   stand    a teammate's stand, then a kind               xy       a teammate's wire, then two kinds
 *   players  one or two players with a used Double Detector
 *   player   the player who goes next
 */
export const EQUIPMENT_INPUT = {
    label: 'pair',
    label_different: 'pair',
    label_equal: 'pair',
    walkie_talkies: 'swap',
    triple_detector: 'detector',
    post_it: 'wire',
    super_detector: 'stand',
    rewinder: 'none',
    emergency_batteries: 'players',
    radar: 'value',
    stabilizer: 'none',
    xy_ray: 'xy',
    coffee_mug: 'player',
};
