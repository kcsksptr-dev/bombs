<script setup>
import { computed } from 'vue';
import { INK } from '../../moonwell/art';
import { useT } from '../../i18n';
import MwTile from './MwTile.vue';

/**
 * The stone well with the ring of tiles. The tiles after the lantern that may be taken are raised
 * and pulse; with `canPick` they are buttons.
 */
const props = defineProps({
    ring: { type: Array, required: true },
    lantern: { type: Number, required: true },
    available: { type: Array, required: true },
    selected: { type: Number, default: null },
    canPick: { type: Boolean, default: false },
    bag: { type: Number, required: true },
});

const emit = defineEmits(['pick']);
const t = useT();

const CX = 180;
const CY = 165;
const size = computed(() => (props.ring.length > 12 ? 38 : 44));
const radius = computed(() => (props.ring.length > 12 ? 116 : 112));
const reduceMotion = typeof window !== 'undefined' && window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;

const slots = computed(() =>
    props.ring.map((tile, slot) => {
        const angle = (slot / props.ring.length) * Math.PI * 2 - Math.PI / 2;
        const lit = props.available.includes(slot);
        return {
            slot,
            tile,
            x: CX + Math.cos(angle) * radius.value,
            y: CY + Math.sin(angle) * radius.value,
            lit,
            chosen: props.selected === slot,
        };
    }),
);

let seed = 9;
const rnd = () => (seed = (seed * 9301 + 49297) % 233280) / 233280;
const pebbles = Array.from({ length: 40 }, (_, i) => {
    const a = (i / 40) * Math.PI * 2 + rnd() * 0.1;
    const r = 145 + rnd() * 3;
    const x = CX + Math.cos(a) * r;
    const y = CY + Math.sin(a) * r;
    return { x, y, rx: 5 + rnd() * 4, ry: 3 + rnd() * 2, rot: a * 57.3 + 90 };
});

function pick(slot) {
    if (props.canPick) emit('pick', slot);
}
</script>

<template>
    <svg viewBox="0 0 360 330" class="block w-full" role="group" :aria-label="t('moonwell.well')">
        <circle :cx="CX" :cy="CY" r="152" fill="url(#mw-stone)" :stroke="INK" stroke-width="3" filter="url(#mw-shadow)" />
        <ellipse
            v-for="(p, i) in pebbles"
            :key="i"
            :cx="p.x"
            :cy="p.y"
            :rx="p.rx"
            :ry="p.ry"
            fill="url(#mw-pebble)"
            :stroke="INK"
            stroke-width="0.8"
            :transform="`rotate(${p.rot} ${p.x} ${p.y})`"
        />
        <circle :cx="CX" :cy="CY" r="137" fill="none" stroke="#fff" stroke-opacity="0.06" stroke-width="1.5" />
        <circle :cx="CX" :cy="CY" r="82" fill="#2a2358" :stroke="INK" stroke-width="2.5" />
        <circle :cx="CX" :cy="CY" r="68" fill="url(#mw-water)" :stroke="INK" stroke-width="3" />
        <template v-if="!reduceMotion">
            <circle v-for="delay in [0, 2]" :key="delay" :cx="CX" :cy="CY + 6" r="10" fill="none" stroke="#cfe8ff" stroke-width="1">
                <animate attributeName="r" from="8" to="62" dur="4s" :begin="`${delay}s`" repeatCount="indefinite" />
                <animate attributeName="stroke-opacity" from="0.5" to="0" dur="4s" :begin="`${delay}s`" repeatCount="indefinite" />
            </circle>
        </template>
        <path :d="`M${CX - 26} ${CY - 36}a18 18 0 1 0 22 -6a14 14 0 1 1 -22 6Z`" fill="#fff1c4" opacity="0.28" />
        <text :x="CX" :y="CY + 10" text-anchor="middle" font-size="26" font-weight="700" fill="#fff1c4" data-testid="mw-bag">{{ bag }}</text>
        <text :x="CX" :y="CY + 26" text-anchor="middle" font-size="9" fill="#cfe8ff">{{ t('moonwell.bag') }}</text>

        <template v-for="s in slots" :key="s.slot">
            <!-- The lantern marks where the last tile was taken; counting starts after it -->
            <g v-if="s.slot === lantern" class="mw-pawn" :style="{ transform: `translate(${s.x}px, ${s.y}px)` }">
                <circle r="28" fill="url(#mw-glow)" opacity="0.9" />
                <path d="M-5 -13Q0 -21 5 -13" fill="none" :stroke="INK" stroke-width="2" />
                <rect x="-8" y="-14" width="16" height="4" rx="2" fill="#c98a1a" :stroke="INK" stroke-width="1.5" />
                <rect x="-9" y="-10" width="18" height="20" rx="5" fill="#ffe9a8" :stroke="INK" stroke-width="2" />
                <path d="M0 6C-4 2 -2 -2 0 -6C2 -2 4 2 0 6Z" fill="#ff9f43" />
                <path d="M0 5C-2 3 -1 0 0 -2C1 0 2 3 0 5Z" fill="#fff8ec" />
                <rect x="-8" y="9" width="16" height="4" rx="2" fill="#c98a1a" :stroke="INK" stroke-width="1.5" />
            </g>
            <circle v-else-if="!s.tile" :cx="s.x" :cy="s.y" r="15" fill="#1c1640" opacity="0.6" stroke="#fff" stroke-opacity="0.1" stroke-dasharray="3 3" />
            <g
                v-else
                :transform="`translate(${s.x - size / 2} ${s.y - size / 2 - (s.lit ? 4 : 0)})`"
                :role="s.lit && canPick ? 'button' : 'img'"
                :tabindex="s.lit && canPick ? 0 : -1"
                :aria-label="t('moonwell.tile_label', { color: t(`moonwell.color.${s.tile.color}`), cost: s.tile.cost })"
                :aria-pressed="s.lit && canPick ? s.chosen : undefined"
                :class="{ 'cursor-pointer': s.lit && canPick }"
                :data-testid="s.lit ? 'mw-available' : 'mw-ring-tile'"
                :data-slot="s.slot"
                @click="s.lit && pick(s.slot)"
                @keydown.enter.prevent="s.lit && pick(s.slot)"
                @keydown.space.prevent="s.lit && pick(s.slot)"
            >
                <circle v-if="s.chosen" :cx="size / 2" :cy="size / 2" r="36" fill="url(#mw-glow)" />
                <rect
                    v-if="s.lit"
                    x="-3.5"
                    y="-3.5"
                    :width="size + 7"
                    :height="size + 7"
                    rx="10"
                    fill="none"
                    :stroke="s.chosen ? '#fff' : '#ffcb3d'"
                    :stroke-width="s.chosen ? 3 : 2.5"
                    :class="{ 'mw-pulse': !s.chosen }"
                />
                <MwTile :tile="s.tile" :size="size" :lift="s.lit" :dim="!s.lit" />
            </g>
        </template>
    </svg>
</template>
