<script setup>
import { useConnectionStatus, useEchoPresence } from '@laravel/echo-vue';
import { onBeforeUnmount, onMounted, watch } from 'vue';

/**
 * Joins the room's presence channel. Mounted only for room members, because the channel
 * authorisation rejects everyone else.
 *
 * - `online`: ids of players whose devices are currently connected
 * - `changed`: the room changed on the server (payload may carry e.g. kicked_user_id), or this
 *   device just came back (socket reconnected / phone unlocked), so the view should resync
 */
const props = defineProps({
    code: { type: String, required: true },
});

const emit = defineEmits(['online', 'changed']);

const { channel } = useEchoPresence(`room.${props.code}`, '.room.updated', (payload) => emit('changed', payload ?? {}));

// Read members from the underlying Pusher subscription rather than Echo's here()/joining()/leaving():
// echo-vue shares one subscription between components (e.g. Lobby → Game), and a reused, already
// subscribed channel never fires here() again. Handlers are unbound on unmount so they don't pile up.
const subscription = channel().subscription;
const publish = () => {
    const ids = [];
    subscription.members?.each((member) => ids.push(Number(member.id)));
    emit('online', ids);
};
const membershipEvents = ['pusher:subscription_succeeded', 'pusher:member_added', 'pusher:member_removed'];

membershipEvents.forEach((event) => subscription.bind(event, publish));
if (subscription.subscribed) publish();

onBeforeUnmount(() => membershipEvents.forEach((event) => subscription.unbind(event, publish)));

const status = useConnectionStatus();
watch(status, (now, before) => {
    if (now === 'connected' && before && before !== 'connected') emit('changed', {});
});

function onVisible() {
    if (document.visibilityState === 'visible') emit('changed', {});
}

onMounted(() => document.addEventListener('visibilitychange', onVisible));
onBeforeUnmount(() => document.removeEventListener('visibilitychange', onVisible));
</script>

<template>
    <slot :status="status" />
</template>
