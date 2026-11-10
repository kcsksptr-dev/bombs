<script setup>
import { computed } from 'vue';
import { glyph } from '../../moonwell/art';

// A colour's shape; fill/stroke come in as plain attributes
const props = defineProps({
    color: { type: String, required: true },
    cx: { type: Number, required: true },
    cy: { type: Number, required: true },
    r: { type: Number, required: true },
});

const shape = computed(() => glyph(props.color, props.cx, props.cy, props.r));
</script>

<template>
    <component :is="shape.tag" v-bind="shape.attrs" />
</template>
