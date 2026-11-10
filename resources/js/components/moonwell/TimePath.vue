<script setup>
import { computed } from 'vue';
import { INK, seatColor } from '../../moonwell/art';
import { useT } from '../../i18n';

/**
 * The shared time path: one pawn per player. The window follows the pawn furthest back, which is
 * the one that plays next. `preview` marks where my pawn would land with the tile I'm holding.
 */
const props = defineProps({
    players: { type: Array, required: true },
    turn: { type: Number, default: null },
    preview: { type: Number, default: null },
});

const t = useT();
const STONES = 20;
const start = computed(() => Math.max(0, Math.min(...props.players.map((player) => player.pos)) - 3));
const stone = (pos) => [24 + (pos - start.value) * 16.5, 70 + Math.sin(pos * 0.8) * 5];
const stones = computed(() => Array.from({ length: STONES }, (_, i) => start.value + i).map((pos) => ({ pos, xy: stone(pos) })));

// Pawns on one stone stand in two columns, stacking upwards; the latest arrival is on top (it plays first)
const pawns = computed(() => {
    const seen = {};
    return [...props.players]
        .sort((a, b) => a.arrived - b.arrived)
        .map((player) => {
            const level = (seen[player.pos] = (seen[player.pos] ?? -1) + 1);
            const [x, y] = stone(Math.min(player.pos, start.value + STONES - 1));
            return { ...player, x: x + (level % 2 ? 4 : -4), y: y - Math.floor(level / 2) * 8, color: seatColor(player.seat) };
        });
});
const previewXY = computed(() => (props.preview === null ? null : stone(Math.min(props.preview, start.value + STONES - 1))));

let seed = 5;
const rnd = () => (seed = (seed * 9301 + 49297) % 233280) / 233280;
const stars = Array.from({ length: 24 }, () => ({ x: rnd() * 360, y: 20 + rnd() * 20, r: 0.5 + rnd(), o: 0.3 + rnd() * 0.5 }));
</script>

<template>
    <svg viewBox="0 0 360 104" class="block w-full" role="img" :aria-label="t('moonwell.time_path')">
        <rect width="360" height="104" fill="url(#mw-sky)" />
        <circle v-for="(star, i) in stars" :key="i" :cx="star.x" :cy="star.y" :r="star.r" fill="#fff1c4" :opacity="star.o" />
        <text x="16" y="15" font-size="9" letter-spacing="1.5" fill="#b9b1e0" font-weight="600">{{ t('moonwell.time_path').toUpperCase() }}</text>
        <text x="344" y="15" font-size="9" fill="#b9b1e0" text-anchor="end">{{ t('moonwell.behind_first') }}</text>

        <g v-for="s in stones" :key="s.pos">
            <ellipse :cx="s.xy[0]" :cy="s.xy[1] + 1.5" rx="7.5" ry="4.5" fill="#0a0720" opacity="0.5" />
            <ellipse :cx="s.xy[0]" :cy="s.xy[1]" rx="7.5" ry="4.8" fill="url(#mw-pebble)" :stroke="INK" stroke-width="1" />
            <text v-if="s.pos % 5 === 0" :x="s.xy[0]" :y="s.xy[1] + 19" font-size="9" fill="#cfc8f0" text-anchor="middle" font-weight="600">{{ s.pos }}</text>
        </g>

        <ellipse
            v-if="previewXY"
            :cx="previewXY[0]"
            :cy="previewXY[1]"
            rx="10"
            ry="6.5"
            fill="none"
            stroke="#ff5d8f"
            stroke-width="2"
            stroke-dasharray="3 3"
            class="mw-pulse"
        />

        <g v-for="pawn in pawns" :key="pawn.id" class="mw-pawn" :style="{ transform: `translate(${pawn.x}px, ${pawn.y}px)` }">
            <ellipse cy="1" rx="6.5" ry="2.2" fill="#000" opacity="0.4" />
            <ellipse v-if="pawn.isMe" cy="1" rx="9" ry="3.2" fill="none" stroke="#ffcb3d" stroke-width="1.6" />
            <path d="M-6 0C-6 -6 -3 -8.5 0 -8.5C3 -8.5 6 -6 6 0Z" :fill="pawn.color" :stroke="INK" stroke-width="1.5" />
            <circle cy="-11.5" r="4" :fill="pawn.color" :stroke="pawn.id === turn ? '#ffcb3d' : INK" :stroke-width="pawn.id === turn ? 2.2 : 1.5" />
            <circle cx="-1.4" cy="-12.8" r="1.2" fill="#fff" opacity="0.6" />
        </g>
    </svg>
</template>
