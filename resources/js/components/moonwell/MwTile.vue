<script setup>
import { computed } from 'vue';
import { CENTER, COLORS, CORNERS, INK, SEEDS } from '../../moonwell/art';
import MwFirefly from './MwFirefly.vue';
import MwGlyph from './MwGlyph.vue';

/**
 * One tile, drawn at (0, 0) and `size` units wide. The big shape shows the colour (and the moon
 * cost), the corners hold its wishes; a wish that came true turns gold and gets a firefly.
 * `fresh` lists wish indexes that just came true, so their fireflies fly in.
 */
const props = defineProps({
    tile: { type: Object, required: true },
    size: { type: Number, default: 60 },
    showCost: { type: Boolean, default: true },
    dim: { type: Boolean, default: false },
    lift: { type: Boolean, default: false },
    fresh: { type: Array, default: () => [] },
});

const shape = computed(() => COLORS[props.tile.color]?.shape ?? 'circle');
const center = computed(() => CENTER[shape.value]);
const corners = computed(() =>
    CORNERS.map(([cx, cy], index) => {
        const wish = props.tile.wishes[index] ?? null;
        return {
            cx,
            cy,
            wish,
            seeds: wish ? (SEEDS[wish.need.length] ?? SEEDS[3]).map(([dx, dy, r], j) => ({ color: wish.need[j], x: cx + dx, y: cy + dy, r })) : [],
            fresh: props.fresh.includes(index),
            // Fireflies sit towards the tile's middle so they don't cover the wish's shapes
            fx: cx + (cx < 30 ? 6 : -6),
            fy: cy + (cy < 30 ? 6 : -6),
        };
    }),
);
</script>

<template>
    <g :transform="`scale(${size / 60})`">
        <rect
            width="60"
            height="60"
            rx="8"
            :fill="`url(#mw-${tile.color})`"
            :stroke="INK"
            stroke-width="2.6"
            :filter="lift ? 'url(#mw-lift)' : 'url(#mw-shadow)'"
        />
        <rect x="1.3" y="1.3" width="57.4" height="57.4" rx="7" fill="url(#mw-stripes)" />
        <rect x="4" y="4" width="52" height="52" rx="5.5" fill="none" stroke="#fff" stroke-opacity="0.55" stroke-width="1.4" />

        <MwGlyph :color="tile.color" :cx="center[0] + 1.2" :cy="center[1] + 1.6" :r="center[2]" :fill="INK" opacity="0.35" />
        <MwGlyph :color="tile.color" :cx="center[0]" :cy="center[1]" :r="center[2]" fill="#fff8ec" :stroke="INK" stroke-width="2.2" />
        <text
            v-if="showCost && tile.cost"
            x="30"
            :y="center[1] + (shape === 'triangle' ? 6.7 : 5.2)"
            text-anchor="middle"
            :font-size="shape === 'triangle' ? 12 : 15"
            font-weight="700"
            :fill="INK"
        >
            {{ tile.cost }}
        </text>
        <MwGlyph v-else :color="tile.color" :cx="center[0]" :cy="center[1] + (shape === 'triangle' ? 1.8 : 0)" :r="center[2] * 0.32" :fill="COLORS[tile.color].b" />

        <g v-for="(corner, index) in corners" :key="index">
            <circle v-if="!corner.wish" :cx="corner.cx" :cy="corner.cy" r="2" :fill="INK" opacity="0.3" />
            <template v-else>
                <circle :cx="corner.cx + 0.8" :cy="corner.cy + 1.2" r="8.4" :fill="INK" opacity="0.35" />
                <circle :cx="corner.cx" :cy="corner.cy" r="8.4" :fill="corner.wish.done ? '#ffe27a' : '#fff8ec'" :stroke="INK" stroke-width="1.7" />
                <MwGlyph
                    v-for="(seed, j) in corner.seeds"
                    :key="j"
                    :color="seed.color"
                    :cx="seed.x"
                    :cy="seed.y"
                    :r="seed.r"
                    :fill="COLORS[seed.color].b"
                    :stroke="INK"
                    stroke-width="0.6"
                />
                <g v-if="corner.wish.done" :class="{ 'mw-land': corner.fresh }">
                    <MwFirefly :x="corner.fx" :y="corner.fy" :scale="0.6" />
                </g>
            </template>
        </g>

        <rect v-if="dim" width="60" height="60" rx="8" fill="#0b0820" opacity="0.55" />
    </g>
</template>
