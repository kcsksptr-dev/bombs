<script setup>
import { router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import ThemeToggle from '../components/ThemeToggle.vue';
import { useT } from '../i18n';

// The room's chosen game (a key of config/games.php), shown under the logo once picked
defineProps({
    game: { type: String, default: null },
});

const GAME_ICONS = { bombbusters: '💣', moonwell: '🌙' };
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
                <span class="animate-wiggle text-4xl drop-shadow-[0_3px_0_var(--color-line)]">🎲</span>
                <span class="flex min-w-0 flex-col gap-1">
                    <span class="flex gap-1.5 text-2xl leading-none font-bold tracking-tight">
                        <span class="text-berry [-webkit-text-stroke:1.5px_var(--color-line)] [paint-order:stroke_fill]">{{ t('app.brand_first') }}</span>
                        <span class="text-sky [-webkit-text-stroke:1.5px_var(--color-line)] [paint-order:stroke_fill]">{{ t('app.brand_second') }}</span>
                    </span>
                    <span v-if="game" class="truncate text-sm leading-none font-bold text-ink-soft" data-testid="game-title">
                        {{ GAME_ICONS[game] ?? '🎲' }} {{ t(`games.${game}.name`) }}
                    </span>
                </span>
            </a>

            <div class="flex items-center gap-2">
            <ThemeToggle />
            <div class="flex rounded-2xl border-[3px] border-line bg-white p-0.5 shadow-[0_3px_0_var(--color-line)]" role="group" :aria-label="t('language.label')">
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
            </div>
        </header>

        <slot />
    </div>
</template>
