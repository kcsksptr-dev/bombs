<script setup>
import { COLORS } from '../../moonwell/art';
</script>

<template>
    <!-- Gradients, shadows and textures every Moonwell drawing refers to by id. Mount once per page. -->
    <svg class="pointer-events-none absolute size-0 overflow-hidden" aria-hidden="true" focusable="false">
        <defs>
            <linearGradient v-for="(c, key) in COLORS" :id="`mw-${key}`" :key="key" x1="0" y1="0" x2="0.4" y2="1">
                <stop offset="0" :stop-color="c.a" />
                <stop offset="1" :stop-color="c.b" />
            </linearGradient>
            <radialGradient id="mw-glow">
                <stop offset="0" stop-color="#fff6b0" />
                <stop offset="0.4" stop-color="#ffe27a" stop-opacity="0.55" />
                <stop offset="1" stop-color="#ffe27a" stop-opacity="0" />
            </radialGradient>
            <radialGradient id="mw-water" cx="0.45" cy="0.4">
                <stop offset="0" stop-color="#3b86cf" />
                <stop offset="0.6" stop-color="#1b3f86" />
                <stop offset="1" stop-color="#0c1444" />
            </radialGradient>
            <radialGradient id="mw-stone" cx="0.5" cy="0.4">
                <stop offset="0" stop-color="#5d5394" />
                <stop offset="0.75" stop-color="#3a3170" />
                <stop offset="1" stop-color="#2a2358" />
            </radialGradient>
            <radialGradient id="mw-pebble" cx="0.4" cy="0.3">
                <stop offset="0" stop-color="#7a70ad" />
                <stop offset="1" stop-color="#3d3474" />
            </radialGradient>
            <radialGradient id="mw-sky" cx="0.5" cy="0" r="1.1">
                <stop offset="0" stop-color="#3a2f78" />
                <stop offset="1" stop-color="#141033" />
            </radialGradient>
            <pattern id="mw-stripes" width="6" height="6" patternUnits="userSpaceOnUse" patternTransform="rotate(45)">
                <rect width="2.2" height="6" fill="#fff" opacity="0.1" />
            </pattern>
            <filter id="mw-shadow" x="-20%" y="-20%" width="150%" height="150%">
                <feDropShadow dx="0" dy="2.5" stdDeviation="2" flood-color="#05031a" flood-opacity="0.6" />
            </filter>
            <filter id="mw-lift" x="-30%" y="-30%" width="170%" height="170%">
                <feDropShadow dx="0" dy="7" stdDeviation="4" flood-color="#05031a" flood-opacity="0.7" />
            </filter>
            <filter id="mw-moss" x="0" y="0" width="100%" height="100%">
                <feTurbulence type="fractalNoise" baseFrequency="0.85" numOctaves="3" stitchTiles="stitch" />
                <feColorMatrix values="0 0 0 0 .75  0 0 0 0 1  0 0 0 0 .8  .5 0 0 0 -.18" />
            </filter>
        </defs>
    </svg>
</template>
