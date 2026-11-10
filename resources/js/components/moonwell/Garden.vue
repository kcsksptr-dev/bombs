<script setup>
import { computed } from 'vue';
import { useT } from '../../i18n';
import MwTile from './MwTile.vue';

/**
 * A player's garden. It grows in every direction, so the drawing fits itself around the tiles
 * (and, while placing, the open cells). Wide gardens scroll sideways inside their own box.
 */
const props = defineProps({
    garden: { type: Array, required: true },
    openCells: { type: Array, default: () => [] },
    placing: { type: Boolean, default: false },
    freshTile: { type: Number, default: null },
    freshWishes: { type: Object, default: () => ({}) },
});

const emit = defineEmits(['place']);
const t = useT();

const CELL = 62;
const TILE = 58;
const cells = computed(() => [...props.garden.map((c) => [c.x, c.y]), ...(props.placing ? props.openCells : [])]);
const bounds = computed(() => {
    const list = cells.value.length ? cells.value : [[0, 0]];
    const xs = list.map(([x]) => x);
    const ys = list.map(([, y]) => y);
    return { minX: Math.min(...xs), maxX: Math.max(...xs), minY: Math.min(...ys), maxY: Math.max(...ys) };
});
const cols = computed(() => Math.max(5, bounds.value.maxX - bounds.value.minX + 1));
const rows = computed(() => Math.max(3, bounds.value.maxY - bounds.value.minY + 1));
// Centre small gardens in a 5×3 board so the first tiles don't fill the whole screen
const offsetX = computed(() => bounds.value.minX - Math.floor((cols.value - (bounds.value.maxX - bounds.value.minX + 1)) / 2));
const offsetY = computed(() => bounds.value.minY - Math.floor((rows.value - (bounds.value.maxY - bounds.value.minY + 1)) / 2));
const width = computed(() => cols.value * CELL + 16);
const height = computed(() => rows.value * CELL + 16);
const at = (x, y) => [8 + (x - offsetX.value) * CELL + 2, 8 + (y - offsetY.value) * CELL + 2];
</script>

<template>
    <div class="overflow-x-auto rounded-[22px] border-[3px] border-line bg-[#241d4c]" data-testid="mw-garden">
        <svg
            :viewBox="`0 0 ${width} ${height}`"
            class="block"
            :style="{ width: cols > 5 ? `${cols * 64}px` : '100%', minWidth: '100%' }"
            role="group"
            :aria-label="t('moonwell.garden')"
        >
            <rect :width="width" :height="height" fill="#2f7a5a" opacity="0.22" />
            <rect :width="width" :height="height" filter="url(#mw-moss)" opacity="0.9" />

            <template v-for="r in rows" :key="`r${r}`">
                <circle
                    v-for="c in cols"
                    :key="`c${c}`"
                    :cx="8 + (c - 1) * CELL + 2 + TILE / 2"
                    :cy="8 + (r - 1) * CELL + 2 + TILE / 2"
                    r="2"
                    fill="#fff"
                    opacity="0.12"
                />
            </template>

            <g v-for="cell in garden" :key="cell.tile.id" :transform="`translate(${at(cell.x, cell.y).join(' ')})`">
                <g :class="{ 'mw-pop': cell.tile.id === freshTile }">
                    <MwTile :tile="cell.tile" :size="TILE" :show-cost="false" :fresh="freshWishes[cell.tile.id] ?? []" />
                </g>
            </g>

            <template v-if="placing">
                <g
                    v-for="[x, y] in openCells"
                    :key="`${x},${y}`"
                    :transform="`translate(${at(x, y).join(' ')})`"
                    class="cursor-pointer"
                    role="button"
                    tabindex="0"
                    :aria-label="t('moonwell.plant_here')"
                    data-testid="mw-open-cell"
                    @click="emit('place', x, y)"
                    @keydown.enter.prevent="emit('place', x, y)"
                    @keydown.space.prevent="emit('place', x, y)"
                >
                    <rect
                        :width="TILE"
                        :height="TILE"
                        rx="11"
                        fill="#fff1c4"
                        fill-opacity="0.08"
                        stroke="#fff1c4"
                        stroke-width="2.2"
                        stroke-dasharray="6 5"
                        class="mw-pulse"
                    />
                    <text :x="TILE / 2" :y="TILE / 2 + 6" text-anchor="middle" font-size="18" fill="#fff1c4" opacity="0.7">+</text>
                </g>
            </template>
        </svg>
    </div>
</template>
