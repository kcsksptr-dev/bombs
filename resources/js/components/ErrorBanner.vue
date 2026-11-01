<script setup>
defineProps({
    message: String,
});
</script>

<template>
    <p v-if="message" class="animate-pop rounded-2xl border-[3px] border-ink bg-berry-soft px-3 py-2 font-medium text-ink" role="alert">
        🙈 {{ message }}
    </p>
</template>
