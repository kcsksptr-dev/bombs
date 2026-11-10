<script setup>
import { computed } from 'vue';

/**
 * One wire on a stand. `wire.color` / `wire.value` are only present when this viewer may see the
 * wire (own stand, or cut / revealed); `wire.info` is a public info token (a number, 'yellow' or 'red').
 */
const props = defineProps({
    wire: { type: Object, required: true },
    large: Boolean,
    selectable: Boolean,
    selected: Boolean,
    // Own wire hidden from onlookers (auto-hide): drawn like a teammate's hidden wire. Cut and
    // revealed wires are public anyway, and info tokens stay visible.
    masked: Boolean,
});

const hide = computed(() => props.masked && !props.wire.cut && !props.wire.revealed);

defineEmits(['select']);

// Paint and state classes for the cable drawing (styles live at the end of resources/css/app.css)
const paint = computed(() => {
    if (props.wire.revealed) return 'wire-red';

    return props.wire.color && !hide.value ? `wire-${props.wire.color}` : 'wire-hidden';
});
const done = computed(() => props.wire.cut || props.wire.revealed);

// Yellow (x.1) and red (x.5) values sort between the blue numbers: show the decimal small
const whole = computed(() => Math.floor(props.wire.value));
const fraction = computed(() => (props.wire.color && props.wire.color !== 'blue' ? `.${Math.round((props.wire.value % 1) * 10)}` : ''));
</script>

<template>
    <button
        type="button"
        class="wire-tile flex shrink-0 transition-transform"
        :class="[
            large ? 'wire-tile--lg h-[4.5rem] w-12 text-2xl' : 'h-12 w-9 text-lg',
            paint,
            wire.cut ? 'wire-cut' : '',
            done ? 'wire-done' : '',
            selected ? '-translate-y-2 ring-4 ring-tangerine ring-offset-2' : '',
            selectable ? 'cursor-pointer active:translate-y-0.5' : 'cursor-default',
            selectable && !selected ? 'animate-[pulse_1.6s_ease-in-out_infinite]' : '',
        ]"
        :disabled="!selectable"
        data-testid="wire"
        :data-cut="wire.cut"
        :data-color="hide ? '' : (wire.color ?? '')"
        :data-masked="hide"
        :data-info="wire.info ?? ''"
        @click="$emit('select')"
    >
        <span v-if="wire.value !== null && !hide" class="wire-label" :class="whole >= 10 && fraction ? 'text-[0.8em]' : ''">{{ whole }}<small>{{ fraction }}</small></span>
        <span v-else class="wire-label" aria-hidden="true">?</span>
        <template v-if="wire.cut">
            <span class="wire-cable wire-cable--top" aria-hidden="true" />
            <span class="wire-cable wire-cable--bottom" aria-hidden="true" />
        </template>
        <span v-else class="wire-cable" aria-hidden="true" />
        <span class="wire-cap wire-cap--top" aria-hidden="true" />
        <span class="wire-cap wire-cap--bottom" aria-hidden="true" />
        <span v-if="wire.cut && !wire.revealed" class="absolute top-[60%] -right-2.5 z-3 -translate-y-1/2 text-xs" aria-hidden="true">✂️</span>
        <span v-if="wire.revealed" class="absolute -top-2 -right-2 z-3 text-base" aria-hidden="true">🚫</span>
        <span
            v-if="wire.info !== null && !wire.cut"
            class="absolute -top-2.5 -right-2.5 z-3 flex size-6 items-center justify-center rounded-full border-2 border-line text-xs text-ink [text-shadow:none]"
            :class="wire.info === 'yellow' ? 'bg-sun' : wire.info === 'red' ? 'bg-berry' : 'bg-white'"
            data-testid="info-token"
        >
            {{ wire.info === 'yellow' ? '🟡' : wire.info === 'red' ? '🔴' : wire.info }}
        </span>
    </button>
</template>
