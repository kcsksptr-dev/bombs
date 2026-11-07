<script setup>
import { onBeforeUnmount, ref } from 'vue';
import { useT } from '../i18n';

/**
 * Light / dark switch. The theme lives on <html data-theme>, set before first paint by the
 * script in app.blade.php. Until the player taps this, the phone's own setting decides (and
 * follows it live); after a tap the choice is saved on this device.
 */
const KEY = 'bombbusters.theme';
const t = useT();
const dark = ref(document.documentElement.dataset.theme === 'dark');

function apply(isDark) {
    dark.value = isDark;
    document.documentElement.dataset.theme = isDark ? 'dark' : 'light';
    document.querySelector('meta[name=theme-color]')?.setAttribute('content', isDark ? '#1c1838' : '#bfeaff');
}

function toggle() {
    apply(!dark.value);
    try {
        localStorage.setItem(KEY, dark.value ? 'dark' : 'light');
    } catch {
        // private mode: the choice just lasts for this visit
    }
}

// Follow the phone's setting while the player hasn't picked one
const media = window.matchMedia('(prefers-color-scheme: dark)');
function followSystem(event) {
    let saved = null;
    try {
        saved = localStorage.getItem(KEY);
    } catch {
        // ignore
    }
    if (!saved) apply(event.matches);
}
media.addEventListener('change', followSystem);
onBeforeUnmount(() => media.removeEventListener('change', followSystem));
</script>

<template>
    <button
        type="button"
        class="flex size-10 items-center justify-center rounded-2xl border-[3px] border-line bg-white text-xl shadow-[0_3px_0_var(--color-line)]"
        :aria-pressed="dark"
        :aria-label="dark ? t('theme.light') : t('theme.dark')"
        :title="dark ? t('theme.light') : t('theme.dark')"
        data-testid="theme-toggle"
        @click="toggle"
    >
        {{ dark ? '☀️' : '🌙' }}
    </button>
</template>
