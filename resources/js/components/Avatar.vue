<script setup>
import { computed } from 'vue';
import { seatAvatar } from '../avatars';

const props = defineProps({
    seat: Number,
    size: { type: String, default: 'md' },
    online: { type: Boolean, default: null },
});

const avatar = computed(() => seatAvatar(props.seat));
</script>

<template>
    <span
        class="relative inline-flex shrink-0 items-center justify-center rounded-full border-[3px] border-ink"
        :class="[avatar.bg, size === 'lg' ? 'size-14 text-3xl' : size === 'sm' ? 'size-8 text-base' : 'size-11 text-2xl']"
        aria-hidden="true"
    >
        {{ avatar.emoji }}
        <span
            v-if="online !== null"
            class="absolute -right-0.5 -bottom-0.5 size-3.5 rounded-full border-2 border-white"
            :class="online ? 'bg-mint' : 'bg-ink-soft/40'"
        />
    </span>
</template>
