<script setup>
import { Head, Link, usePage } from '@inertiajs/vue3';
import QRCode from 'qrcode';
import { computed, ref, watchEffect } from 'vue';
import Avatar from '../../components/Avatar.vue';
import PushToggle from '../../components/PushToggle.vue';
import MissionPicker from '../../components/MissionPicker.vue';
import ErrorBanner from '../../components/ErrorBanner.vue';
import RoomPresence from '../../components/RoomPresence.vue';
import AppLayout from '../../layouts/AppLayout.vue';
import { useT } from '../../i18n';
import { useServerActions } from '../../useServerActions';

const props = defineProps({
    room: Object,
    me: Object,
    joinUrl: String,
    capacity: Object,
    missions: Object,
});

const page = usePage();
const t = useT();

const online = ref([]);
const kicked = ref(false);
const copied = ref(false);
const confirmLeave = ref(false);
const qrSvg = ref('');

const base = computed(() => `/rooms/${props.room.code}`);
const { post, resync: reloadRoom } = useServerActions(() => base.value, ['room', 'me']);
const error = computed(() => page.props.errors?.lobby);
const seats = computed(() =>
    Array.from({ length: props.room.capacity }, (_, index) => ({
        number: index + 1,
        player: props.room.players.find((player) => player.seat === index + 1) ?? null,
    })),
);
const enoughPlayers = computed(() => props.room.players.length >= props.capacity.min);
const everyoneReady = computed(() =>
    props.room.players.every((player) => player.ready || player.id === props.room.hostId),
);
const canStart = computed(() => props.me.isHost && enoughPlayers.value && everyoneReady.value);
const minCapacity = computed(() => Math.max(props.capacity.min, props.room.players.length));

watchEffect(async () => {
    qrSvg.value = await QRCode.toString(props.joinUrl, {
        type: 'svg',
        margin: 1,
        color: { dark: '#2b2550', light: '#ffffff' },
    });
});

function resync(payload) {
    if (payload.kicked_user_id === props.me.id) kicked.value = true;
    reloadRoom();
}

function leave() {
    if (!confirmLeave.value) {
        confirmLeave.value = true;
        setTimeout(() => (confirmLeave.value = false), 3000);
        return;
    }
    post('leave');
}

async function copyLink() {
    try {
        await navigator.clipboard.writeText(props.joinUrl);
    } catch {
        // Clipboard API needs a secure context; plain http on the LAN usually isn't one
        const field = Object.assign(document.createElement('textarea'), { value: props.joinUrl });
        document.body.append(field);
        field.select();
        document.execCommand('copy');
        field.remove();
    }
    copied.value = true;
    setTimeout(() => (copied.value = false), 2000);
}
</script>

<template>
    <Head :title="`${t('lobby.title')} ${room.code}`" />

    <AppLayout>
        <!-- Not (or no longer) part of this room -->
        <section v-if="!me.isMember" class="card animate-pop flex flex-col items-center gap-4 text-center">
            <p class="label">{{ t('lobby.room_code') }}</p>
            <p class="rounded-2xl border-[3px] border-ink bg-sun px-5 py-2 text-4xl font-bold tracking-[0.25em] shadow-[0_5px_0_var(--color-ink)]">
                {{ room.code }}
            </p>
            <p class="font-medium">👥 {{ t('lobby.players_count', { count: room.players.length, capacity: room.capacity }) }}</p>

            <ErrorBanner v-if="kicked" :message="t('lobby.kicked')" />
            <ErrorBanner :message="error" />

            <button
                v-if="room.status === 'lobby' && room.players.length < room.capacity"
                type="button"
                class="btn w-full bg-mint text-xl"
                data-testid="join-room"
                @click="post('join')"
            >
                🙋 {{ t('lobby.join_room') }}
            </button>
            <p v-else-if="room.status === 'lobby'">{{ t('lobby.errors.room_full') }}</p>
            <p v-else-if="room.status === 'playing'">{{ t('lobby.game_in_progress') }}</p>
            <p v-else>{{ t('lobby.room_closed') }}</p>

            <Link href="/" class="font-semibold text-ink-soft underline underline-offset-4">{{ t('lobby.back_home') }}</Link>
        </section>

        <RoomPresence v-else v-slot="{ status }" :code="room.code" @online="online = $event" @changed="resync">
            <!-- Room code + QR for the others at the table -->
            <section class="card mb-4 flex items-center gap-4">
                <div class="size-28 shrink-0 overflow-hidden rounded-2xl border-[3px] border-ink bg-white p-1" v-html="qrSvg" />
                <div class="min-w-0 flex-1">
                    <p class="label">{{ t('lobby.room_code') }}</p>
                    <p class="text-4xl font-bold tracking-[0.2em] text-ink" data-testid="room-code">{{ room.code }}</p>
                    <p class="mt-1 text-xs text-ink-soft">📱 {{ t('lobby.scan_to_join') }}</p>
                    <button type="button" class="block w-full truncate text-left text-xs font-semibold text-sky underline underline-offset-2" @click="copyLink">
                        {{ copied ? `✅ ${t('lobby.copied')}` : joinUrl }}
                    </button>
                </div>
            </section>

            <div class="mb-2 flex items-center justify-between px-1">
                <span class="font-semibold">👥 {{ t('lobby.players_count', { count: room.players.length, capacity: room.capacity }) }}</span>
                <span class="chip" :class="status === 'connected' ? 'bg-mint-soft' : 'bg-sun-soft'">
                    {{ status === 'connected' ? '🟢' : '🟡' }} {{ t(`connection.${status}`) }}
                </span>
            </div>

            <ErrorBanner class="mb-3" :message="error" />
            <PushToggle />

            <!-- Seats -->
            <ol class="mb-4 grid grid-cols-2 gap-3">
                <li
                    v-for="seat in seats"
                    :key="seat.number"
                    class="relative flex min-h-28 flex-col rounded-3xl border-[3px] p-3"
                    :class="
                        seat.player
                            ? seat.player.id === me.id
                                ? 'border-ink bg-sun-soft shadow-[0_5px_0_var(--color-ink)]'
                                : 'border-ink bg-white shadow-[0_5px_0_var(--color-ink)]'
                            : 'border-dashed border-ink-soft/50 bg-white/50'
                    "
                    :data-testid="seat.player ? 'seat-taken' : 'seat-empty'"
                >
                    <span class="mb-1 text-xs font-semibold text-ink-soft">{{ t('lobby.seat', { seat: seat.number }) }}</span>

                    <template v-if="seat.player">
                        <div class="flex items-center gap-2">
                            <Avatar :seat="seat.number" :online="online.includes(seat.player.id)" />
                            <div class="min-w-0">
                                <p class="truncate font-bold">
                                    {{ seat.player.name }}
                                    <span v-if="seat.player.id === me.id" class="font-medium text-ink-soft">({{ t('lobby.you') }})</span>
                                </p>
                                <span v-if="seat.player.id === room.hostId" class="chip bg-sun">👑 {{ t('lobby.host') }}</span>
                                <span v-else-if="seat.player.ready" class="chip bg-mint">✅ {{ t('lobby.ready') }}</span>
                                <span v-else class="chip bg-cloud">💤 {{ t('lobby.not_ready') }}</span>
                            </div>
                        </div>
                        <!-- My own tile is the ready switch: the whole tile takes the tap -->
                        <template v-if="seat.player.id === me.id && !me.isHost">
                            <span class="btn btn-sm mt-2 text-center text-sm" :class="seat.player.ready ? 'bg-white' : 'bg-mint'" aria-hidden="true">
                                {{ seat.player.ready ? `💤 ${t('lobby.unready')}` : `✋ ${t('lobby.im_ready')}` }}
                            </span>
                            <button
                                type="button"
                                class="absolute inset-0 rounded-3xl active:bg-ink/10"
                                :aria-label="seat.player.ready ? t('lobby.unready') : t('lobby.im_ready')"
                                :aria-pressed="seat.player.ready"
                                data-testid="ready"
                                @click="post('ready', { ready: !seat.player.ready })"
                            />
                        </template>
                        <div v-if="me.isHost && seat.player.id !== me.id" class="mt-2 flex flex-wrap gap-1.5">
                            <button type="button" class="btn btn-sm bg-white text-xs" @click="post(`host/${seat.player.id}`)">
                                👑 {{ t('lobby.make_host') }}
                            </button>
                            <button type="button" class="btn btn-sm bg-berry-soft text-xs" @click="post(`kick/${seat.player.id}`)">
                                👋 {{ t('lobby.kick') }}
                            </button>
                        </div>
                    </template>

                    <template v-else>
                        <div class="flex flex-1 flex-col items-center justify-center gap-1.5">
                            <span class="text-2xl opacity-40" aria-hidden="true">🪑</span>
                            <button type="button" class="btn btn-sm bg-white" data-testid="sit-here" @click="post('seat', { seat: seat.number })">
                                {{ t('lobby.sit_here') }}
                            </button>
                        </div>
                    </template>
                </li>
            </ol>

            <!-- Game settings: the host changes them, everyone sees them -->
            <section class="card mb-4 flex flex-col gap-4">
                <div v-if="me.isHost" class="flex items-center justify-between">
                    <span class="label">{{ t('lobby.table_size') }}</span>
                    <div class="flex items-center gap-3">
                        <button
                            type="button"
                            class="btn size-11 bg-white p-0 text-2xl"
                            :disabled="room.capacity <= minCapacity"
                            @click="post('capacity', { capacity: room.capacity - 1 })"
                        >
                            −
                        </button>
                        <span class="w-7 text-center text-2xl font-bold">{{ room.capacity }}</span>
                        <button
                            type="button"
                            class="btn size-11 bg-white p-0 text-2xl"
                            :disabled="room.capacity >= capacity.max"
                            @click="post('capacity', { capacity: room.capacity + 1 })"
                        >
                            +
                        </button>
                    </div>
                </div>
                <p v-if="room.capacity >= 6" class="chip self-start bg-lilac">✨ {{ t('lobby.extended_rules') }}</p>

                <MissionPicker
                    :model-value="room.mission"
                    :options="missions"
                    :players="room.players.length"
                    :readonly="!me.isHost"
                    @update:model-value="post('mission', { mission: $event })"
                />

                <template v-if="me.isHost">
                    <button type="button" class="btn bg-sun text-xl" :disabled="!canStart" data-testid="start" @click="post('start')">
                        🚀 {{ t('lobby.start') }}
                    </button>
                    <p v-if="!enoughPlayers" class="text-center text-sm text-ink-soft">{{ t('lobby.need_players', { min: capacity.min }) }}</p>
                    <p v-else-if="!everyoneReady" class="text-center text-sm text-ink-soft">⏳ {{ t('lobby.waiting_ready') }}</p>
                </template>

                <p v-else class="text-center text-sm text-ink-soft">⏳ {{ t('lobby.waiting_for_host') }}</p>
            </section>

            <button
                type="button"
                class="mt-auto py-3 font-semibold"
                :class="confirmLeave ? 'text-berry' : 'text-ink-soft'"
                data-testid="leave"
                @click="leave"
            >
                {{ confirmLeave ? `⚠️ ${t('lobby.leave_confirm')}` : `🚪 ${t('lobby.leave')}` }}
            </button>
        </RoomPresence>
    </AppLayout>
</template>
