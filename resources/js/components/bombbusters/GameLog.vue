<script setup>
import { computed } from 'vue';
import { useT } from '../../i18n';
import { describeEntry, entryIcon as icon, entryTone as tone } from './logEntry';

const props = defineProps({
    log: { type: Array, required: true },
    names: { type: Object, required: true },
});

const t = useT();

const describe = (entry) => describeEntry(t, props.names, entry);

const entries = computed(() => [...props.log].reverse());
</script>

<template>
    <ol class="max-h-72 space-y-1.5 overflow-y-auto overscroll-contain text-sm" data-testid="log" :data-length="log.length">
        <li
            v-for="(entry, i) in entries"
            :key="log.length - i"
            class="flex gap-2 rounded-xl px-2.5 py-1.5"
            :class="[tone(entry), i === 0 ? 'animate-pop border-2 border-line font-semibold' : 'opacity-80']"
        >
            <span aria-hidden="true">{{ icon(entry) }}</span>
            <span>{{ describe(entry) }}</span>
        </li>
    </ol>
</template>
