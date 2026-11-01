<script setup>
import { EQUIPMENT_ICON, EQUIPMENT_INPUT } from '../../game';
import { useT } from '../../i18n';

/**
 * The team's equipment cards: locked until two wires of the card's number are cut, then usable
 * once by whoever's turn it is. Cards that need a target emit `choose` so the game screen can ask
 * for a wire or a number; the rest emit `use` straight away.
 */
defineProps({
    equipment: { type: Array, required: true },
    myTurn: Boolean,
    busy: Boolean,
    stabilized: Boolean,
});

const emit = defineEmits(['use', 'choose']);
const t = useT();

function activate(card) {
    if (EQUIPMENT_INPUT[card.id] === 'none') emit('use', card.id);
    else emit('choose', card.id);
}
</script>

<template>
    <div class="flex flex-col gap-3" data-testid="equipment">
        <p v-if="!equipment.length" class="card text-center text-ink-soft">🧰 {{ t('equipment.none') }}</p>

        <p v-if="stabilized" class="animate-pop rounded-2xl border-[3px] border-ink bg-sky-soft px-3 py-2 font-semibold">
            🛡️ {{ t('equipment.stabilized') }}
        </p>

        <article
            v-for="card in equipment"
            :key="card.id"
            class="flex items-center gap-3 rounded-3xl border-[3px] border-ink p-3 shadow-[0_5px_0_var(--color-ink)]"
            :class="card.used ? 'bg-cloud opacity-60' : card.unlocked ? 'bg-white' : 'bg-white/70'"
            :data-equipment="card.id"
        >
            <span
                class="flex size-14 shrink-0 items-center justify-center rounded-2xl border-[3px] border-ink text-3xl"
                :class="card.used ? 'bg-cloud grayscale' : card.unlocked ? 'bg-sun' : 'bg-lilac grayscale'"
                aria-hidden="true"
            >
                {{ card.unlocked || card.used ? EQUIPMENT_ICON[card.id] : '🔒' }}
            </span>
            <div class="min-w-0 flex-1">
                <p class="flex items-center gap-2 font-bold">
                    {{ t(`equipment.${card.id}.name`) }}
                    <span v-if="card.rule === 'cut_pair' || card.rule === 'cut_all'" class="chip bg-white">{{ card.unlock }}</span>
                </p>
                <p class="text-sm text-ink-soft">{{ t(`equipment.${card.id}.text`) }}</p>
                <p class="mt-1 text-xs font-semibold" :class="card.used ? 'text-ink-soft' : card.unlocked ? 'text-mint' : 'text-ink-soft'">
                    {{ card.used ? `✔️ ${t('equipment.used')}` : card.unlocked ? `✨ ${t('equipment.ready')}` : `🔒 ${t(`equipment.locked_${card.rule}`, { value: card.unlock })}` }}
                </p>
            </div>
            <button
                v-if="card.unlocked && !card.used"
                type="button"
                class="btn btn-sm bg-sun"
                :disabled="!myTurn || busy"
                :data-use="card.id"
                @click="activate(card)"
            >
                {{ t('equipment.use') }}
            </button>
        </article>

        <p v-if="equipment.length" class="px-1 text-center text-sm text-ink-soft">💡 {{ t('equipment.your_turn_only') }}</p>
    </div>
</template>
