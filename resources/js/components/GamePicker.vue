<script setup>
import { useT } from '../i18n';
import MwTile from './moonwell/MwTile.vue';

/**
 * The room's game picker: the host taps a game, everyone else watches the choice. Game texts come
 * from games.{key}.* in both dictionaries; a new game also needs its card art below.
 */
defineProps({
    games: { type: Array, required: true },
    isHost: { type: Boolean, default: false },
    players: { type: Number, required: true },
    busy: { type: Boolean, default: false },
});

const emit = defineEmits(['choose']);
const t = useT();

const artTiles = [
    { color: 'blue', cost: 0, wishes: [{ need: ['green'] }] },
    { color: 'green', cost: 0, wishes: [{ need: ['pink', 'pink'] }, { need: ['blue'], done: true }] },
    { color: 'pink', cost: 0, wishes: [{ need: ['orange', 'green', 'blue'] }] },
    { color: 'orange', cost: 0, wishes: [{ need: ['blue'], done: true }, { need: ['pink'] }] },
    { color: 'blue', cost: 0, wishes: [{ need: ['orange', 'orange'] }] },
];
</script>

<template>
    <section class="flex flex-col gap-4" data-testid="game-picker">
        <div class="text-center">
            <h2 class="heading">🎲 {{ t('picker.title') }}</h2>
            <p class="text-sm text-ink-soft">{{ isHost ? t('picker.host_hint') : t('picker.host_choosing') }}</p>
        </div>

        <component
            :is="isHost ? 'button' : 'div'"
            v-for="game in games"
            :key="game.key"
            :type="isHost ? 'button' : undefined"
            class="overflow-hidden rounded-3xl border-[3px] border-line bg-cream text-left text-ink shadow-[0_5px_0_var(--color-line)] transition-transform"
            :class="{ 'active:translate-y-1 active:shadow-[0_1px_0_var(--color-line)]': isHost, 'opacity-50': players > game.max }"
            :disabled="isHost && (busy || players > game.max)"
            :data-testid="`pick-${game.key}`"
            @click="isHost && emit('choose', game.key)"
        >
            <svg v-if="game.key === 'bombbusters'" viewBox="0 0 340 96" preserveAspectRatio="xMidYMid slice" class="block h-24 w-full" aria-hidden="true">
                <rect width="340" height="96" fill="#ffe0ea" />
                <g stroke="#2b2550" stroke-width="3">
                    <rect x="40" y="30" width="22" height="46" rx="6" fill="#4cc3ff" />
                    <rect x="70" y="30" width="22" height="46" rx="6" fill="#4cc3ff" />
                    <rect x="100" y="30" width="22" height="46" rx="6" fill="#ffcb3d" />
                    <rect x="130" y="30" width="22" height="46" rx="6" fill="#ff5d8f" />
                    <rect x="160" y="30" width="22" height="46" rx="6" fill="#4cc3ff" />
                    <circle cx="270" cy="52" r="28" fill="#2b2550" />
                    <path d="M286 30q12 -14 20 -6" fill="none" />
                </g>
                <text x="270" y="60" text-anchor="middle" font-size="22">💣</text>
            </svg>
            <svg v-else-if="game.key === 'moonwell'" viewBox="0 0 340 96" preserveAspectRatio="xMidYMid slice" class="block h-24 w-full" aria-hidden="true">
                <rect width="340" height="96" fill="url(#mw-sky)" />
                <circle cx="302" cy="26" r="15" fill="#fff1c4" />
                <circle cx="309" cy="21" r="13" fill="#221b52" />
                <g v-for="(tile, i) in artTiles" :key="i" :transform="`translate(${22 + i * 50} ${24 + (i % 2) * 12}) rotate(${i % 2 ? 6 : -6} 21 21)`">
                    <MwTile :tile="tile" :size="42" :show-cost="false" />
                </g>
            </svg>
            <div class="flex flex-col gap-1 border-t-[3px] border-line px-4 pt-2.5 pb-3">
                <div class="flex items-baseline justify-between gap-2">
                    <h3 class="text-lg font-bold">{{ t(`games.${game.key}.name`) }}</h3>
                    <span class="chip shrink-0 bg-mint-soft text-xs">{{ t('picker.players', { min: game.min, max: game.max }) }} · {{ t(`games.${game.key}.kind`) }}</span>
                </div>
                <p class="text-sm text-ink-soft">{{ t(`games.${game.key}.pitch`) }}</p>
                <p v-if="players > game.max" class="text-sm font-semibold text-berry">{{ t('lobby.errors.too_many_for_game', { max: game.max }) }}</p>
            </div>
        </component>
    </section>
</template>
