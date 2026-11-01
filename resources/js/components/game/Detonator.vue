<script setup>
import { useT } from '../../i18n';

/**
 * A burning fuse: each mistake lights one more segment; the bomb sits at the end.
 */
defineProps({
    detonator: { type: Object, required: true },
});

const t = useT();
</script>

<template>
    <div class="flex items-center gap-2" data-testid="detonator">
        <div class="flex flex-1 items-center gap-1" role="meter" :aria-valuenow="detonator.mistakes" :aria-valuemax="detonator.limit" :aria-label="t('game.detonator')">
            <span
                v-for="step in detonator.limit"
                :key="step"
                class="flex h-5 flex-1 items-center justify-center rounded-full border-2 border-ink text-xs"
                :class="step <= detonator.mistakes ? 'bg-berry' : 'bg-white'"
            >
                <span v-if="step === detonator.mistakes" aria-hidden="true">🔥</span>
            </span>
            <span class="text-2xl" :class="detonator.mistakes === detonator.limit - 1 ? 'animate-wiggle' : ''" aria-hidden="true">💣</span>
        </div>
        <span class="chip shrink-0 tabular-nums" :class="detonator.mistakes ? 'bg-berry-soft' : 'bg-white'">
            {{ t('game.mistakes', detonator) }}
        </span>
    </div>
</template>
