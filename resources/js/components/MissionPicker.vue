<script setup>
import { computed } from 'vue';
import { kindLabel } from '../game';
import { useT } from '../i18n';

/**
 * Campaign mission picker: numbered bubbles (✅ won, ⭐ next up) and a card explaining the chosen
 * mission's rules for the current table size. `readonly` shows the choice to non-hosts.
 */
const props = defineProps({
    modelValue: { type: Number, required: true },
    options: { type: Object, required: true }, // { missions, detonator, completed, next }
    players: { type: Number, required: true },
    readonly: Boolean,
});

const emit = defineEmits(['update:modelValue']);
const t = useT();

const selected = computed(() => props.options.missions.find((mission) => mission.number === props.modelValue));
const mistakes = computed(() => {
    const table = props.options.detonator;
    const counts = Object.keys(table).map(Number);
    // The table may not have an entry for the current count (e.g. a host alone in the lobby): clamp into range
    const players = Math.min(Math.max(props.players, Math.min(...counts)), Math.max(...counts));
    return Math.max(1, table[players] + (selected.value?.detonatorBonus ?? 0));
});

function wires(color) {
    const { count, pool } = selected.value[color];
    if (!count) return null;
    return count === pool ? t(`mission.rule.${color}_known`, { count }) : t(`mission.rule.${color}_hidden`, { count, pool });
}

const rules = computed(() => {
    const mission = selected.value;
    if (!mission) return [];

    const list = [{ icon: '💥', text: t('mission.rule.detonator', { mistakes: mistakes.value }), tone: 'bg-berry-soft' }];
    const yellow = wires('yellow');
    const red = wires('red');
    if (!yellow && !red) list.push({ icon: '🔵', text: t('mission.rule.blue_only'), tone: 'bg-sky-soft' });
    if (yellow) list.push({ icon: '🟡', text: yellow, tone: 'bg-sun-soft' });
    if (red) list.push({ icon: '🔴', text: red, tone: 'bg-berry-soft' });
    if (mission.sequence.length) {
        list.push({ icon: '🔢', text: t('mission.rule.sequence', { steps: mission.sequence.map((kind) => kindLabel(t, kind)).join(' → ') }), tone: 'bg-lilac' });
    }
    list.push(
        mission.equipment.count
            ? { icon: '🧰', text: t('mission.rule.equipment', { count: mission.equipment.count, unlock: t(`mission.unlock.${mission.equipment.rule}`) }), tone: 'bg-mint-soft' }
            : { icon: '🧰', text: t('mission.rule.no_equipment'), tone: 'bg-cloud' },
    );

    return list;
});
</script>

<template>
    <div class="flex flex-col gap-3" data-testid="mission-picker">
        <div class="flex items-center justify-between">
            <span class="label">🗺️ {{ t('mission.label') }}</span>
            <span class="chip bg-mint-soft">✅ {{ t('mission.progress', { done: options.completed.length, total: options.missions.length }) }}</span>
        </div>

        <div class="grid grid-cols-8 gap-1.5" role="radiogroup" :aria-label="t('mission.label')">
            <button
                v-for="mission in options.missions"
                :key="mission.number"
                type="button"
                role="radio"
                class="relative flex aspect-square items-center justify-center rounded-full border-[3px] text-base font-bold transition-transform"
                :class="[
                    mission.number === modelValue
                        ? 'scale-110 border-ink bg-sun shadow-[0_3px_0_var(--color-ink)]'
                        : options.completed.includes(mission.number)
                          ? 'border-ink bg-mint'
                          : 'border-ink/40 bg-white',
                    readonly && mission.number !== modelValue ? 'opacity-50' : '',
                ]"
                :aria-checked="mission.number === modelValue"
                :aria-label="t('mission.title', { number: mission.number })"
                :disabled="readonly && mission.number !== modelValue"
                :data-mission="mission.number"
                @click="!readonly && emit('update:modelValue', mission.number)"
            >
                {{ mission.number }}
                <span v-if="options.completed.includes(mission.number)" class="absolute -top-1.5 -right-1.5 text-xs" aria-hidden="true">✅</span>
                <span v-else-if="mission.number === options.next" class="absolute -top-1.5 -right-1.5 text-xs" aria-hidden="true">⭐</span>
            </button>
        </div>

        <article v-if="selected" class="animate-pop rounded-2xl border-[3px] border-ink bg-cream p-3" :key="selected.number" data-testid="mission-card">
            <p class="flex flex-wrap items-center gap-2">
                <span class="chip bg-sun">{{ t('mission.title', { number: selected.number }) }}</span>
                <span class="text-lg font-bold">{{ t(`mission.${selected.number}.name`) }}</span>
                <span v-if="options.completed.includes(selected.number)" class="chip bg-mint">✅ {{ t('mission.completed') }}</span>
                <span v-else-if="selected.number === options.next" class="chip bg-sun-soft">⭐ {{ t('mission.next_up') }}</span>
            </p>
            <p class="mt-1 text-sm text-ink-soft">{{ t(`mission.${selected.number}.briefing`) }}</p>
            <ul class="mt-2 flex flex-wrap gap-1.5">
                <li v-for="rule in rules" :key="rule.text" class="chip whitespace-normal" :class="rule.tone">{{ rule.icon }} {{ rule.text }}</li>
            </ul>
        </article>
    </div>
</template>
