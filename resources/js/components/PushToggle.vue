<script setup>
import { usePage } from '@inertiajs/vue3';
import { onMounted, ref } from 'vue';
import { useT } from '../i18n';

/**
 * Offers notifications for turns and nudges. Web push needs HTTPS, so this stays hidden on the
 * plain http LAN address. iPhones only offer push once the site is on the Home Screen.
 */
const t = useT();
const key = usePage().props.pushKey;
const secure = !!key && window.isSecureContext;
const supported = secure && 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;
const needsInstall = secure && !supported && /iPhone|iPad/.test(navigator.userAgent);

const offer = ref(false);
const failed = ref(false);
let registration = null;

function save(subscription) {
    const token = decodeURIComponent(document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? '');

    return fetch('/push-subscription', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': token },
        body: JSON.stringify(subscription),
    });
}

function keyBytes() {
    const base64 = key.replaceAll('-', '+').replaceAll('_', '/');

    return Uint8Array.from(atob(base64), (char) => char.charCodeAt(0));
}

async function enable() {
    failed.value = false;

    try {
        if ((await Notification.requestPermission()) !== 'granted') {
            offer.value = false;
            return;
        }
        await save(await registration.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: keyBytes() }));
        offer.value = false;
    } catch {
        failed.value = true;
    }
}

onMounted(async () => {
    if (!supported) return;

    registration = await navigator.serviceWorker.register('/sw.js');
    const subscription = await registration.pushManager.getSubscription();

    // Saved again each time in case this phone has since been used by another guest
    if (subscription) save(subscription);
    else offer.value = Notification.permission !== 'denied';
});
</script>

<template>
    <p v-if="needsInstall" class="mb-4 rounded-2xl bg-sky-soft px-3 py-2 text-sm font-medium" data-testid="push-install">
        🔔 {{ t('push.install_hint') }}
    </p>
    <section v-else-if="offer" class="card mb-4 bg-sky-soft" data-testid="push-offer">
        <p class="mb-3 font-semibold">🔔 {{ t('push.offer') }}</p>
        <button type="button" class="btn w-full bg-white" data-testid="push-enable" @click="enable">{{ t('push.enable') }}</button>
        <p v-if="failed" class="mt-2 text-sm text-berry">{{ t('push.failed') }}</p>
    </section>
</template>
