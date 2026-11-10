<script setup>
defineProps({
    x: { type: Number, default: 0 },
    y: { type: Number, default: 0 },
    scale: { type: Number, default: 1 },
});
</script>

<template>
    <g :transform="`translate(${x} ${y}) scale(${scale})`">
        <g class="mw-ff">
            <circle r="8" fill="url(#mw-glow)" />
            <ellipse cx="-2.2" cy="-2.2" rx="2.6" ry="1.4" fill="#fff" opacity="0.85" transform="rotate(-35 -2.2 -2.2)" />
            <ellipse cx="2.2" cy="-2.2" rx="2.6" ry="1.4" fill="#fff" opacity="0.85" transform="rotate(35 2.2 -2.2)" />
            <ellipse rx="1.7" ry="2.6" fill="#ffd84a" stroke="#7a5400" stroke-width="0.35" />
        </g>
    </g>
</template>
