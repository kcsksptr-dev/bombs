<script setup>
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { EQUIPMENT_ICON, EQUIPMENT_INPUT } from '../../bombbusters/game';
import { useT } from '../../i18n';

/**
 * Every equipment card in the game (the `equipmentCatalog` shared prop, from
 * config/bombbusters.php), so players know all their options: what it does, how it's used, its
 * unlock number, and whether it's in this mission. Folded away by default.
 *
 * `inPlay`: ids known to be in the mission (or null when they're drawn at random); `random`: how
 * many are drawn at random.
 */
const props = defineProps({
    inPlay: { type: Array, default: null },
    random: { type: Number, default: 0 },
});

const page = usePage();
const t = useT();

const cards = computed(() =>
    Object.entries(page.props.equipmentCatalog ?? {}).map(([id, unlock]) => ({
        id,
        unlock,
        inPlay: props.inPlay?.includes(id) ?? false,
    })),
);
</script>

<template>
    <details class="group rounded-3xl border-[3px] border-line bg-white shadow-[0_5px_0_var(--color-line)]" data-testid="equipment-guide">
        <summary class="flex cursor-pointer list-none items-center gap-2 px-4 py-3 font-bold select-none [&::-webkit-details-marker]:hidden">
            <span aria-hidden="true">📖</span>
            <span class="flex-1">{{ t('equipment.guide_title', { count: cards.length }) }}</span>
            <span class="text-ink-soft transition-transform group-open:rotate-180" aria-hidden="true">▾</span>
        </summary>

        <div class="flex flex-col gap-2 px-3 pb-3">
            <p class="px-1 text-sm text-ink-soft">
                {{ random ? t('equipment.guide_random', { count: random }) : t('equipment.guide_hint') }}
            </p>
            <article
                v-for="card in cards"
                :key="card.id"
                class="flex gap-3 rounded-2xl border-2 border-line p-2.5"
                :class="card.inPlay ? 'bg-mint-soft' : 'bg-cloud'"
                :data-guide="card.id"
            >
                <span class="flex size-11 shrink-0 items-center justify-center rounded-xl border-2 border-line bg-sun text-2xl" aria-hidden="true">
                    {{ EQUIPMENT_ICON[card.id] }}
                </span>
                <div class="min-w-0 flex-1">
                    <p class="flex flex-wrap items-center gap-1.5 font-bold">
                        {{ t(`equipment.${card.id}.name`) }}
                        <span class="chip bg-white" :title="t('equipment.guide_unlock_hint')">🔓 {{ card.unlock }}</span>
                        <span v-if="card.inPlay" class="chip bg-mint">✅ {{ t('equipment.in_mission') }}</span>
                    </p>
                    <p class="text-sm">{{ t(`equipment.${card.id}.text`) }}</p>
                    <p class="mt-0.5 text-xs font-semibold text-ink-soft">
                        👉 {{ t(`equipment.how.${EQUIPMENT_INPUT[card.id]}`) }}
                        <template v-if="card.id === 'stabilizer'"> · ↩️ {{ t('equipment.how.switch_off') }}</template>
                    </p>
                </div>
            </article>
            <p class="px-1 text-xs text-ink-soft">🔓 {{ t('equipment.guide_unlock_hint') }}</p>
        </div>
    </details>
</template>
