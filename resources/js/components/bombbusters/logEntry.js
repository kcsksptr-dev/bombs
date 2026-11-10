import { EQUIPMENT_ICON, kindLabel } from '../../bombbusters/game';

/**
 * Log entries are structured data from the server; turning them into sentences here keeps
 * them in the reader's language.
 */
export function describeEntry(t, names, entry) {
    const name = (id) => names[id] ?? '?';

    switch (entry.type) {
        case 'info':
            return t('game.log.info', { player: name(entry.player), value: entry.value });
        case 'begin':
            return t('game.log.begin', { player: name(entry.player) });
        case 'dual_cut': {
            const key = entry.success
                ? 'dual_cut_success'
                : entry.actual === 'red'
                  ? 'dual_cut_red'
                  : entry.stabilized
                    ? 'dual_cut_stabilized'
                    : 'dual_cut_fail';

            const sentence = t(`game.log.${key}`, {
                actor: name(entry.actor),
                target: name(entry.target),
                position: entry.index + 1,
                // The X or Y Ray names two kinds; on a hit `value` is the one that matched
                value: entry.values && !entry.success ? entry.values.map((kind) => kindLabel(t, kind)).join(' / ') : kindLabel(t, entry.value),
                actual: kindLabel(t, entry.actual ?? ''),
            });

            return entry.equipment ? `${sentence} (${EQUIPMENT_ICON[entry.equipment]} ${t(`equipment.${entry.equipment}.name`)})` : sentence;
        }
        case 'solo_cut':
            return t('game.log.solo_cut', { actor: name(entry.actor), value: kindLabel(t, entry.value), count: entry.count });
        case 'double_detector': {
            const key = entry.success ? 'double_detector_success' : entry.stabilized ? 'double_detector_stabilized' : 'double_detector_fail';

            return t(`game.log.${key}`, {
                actor: name(entry.actor),
                target: name(entry.target),
                first: entry.indices[0] + 1,
                second: entry.indices[1] + 1,
                position: (entry.index ?? 0) + 1,
                value: kindLabel(t, entry.value),
            });
        }
        case 'triple_detector':
        case 'super_detector': {
            const key = entry.success ? 'detector_many_success' : entry.stabilized ? 'detector_many_stabilized' : 'detector_many_fail';

            return t(`game.log.${key}`, {
                card: t(`equipment.${entry.type}.name`),
                actor: name(entry.actor),
                target: name(entry.target),
                positions: entry.type === 'super_detector' ? t('game.log.whole_stand') : entry.indices.map((i) => `#${i + 1}`).join(', '),
                position: (entry.index ?? 0) + 1,
                value: kindLabel(t, entry.value),
            });
        }
        case 'walkie_swap':
            return t('game.log.walkie_swap', { actor: name(entry.actor), player: name(entry.player) });
        case 'detector_reveal':
            return t('game.log.detector_reveal', { player: name(entry.player), position: entry.index + 1, value: kindLabel(t, entry.value) });
        case 'equipment_cancel':
            return t('game.log.equipment_cancel', { actor: name(entry.actor), card: t(`equipment.${entry.id}.name`) });
        case 'undo':
            return t('game.log.undo', { actor: name(entry.actor) });
        case 'pass':
            return t('game.log.pass', { actor: name(entry.actor) });
        case 'reveal_red':
            return t('game.log.reveal_red', { actor: name(entry.actor), count: entry.count });
        case 'equipment': {
            const params = {
                actor: name(entry.actor),
                position: entry.index + 1,
                next: entry.index + 2,
                value: entry.value,
                target: name(entry.target),
                player: name(entry.player),
                players: (entry.players ?? []).map(name).join(', '),
            };

            if (['label', 'label_equal', 'label_different'].includes(entry.id)) return t(`game.log.equipment.label_${entry.equal ? 'equal' : 'different'}`, params);
            if (entry.id === 'radar') {
                params.holders = entry.holders.length ? entry.holders.map(name).join(', ') : t('game.log.radar_nobody');
            }

            return t(`game.log.equipment.${entry.id}`, params);
        }
        default:
            return t(`game.log.${entry.type}`);
    }
}

export function entryIcon(entry) {
    if (entry.type === 'lost') return '💥';
    if (entry.type === 'won') return '🎉';
    if (entry.type === 'dual_cut') return entry.success ? '✂️' : entry.actual === 'red' ? '🔴' : entry.stabilized ? '🛡️' : '😬';
    if (entry.type === 'solo_cut') return '🌟';
    if (entry.type === 'reveal_red') return '🔴';
    if (entry.type === 'pass') return '⏭️';
    if (['double_detector', 'triple_detector', 'super_detector'].includes(entry.type)) return entry.success ? '🔍' : entry.stabilized ? '🛡️' : '😬';
    if (entry.type === 'walkie_swap') return '📻';
    if (entry.type === 'detector_reveal') return '👀';
    if (entry.type === 'equipment') return EQUIPMENT_ICON[entry.id] ?? '🧰';
    if (entry.type === 'info') return '💡';
    if (entry.type === 'undo' || entry.type === 'equipment_cancel') return '↩️';
    return '🚀';
}

export function entryTone(entry) {
    const cut = ['dual_cut', 'double_detector', 'triple_detector', 'super_detector'].includes(entry.type);
    if (entry.type === 'lost' || (cut && !entry.success && !entry.stabilized)) return 'bg-berry-soft';
    if (entry.type === 'won' || entry.type === 'solo_cut' || (cut && entry.success)) return 'bg-mint-soft';
    if (['equipment', 'equipment_cancel', 'walkie_swap'].includes(entry.type)) return 'bg-lilac';
    return 'bg-cloud';
}
