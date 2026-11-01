<script setup>
import { computed } from 'vue';
import { EQUIPMENT_ICON, kindLabel } from '../../game';
import { useT } from '../../i18n';

const props = defineProps({
    log: { type: Array, required: true },
    names: { type: Object, required: true },
});

const t = useT();

/**
 * Log entries are structured data from the server; turning them into sentences here keeps
 * them in the reader's language.
 */
function describe(entry) {
    const name = (id) => props.names[id] ?? '?';

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

            return t(`game.log.${key}`, {
                actor: name(entry.actor),
                target: name(entry.target),
                position: entry.index + 1,
                value: kindLabel(t, entry.value),
                actual: kindLabel(t, entry.actual ?? ''),
            });
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
        case 'detector_reveal':
            return t('game.log.detector_reveal', { player: name(entry.player), position: entry.index + 1, value: kindLabel(t, entry.value) });
        case 'undo':
            return t('game.log.undo', { actor: name(entry.actor) });
        case 'pass':
            return t('game.log.pass', { actor: name(entry.actor) });
        case 'reveal_red':
            return t('game.log.reveal_red', { actor: name(entry.actor), count: entry.count });
        case 'equipment': {
            const params = { actor: name(entry.actor), position: entry.index + 1, next: entry.index + 2, value: entry.value };

            if (entry.id === 'label') return t(`game.log.equipment.label_${entry.equal ? 'equal' : 'different'}`, params);
            if (entry.id === 'radar') {
                params.holders = entry.holders.length ? entry.holders.map(name).join(', ') : t('game.log.radar_nobody');
            }

            return t(`game.log.equipment.${entry.id}`, params);
        }
        default:
            return t(`game.log.${entry.type}`);
    }
}

function icon(entry) {
    if (entry.type === 'lost') return '💥';
    if (entry.type === 'won') return '🎉';
    if (entry.type === 'dual_cut') return entry.success ? '✂️' : entry.actual === 'red' ? '🔴' : entry.stabilized ? '🛡️' : '😬';
    if (entry.type === 'solo_cut') return '🌟';
    if (entry.type === 'reveal_red') return '🔴';
    if (entry.type === 'pass') return '⏭️';
    if (entry.type === 'double_detector') return entry.success ? '🔍' : entry.stabilized ? '🛡️' : '😬';
    if (entry.type === 'detector_reveal') return '👀';
    if (entry.type === 'equipment') return EQUIPMENT_ICON[entry.id] ?? '🧰';
    if (entry.type === 'info') return '💡';
    if (entry.type === 'undo') return '↩️';
    return '🚀';
}

function tone(entry) {
    const cut = entry.type === 'dual_cut' || entry.type === 'double_detector';
    if (entry.type === 'lost' || (cut && !entry.success && !entry.stabilized)) return 'bg-berry-soft';
    if (entry.type === 'won' || entry.type === 'solo_cut' || (cut && entry.success)) return 'bg-mint-soft';
    if (entry.type === 'equipment') return 'bg-lilac';
    return 'bg-cloud';
}

const entries = computed(() => [...props.log].reverse());
</script>

<template>
    <ol class="max-h-72 space-y-1.5 overflow-y-auto overscroll-contain text-sm" data-testid="log" :data-length="log.length">
        <li
            v-for="(entry, i) in entries"
            :key="log.length - i"
            class="flex gap-2 rounded-xl px-2.5 py-1.5"
            :class="[tone(entry), i === 0 ? 'animate-pop border-2 border-ink font-semibold' : 'opacity-80']"
        >
            <span aria-hidden="true">{{ icon(entry) }}</span>
            <span>{{ describe(entry) }}</span>
        </li>
    </ol>
</template>
