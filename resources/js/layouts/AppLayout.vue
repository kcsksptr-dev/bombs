<script setup>
import { router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useT } from '../i18n';

const page = usePage();
const t = useT();
const locale = computed(() => page.props.locale);
const flags = { en: '🇬🇧', hu: '🇭🇺' };

function switchLocale(next) {
    if (next !== locale.value) {
        router.post('/locale', { locale: next }, { preserveScroll: true, preserveState: true });
    }
}
</script>

<template>
    <div class="mx-auto flex min-h-dvh max-w-md flex-col px-4 pt-[max(1rem,env(safe-area-inset-top))] pb-[max(1.5rem,env(safe-area-inset-bottom))]">
        <header class="mb-5 flex items-center justify-between gap-3">
            <a href="/" class="flex items-center gap-2" data-testid="logo">
                <span class="animate-wiggle text-4xl drop-shadow-[0_3px_0_var(--color-ink)]">💣</span>
                <span class="flex gap-1.5 text-2xl leading-none font-bold tracking-tight">
                    <span class="text-berry [-webkit-text-stroke:1.5px_var(--color-ink)] [paint-order:stroke_fill]">Bomb</span>
                    <span class="text-sky [-webkit-text-stroke:1.5px_var(--color-ink)] [paint-order:stroke_fill]">Busters</span>
                </span>
            </a>

            <div class="flex rounded-2xl border-[3px] border-ink bg-white p-0.5 shadow-[0_3px_0_var(--color-ink)]" role="group" :aria-label="t('language.label')">
                <button
                    v-for="code in page.props.locales"
                    :key="code"
                    type="button"
                    class="rounded-xl px-2 py-1 text-sm font-bold uppercase"
                    :class="code === locale ? 'bg-sun text-ink' : 'text-ink-soft'"
                    :aria-pressed="code === locale"
                    :title="t(`language.${code}`)"
                    @click="switchLocale(code)"
                >
                    {{ flags[code] }} {{ code }}
                </button>
            </div>
        </header>

        <slot />
    </div>
</template>
