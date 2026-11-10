<script setup>
import { useT } from '../../i18n';

defineProps({
    board: { type: Array, required: true },
});

const t = useT();
</script>

<template>
    <div>
        <p class="label mb-2">✂️ {{ t('game.cut_board') }}</p>
        <div class="grid grid-cols-6 gap-1.5">
            <div
                v-for="slot in board"
                :key="slot.kind"
                class="flex items-center gap-1 rounded-xl border-2 border-line px-1.5 py-1"
                :class="slot.cut === slot.total ? 'bg-mint' : slot.kind === 'yellow' ? 'bg-sun-soft' : slot.kind === 'red' ? 'bg-berry-soft' : 'bg-white'"
            >
                <span class="w-5 text-center text-sm font-bold">{{ slot.kind === 'yellow' ? '🟡' : slot.kind === 'red' ? '🔴' : slot.kind }}</span>
                <span class="grid flex-1 grid-cols-2 gap-0.5">
                    <span
                        v-for="n in slot.total"
                        :key="n"
                        class="size-1.5 justify-self-center rounded-full"
                        :class="n <= slot.cut ? 'bg-line' : 'bg-line/15'"
                    />
                </span>
            </div>
        </div>
    </div>
</template>
