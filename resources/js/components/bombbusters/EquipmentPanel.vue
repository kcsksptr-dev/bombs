<script setup>
import { ref, watch } from 'vue';
import { EQUIPMENT_ICON, EQUIPMENT_INPUT } from '../../bombbusters/game';
import { useT } from '../../i18n';

/**
 * The team's equipment cards: locked until two wires of the card's number are cut, then usable
 * once by whoever's turn it is. Cards that need a target emit `choose` so the game screen can ask
 * for a wire or a number (cancellable there); the rest ask "Use it now?" first, so a stray tap
 * can be cancelled. A switched-on Stabilizer can still be switched off (`cancel`) until it is spent.
 */
const props = defineProps({
    equipment: { type: Array, required: true },
    myTurn: Boolean,
    busy: Boolean,
    stabilized: Boolean,
});

const emit = defineEmits(['use', 'choose', 'cancel']);
const t = useT();
const confirming = ref(null); // id of an instant card waiting for "Use it"

// A confirmation belongs to this turn
watch(
    () => props.myTurn,
    () => (confirming.value = null),
);

function activate(card) {
    if (EQUIPMENT_INPUT[card.id] === 'none') confirming.value = card.id;
    else emit('choose', card.id);
}

function confirm(id) {
    confirming.value = null;
    emit('use', id);
}
</script>

<template>
    <div class="flex flex-col gap-3" data-testid="equipment">
        <p v-if="!equipment.length" class="card text-center text-ink-soft">🧰 {{ t('equipment.none') }}</p>

        <div v-if="stabilized" class="animate-pop flex items-center gap-2 rounded-2xl border-[3px] border-line bg-sky-soft px-3 py-2 font-semibold">
            <span class="flex-1">🛡️ {{ t('equipment.stabilized') }}</span>
            <button
                v-if="myTurn"
                type="button"
                class="btn btn-sm shrink-0 bg-white"
                :disabled="busy"
                data-testid="cancel-stabilizer"
                @click="emit('cancel', 'stabilizer')"
            >
                {{ t('equipment.cancel_stabilizer') }}
            </button>
        </div>

        <article
            v-for="card in equipment"
            :key="card.id"
            class="flex items-center gap-3 rounded-3xl border-[3px] border-line p-3 shadow-[0_5px_0_var(--color-line)]"
            :class="card.used ? 'bg-cloud opacity-60' : card.unlocked ? 'bg-white' : 'bg-white/70'"
            :data-equipment="card.id"
        >
            <span
                class="flex size-14 shrink-0 items-center justify-center rounded-2xl border-[3px] border-line text-3xl"
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
            <div v-if="confirming === card.id" class="flex shrink-0 flex-col items-stretch gap-1.5" data-testid="equipment-confirm">
                <span class="text-center text-xs font-semibold">{{ t('equipment.confirm_prompt', { card: t(`equipment.${card.id}.name`) }) }}</span>
                <button type="button" class="btn btn-sm bg-sun" :disabled="!myTurn || busy" :data-confirm="card.id" @click="confirm(card.id)">
                    {{ t('equipment.confirm') }}
                </button>
                <button type="button" class="btn btn-sm bg-white" data-testid="equipment-cancel" @click="confirming = null">
                    {{ t('game.cancel') }}
                </button>
            </div>
            <button
                v-else-if="card.unlocked && !card.used"
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
