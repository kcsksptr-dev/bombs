<script setup>
import { Head, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import Avatar from '../../../components/Avatar.vue';
import ErrorBanner from '../../../components/ErrorBanner.vue';
import PushToggle from '../../../components/PushToggle.vue';
import RoomPresence from '../../../components/RoomPresence.vue';
import Garden from '../../../components/moonwell/Garden.vue';
import Moonwell from '../../../components/moonwell/Moonwell.vue';
import MwDefs from '../../../components/moonwell/MwDefs.vue';
import MwFirefly from '../../../components/moonwell/MwFirefly.vue';
import TimePath from '../../../components/moonwell/TimePath.vue';
import AppLayout from '../../../layouts/AppLayout.vue';
import { COLORS } from '../../../moonwell/art';
import { useT } from '../../../i18n';
import { useServerActions } from '../../../useServerActions';

const props = defineProps({
    room: Object,
    game: Object,
    me: Object,
});

const page = usePage();
const t = useT();

const online = ref([]);
const selected = ref(null); // ring slot I'm holding, waiting for a garden cell
const viewing = ref(props.me.id); // whose garden is shown
const confirmUndo = ref(false);
const confirmAbort = ref(false);
const nudgedBy = ref(null);
const nudgeSent = ref(false);
const freshTile = ref(null); // tile id that was just planted (pops in)
const freshWishes = ref({}); // tile id → wish indexes that just came true (fireflies fly in)

const base = computed(() => `/rooms/${props.room.code}`);
const { busy, post, resync } = useServerActions(() => `${base.value}/game`, ['game', 'room', 'me']);

const error = computed(() => page.props.errors?.game);
const players = computed(() => props.game.players);
const names = computed(() => Object.fromEntries(players.value.map((player) => [player.id, player.name])));
const mine = computed(() => players.value.find((player) => player.isMe));
const shown = computed(() => players.value.find((player) => player.id === viewing.value) ?? mine.value);
const turnPlayer = computed(() => players.value.find((player) => player.id === props.game.turn));
const myTurn = computed(() => props.game.me.myTurn);
const finished = computed(() => props.game.phase === 'won');
const held = computed(() => (selected.value === null ? null : props.game.ring[selected.value]));
const placing = computed(() => myTurn.value && held.value !== null && shown.value?.isMe);
const ranking = computed(() => [...players.value].sort((a, b) => a.fireflies - b.fireflies || a.pos - b.pos));

const hint = computed(() => {
    if (finished.value) return '';
    if (!myTurn.value) return t('moonwell.hint_wait', { name: turnPlayer.value?.name ?? '' });
    if (held.value) return t('moonwell.hint_place', { cost: held.value.cost });
    return t('moonwell.hint_pick');
});

// Wishes that were already true, so a resync can tell which fireflies are new
const doneKeys = (list) => new Set(list.flatMap((player) => player.garden.flatMap((cell) => cell.tile.wishes.flatMap((wish, i) => (wish.done ? [`${cell.tile.id}:${i}`] : [])))));
let known = doneKeys(props.game.players);
let freshTimer = null;

// A move happened (mine or someone else's): drop what I was choosing and show what changed
watch(
    () => props.game.logLength,
    (now, before) => {
        selected.value = null;
        const now_ = doneKeys(props.game.players);
        if (now > before) {
            const last = [...props.game.log].reverse().find((entry) => entry.type === 'take');
            freshTile.value = last?.tile ?? null;
            const fresh = {};
            now_.forEach((key) => {
                if (known.has(key)) return;
                const [tile, wish] = key.split(':').map(Number);
                (fresh[tile] ??= []).push(wish);
            });
            freshWishes.value = fresh;
            clearTimeout(freshTimer);
            freshTimer = setTimeout(() => {
                freshTile.value = null;
                freshWishes.value = {};
            }, 1600);
        }
        known = now_;
    },
);

// When it becomes my turn, show my own garden
watch(myTurn, (now) => {
    if (now) viewing.value = props.me.id;
});

function pick(slot) {
    if (busy.value) return;
    selected.value = selected.value === slot ? null : slot;
    viewing.value = props.me.id;
}

function place(x, y) {
    if (busy.value || selected.value === null) return;
    post('take', { slot: selected.value, x, y });
}

function nudge() {
    if (!turnPlayer.value || nudgeSent.value) return;
    post(`nudge/${turnPlayer.value.id}`);
    nudgeSent.value = true;
    setTimeout(() => (nudgeSent.value = false), 30000);
}

function onChanged(payload) {
    if (payload.nudged_user_id === props.me.id) {
        nudgedBy.value = names.value[payload.nudged_by];
        setTimeout(() => (nudgedBy.value = null), 4000);
    }
    resync();
}

function confirmThen(flag, action) {
    if (!flag.value) {
        flag.value = true;
        setTimeout(() => (flag.value = false), 3000);
        return;
    }
    flag.value = false;
    post(action);
}

function describe(entry) {
    const name = names.value[entry.player] ?? '?';
    switch (entry.type) {
        case 'start':
            return t('moonwell.log.start', { name });
        case 'take':
            return (
                t('moonwell.log.take', { name, color: t(`moonwell.color.${entry.color}`), cost: entry.cost }) +
                (entry.granted ? ` ${t('moonwell.log.granted', { count: entry.granted })}` : '')
            );
        case 'refill':
            return t('moonwell.log.refill', { count: entry.count });
        case 'won':
            return t(entry.reason === 'bag' ? 'moonwell.log.won_bag' : 'moonwell.log.won', { name });
        case 'undo':
            return t('moonwell.log.undo', { name });
        default:
            return '';
    }
}
const recent = computed(() => [...props.game.log].reverse().slice(0, 4).map(describe).filter(Boolean));
</script>

<template>
    <Head :title="`${t('games.moonwell.name')} ${room.code}`" />
    <MwDefs />

    <AppLayout game="moonwell">
        <RoomPresence :code="room.code" @online="online = $event" @changed="onChanged">
            <span class="hidden" data-testid="busy" :data-busy="busy ? '1' : '0'" />

            <p v-if="nudgedBy" class="animate-pop mb-3 rounded-2xl bg-sun px-3 py-2 text-center font-semibold" data-testid="nudged">
                👋 {{ t('nudge.received', { name: nudgedBy }) }}
            </p>

            <div class="mw-table overflow-hidden rounded-[28px] border-[3px] border-line bg-[#1d1840] text-[#f3efff] shadow-[0_6px_0_var(--color-line)]">
                <!-- Whose turn -->
                <div class="flex items-center justify-between gap-2 border-b-[3px] border-line bg-[#2b2458] px-4 py-2.5">
                    <span class="font-bold text-[#fff1c4]" data-testid="mw-turn">
                        <template v-if="finished">🏆 {{ t('moonwell.game_over') }}</template>
                        <template v-else-if="myTurn">🌙 {{ t('game.your_turn') }}</template>
                        <template v-else>⏳ {{ t('game.turn_of', { name: turnPlayer?.name ?? '' }) }}</template>
                    </span>
                    <button
                        v-if="!finished && !myTurn && turnPlayer"
                        type="button"
                        class="btn btn-sm bg-sun text-sm"
                        :disabled="busy || nudgeSent"
                        :aria-label="t('nudge.button', { name: turnPlayer.name })"
                        data-testid="nudge"
                        @click="nudge"
                    >
                        {{ nudgeSent ? '✅' : '👋' }} {{ turnPlayer.name }}
                    </button>
                </div>

                <TimePath :players="players" :turn="game.turn" :preview="held ? mine.pos + held.cost : null" />

                <Moonwell
                    :ring="game.ring"
                    :lantern="game.lantern"
                    :available="game.available"
                    :selected="selected"
                    :can-pick="myTurn && !busy"
                    :bag="game.bag"
                    @pick="pick"
                />

                <p v-if="hint" class="mx-4 mb-3 rounded-2xl border-[2.5px] border-line bg-[#fff1c4] px-3 py-1.5 text-center text-sm font-semibold text-[#2b2550]" data-testid="mw-hint">
                    {{ hint }}
                </p>

                <!-- Players: tap a face to look at that garden -->
                <div class="grid grid-cols-4 gap-x-1 gap-y-2 px-3 pb-3" :class="{ 'grid-cols-5': players.length === 5, 'sm:grid-cols-8': players.length > 5 }">
                    <button
                        v-for="player in players"
                        :key="player.id"
                        type="button"
                        class="flex min-w-0 flex-col items-center gap-0.5 rounded-2xl px-1 py-1.5"
                        :class="player.id === shown?.id ? 'bg-white/10' : ''"
                        :aria-pressed="player.id === shown?.id"
                        :data-testid="`mw-player-${player.id}`"
                        @click="viewing = player.id"
                    >
                        <span class="rounded-full" :class="player.id === game.turn ? 'ring-4 ring-[#ffcb3d]' : ''">
                            <Avatar :seat="player.seat" size="sm" :online="online.includes(player.id)" />
                        </span>
                        <span class="w-full truncate text-center text-xs font-semibold">{{ player.isMe ? t('moonwell.you') : player.name }}</span>
                        <span class="flex items-center text-xs font-bold text-[#fff1c4]">
                            <svg viewBox="-9 -9 18 18" class="size-4" aria-hidden="true"><MwFirefly :scale="0.9" /></svg>
                            {{ player.fireflies }}
                        </span>
                    </button>
                </div>

                <!-- Garden -->
                <div class="px-3 pb-3">
                    <div class="mb-1.5 flex items-baseline justify-between px-1">
                        <span class="font-bold text-[#fff1c4]">{{ shown.isMe ? t('moonwell.your_garden') : t('moonwell.garden_of', { name: shown.name }) }}</span>
                        <span class="text-xs text-[#cfc8f0]">{{ t('moonwell.fireflies_left', { count: shown.fireflies }) }}</span>
                    </div>
                    <Garden
                        :garden="shown.garden"
                        :open-cells="game.me.openCells"
                        :placing="placing && !busy"
                        :fresh-tile="freshTile"
                        :fresh-wishes="freshWishes"
                        @place="place"
                    />
                    <button v-if="held && !shown.isMe" type="button" class="btn btn-sm mt-2 w-full bg-sun" @click="viewing = me.id">
                        🌱 {{ t('moonwell.back_to_my_garden') }}
                    </button>
                </div>
            </div>

            <ErrorBanner class="mt-3" :message="error" />

            <!-- Game over -->
            <section v-if="finished" class="card animate-pop mt-4 flex flex-col gap-3" data-testid="mw-finished">
                <h2 class="heading text-center">
                    🏆 {{ game.winner === me.id ? t('moonwell.won_you') : t('moonwell.won_other', { name: names[game.winner] }) }}
                </h2>
                <ol class="flex flex-col gap-1.5">
                    <li v-for="(player, index) in ranking" :key="player.id" class="flex items-center gap-2">
                        <span class="w-5 text-right font-bold text-ink-soft">{{ index + 1 }}.</span>
                        <Avatar :seat="player.seat" size="sm" />
                        <span class="min-w-0 flex-1 truncate font-semibold">{{ player.name }}</span>
                        <span class="chip bg-sun-soft">✨ {{ t('moonwell.fireflies_left', { count: player.fireflies }) }}</span>
                    </li>
                </ol>
                <button v-if="me.isHost" type="button" class="btn bg-sun text-xl" :disabled="busy" data-testid="back-to-lobby" @click="post('lobby')">
                    🏠 {{ t('game.back_to_lobby') }}
                </button>
                <p v-else class="text-center text-sm text-ink-soft">⏳ {{ t('lobby.waiting_for_host') }}</p>
            </section>

            <!-- What just happened -->
            <ul v-if="recent.length" class="mt-4 flex flex-col gap-1 px-1 text-sm text-ink-soft" data-testid="mw-log">
                <li v-for="(line, index) in recent" :key="`${game.logLength}-${index}`" :class="{ 'font-semibold text-ink': index === 0 }">{{ line }}</li>
            </ul>

            <details class="card mt-4">
                <summary class="cursor-pointer font-bold">📖 {{ t('moonwell.rules_title') }}</summary>
                <ol class="mt-2 flex list-decimal flex-col gap-1.5 pl-5 text-sm">
                    <li v-for="n in 4" :key="n">{{ t(`moonwell.rules_${n}`) }}</li>
                </ol>
                <p class="mt-2 text-sm text-ink-soft">
                    <span v-for="(c, key) in COLORS" :key="key" class="mr-3 whitespace-nowrap">{{ c.glyph }} {{ t(`moonwell.color.${key}`) }}</span>
                </p>
            </details>

            <PushToggle class="mt-4" />

            <div v-if="me.isHost" class="mt-4 flex flex-wrap justify-center gap-2">
                <button v-if="game.undoSteps" type="button" class="btn btn-sm bg-white" :disabled="busy" data-testid="undo" @click="confirmThen(confirmUndo, 'undo')">
                    {{ confirmUndo ? `⚠️ ${t('game.undo_confirm')}` : `↩️ ${t('game.undo')}` }}
                </button>
                <button v-if="!finished" type="button" class="btn btn-sm bg-berry-soft" :disabled="busy" data-testid="abort" @click="confirmThen(confirmAbort, 'lobby')">
                    {{ confirmAbort ? `⚠️ ${t('moonwell.abort_confirm')}` : `🏠 ${t('game.back_to_lobby')}` }}
                </button>
            </div>
        </RoomPresence>
    </AppLayout>
</template>
