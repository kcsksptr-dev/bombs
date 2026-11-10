<script setup>
import { onBeforeUnmount, ref, watch } from 'vue';
import { useT } from '../../i18n';
import { describeEntry, entryIcon, entryTone } from './logEntry';

/**
 * Pops every new log entry up as a speech bubble at the top of the screen, so nobody misses a
 * move while looking at their own wires. Bubbles fade out on their own and let taps through
 * (pointer-events-none), so they can never swallow a tap meant for a wire or a button.
 */
const props = defineProps({
    log: { type: Array, required: true },
    logLength: { type: Number, required: true },
    undoSteps: { type: Number, default: 0 },
    names: { type: Object, required: true },
});

const t = useT();
const bubbles = ref([]); // { id, icon, tone, text }
const MAX = 3;
const timers = new Map();
let nextId = 0;

// Long sentences stay up a little longer
const lifetime = (text) => Math.min(8000, 3500 + text.length * 45);

function show(entry) {
    const text = describeEntry(t, props.names, entry);
    const id = ++nextId;

    bubbles.value = [{ id, icon: entryIcon(entry), tone: entryTone(entry), text }, ...bubbles.value].slice(0, MAX);
    timers.set(
        id,
        setTimeout(() => {
            bubbles.value = bubbles.value.filter((bubble) => bubble.id !== id);
            timers.delete(id);
        }, lifetime(text)),
    );
}

watch([() => props.logLength, () => props.undoSteps], ([now, steps], [before, stepsBefore]) => {
    // Only moves that arrive while we're watching, not the history on page load
    if (before === undefined) return;

    if (now > before) {
        props.log.slice(-(now - before)).forEach(show);
    } else if (steps !== stepsBefore && props.log.at(-1)?.type === 'undo') {
        // An undo can leave the log the same length (or shorter), but it always ends with an undo entry
        show(props.log.at(-1));
    }
});

onBeforeUnmount(() => timers.forEach(clearTimeout));
</script>

<template>
    <div
        class="pointer-events-none fixed inset-x-0 top-0 z-30 mx-auto flex max-w-md flex-col gap-2 px-4 pt-[max(0.75rem,env(safe-area-inset-top))]"
        aria-live="polite"
        data-testid="log-bubbles"
    >
        <TransitionGroup
            enter-from-class="-translate-y-4 opacity-0"
            leave-to-class="opacity-0"
            enter-active-class="transition duration-300 ease-out"
            leave-active-class="transition duration-500 ease-in"
        >
            <p
                v-for="bubble in bubbles"
                :key="bubble.id"
                class="flex items-start gap-2 rounded-3xl border-[3px] border-line px-3 py-2 font-semibold shadow-[0_4px_0_var(--color-line)]"
                :class="bubble.tone"
                data-testid="log-bubble"
            >
                <span class="text-xl leading-6" aria-hidden="true">{{ bubble.icon }}</span>
                <span class="leading-6">{{ bubble.text }}</span>
            </p>
        </TransitionGroup>
    </div>
</template>
