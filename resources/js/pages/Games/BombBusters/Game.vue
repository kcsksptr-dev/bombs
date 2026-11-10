<script setup>
import { Head, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import Avatar from '../../../components/Avatar.vue';
import ErrorBanner from '../../../components/ErrorBanner.vue';
import PushToggle from '../../../components/PushToggle.vue';
import RoomPresence from '../../../components/RoomPresence.vue';
import CutBoard from '../../../components/bombbusters/CutBoard.vue';
import CutFx from '../../../components/bombbusters/CutFx.vue';
import Detonator from '../../../components/bombbusters/Detonator.vue';
import EquipmentGuide from '../../../components/bombbusters/EquipmentGuide.vue';
import EquipmentPanel from '../../../components/bombbusters/EquipmentPanel.vue';
import GameLog from '../../../components/bombbusters/GameLog.vue';
import LogBubbles from '../../../components/bombbusters/LogBubbles.vue';
import WireTile from '../../../components/bombbusters/WireTile.vue';
import AppLayout from '../../../layouts/AppLayout.vue';
import { EQUIPMENT_ICON, EQUIPMENT_INPUT, kindLabel } from '../../../bombbusters/game';
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
const tab = ref('bomb');
const target = ref(null); // { stand, index } picked for a Dual Cut
const infoPick = ref(null); // index of own wire picked for a setup info token
const equipping = ref(null); // equipment id waiting for a wire / number
const equipPick = ref(null); // own wire picked for that equipment, waiting for confirmation
const equipTarget = ref(null); // teammate stand (and wire) picked for that equipment: { stand, index }
const equipKinds = ref([]); // kinds picked for the X or Y Ray
const equipPlayers = ref([]); // players picked for the Emergency Batteries
const detector = ref(null); // Double Detector in progress: { stand, indices }
const confirmAbort = ref(false);
const confirmUndo = ref(false);
const nudgedBy = ref(null); // name of the teammate who just called me back
const nudgeSent = ref([]); // teammates I've just nudged, while the cooldown runs

const NUDGE_COOLDOWN_MS = 30000; // bombbusters.push.nudge_cooldown

// Auto-hide: my own wires look hidden (so people around me can't read them) and show only while
// my finger is pressed on them. Any press shows them, so a normal tap still selects a wire.
const HIDE_KEY = 'bombbusters.hideWires';
const hideWires = ref(readFlag(HIDE_KEY));
const peeking = ref(false);
const masked = computed(() => hideWires.value && !peeking.value);

function readFlag(key) {
    try {
        return localStorage.getItem(key) === '1';
    } catch {
        return false;
    }
}

function toggleHideWires() {
    hideWires.value = !hideWires.value;
    try {
        localStorage.setItem(HIDE_KEY, hideWires.value ? '1' : '0');
    } catch {
        // private mode: the setting just doesn't persist
    }
}

function peek() {
    if (!hideWires.value) return;
    peeking.value = true;
    // Released anywhere (or the press turned into a scroll): hide again
    const stop = () => {
        peeking.value = false;
        window.removeEventListener('pointerup', stop);
        window.removeEventListener('pointercancel', stop);
        window.removeEventListener('blur', stop);
    };
    window.addEventListener('pointerup', stop);
    window.addEventListener('pointercancel', stop);
    window.addEventListener('blur', stop);
}

// Wire zoom: a per-phone display setting. CSS `zoom` changes the layout size (not a transform),
// so tap targets grow with the tiles and the rows simply wrap; it never touches the selection.
const ZOOM_LEVELS = [0.8, 1, 1.25, 1.5, 1.75, 2];
const ZOOM_KEY = 'bombbusters.wireZoom';
const wireZoom = ref(readZoom());
const zoomIndex = computed(() => ZOOM_LEVELS.indexOf(wireZoom.value));

function readZoom() {
    try {
        const saved = Number(localStorage.getItem(ZOOM_KEY));
        return ZOOM_LEVELS.includes(saved) ? saved : 1;
    } catch {
        return 1;
    }
}

function stepZoom(step) {
    const next = ZOOM_LEVELS[zoomIndex.value + step];
    if (next === undefined) return;
    wireZoom.value = next;
    try {
        localStorage.setItem(ZOOM_KEY, String(next));
    } catch {
        // private mode: the setting just doesn't persist
    }
}

const base = computed(() => `/rooms/${props.room.code}`);
const { busy, post, resync } = useServerActions(() => `${base.value}/game`, ['game', 'room', 'me']);

const error = computed(() => page.props.errors?.game);
const myStand = computed(() => props.game.stands.find((stand) => stand.isMe));
const others = computed(() => props.game.stands.filter((stand) => !stand.isMe));
const names = computed(() => Object.fromEntries(props.game.stands.map((stand) => [stand.id, stand.name])));
const turnStand = computed(() => props.game.stands.find((stand) => stand.id === props.game.turn));
const finished = computed(() => props.game.phase === 'won' || props.game.phase === 'lost');
const targetStand = computed(() => props.game.stands.find((stand) => stand.id === target.value?.stand));
const detectorStand = computed(() => props.game.stands.find((stand) => stand.id === detector.value?.stand));
const detectorReady = computed(() => !!detector.value && detector.value.indices.length === detector.value.size);
const equipTargetStand = computed(() => props.game.stands.find((stand) => stand.id === equipTarget.value?.stand));
const pendingActor = computed(() => props.game.stands.find((stand) => stand.id === props.game.pending?.actor));
// Emergency Batteries: whose Double Detector is spent; Coffee Mug: who could go next
const usedDetectors = computed(() => props.game.stands.filter((stand) => stand.detectorUsed));
const nextPlayers = computed(() => others.value.filter((stand) => wiresLeft(stand) > 0));
const pendingPlayer = computed(() => props.game.stands.find((stand) => stand.id === props.game.pending?.player));
const placingInfo = computed(() => props.game.me.needsInfoToken);
const readyEquipment = computed(() => props.game.equipment.filter((card) => card.unlocked && !card.used).length);
const equipInput = computed(() => (equipping.value ? EQUIPMENT_INPUT[equipping.value] : null));
const candidates = computed(() =>
    ['yellow', 'red']
        .map((color) => ({
            color,
            values: props.game.candidates[color] ?? [],
            count: props.game.cutBoard.find((slot) => slot.kind === color)?.total ?? 0,
        }))
        .filter((group) => group.values.length),
);

// A selection belongs to the move it was made in: drop it once a move happens (a new log entry)
// or is taken back, but survive resyncs that change nothing (reconnects, the phone waking up,
// late notifications). undoSteps is watched too: an undo can leave the log the same length.
watch([() => props.game.logLength, () => props.game.undoSteps], () => {
    confirmUndo.value = false;
    target.value = null;
    infoPick.value = null;
    equipping.value = null;
    equipPick.value = null;
    equipTarget.value = null;
    detector.value = null;
});

// Picking another card (or cancelling) drops whatever was picked for the previous one
watch(equipping, () => {
    equipPick.value = null;
    equipTarget.value = null;
    equipKinds.value = [];
    equipPlayers.value = [];
});

// Own wires highlighted: the setup info token pick, or the wire(s) an equipment card will use
function mineSelected(index) {
    if (infoPick.value === index || equipPick.value === index) return true;

    return equipInput.value === 'pair' && equipPick.value !== null && equipPick.value + 1 === index;
}

const wiresLeft = (stand) => stand.wires.filter((wire) => !wire.cut).length;
const labelAt = (stand, index) => stand.labels.find((label) => label.index === index);

// Which of my own wires can be tapped right now, and what tapping does
function mineSelectable(wire, index) {
    if (busy.value || wire.cut) return false;
    if (placingInfo.value) return wire.info === null && wire.color === 'blue';
    if (equipInput.value === 'wire') return wire.info === null && wire.color === 'blue';
    if (equipInput.value === 'swap') return wire.info === null;
    if (equipInput.value === 'pair') return myStand.value.wires[index + 1] && !myStand.value.wires[index + 1].cut;
    return false;
}

function tapMine(index) {
    if (placingInfo.value) infoPick.value = index;
    else if (equipping.value) equipPick.value = equipPick.value === index ? null : index;
}

function pickTarget(stand, index) {
    if (detector.value) return pickForDetector(stand, index);
    if (equipInput.value === 'stand') return (equipTarget.value = { stand: stand.id, index: null });
    if (equipInput.value === 'xy') return (equipTarget.value = { stand: stand.id, index });

    target.value = target.value?.stand === stand.id && target.value?.index === index ? null : { stand: stand.id, index };
}

// Double Detector: two wires on the same teammate's stand
function pickForDetector(stand, index) {
    const picked = detector.value.indices;

    if (picked.includes(index)) {
        detector.value = { ...detector.value, stand: picked.length > 1 ? stand.id : null, indices: picked.filter((i) => i !== index) };
    } else if (picked.length < detector.value.size) {
        detector.value = { ...detector.value, stand: stand.id, indices: [...picked, index].sort((a, b) => a - b) };
    }
}

function teammateSelectable(stand, wire) {
    if (!props.game.me.myTurn || wire.cut || busy.value) return false;
    if (equipping.value) return equipInput.value === 'stand' || equipInput.value === 'xy';
    if (!detector.value) return true;

    return detector.value.stand === null || (detector.value.stand === stand.id && detector.value.indices.length < detector.value.size);
}

function isPicked(stand, index) {
    if (detector.value) return detector.value.stand === stand.id && detector.value.indices.includes(index);
    if (equipTarget.value) return equipTarget.value.stand === stand.id && (equipTarget.value.index === null || equipTarget.value.index === index);

    return target.value?.stand === stand.id && target.value?.index === index;
}

function toggleDetector() {
    target.value = null;
    detector.value = detector.value ? null : { stand: null, indices: [], size: 2, card: null };
}

function useDetector(value) {
    const { stand, indices, card } = detector.value;
    if (card) useEquipment(card, { target: stand, indices, value });
    else post('double-detector', { target: stand, indices, value });
}

// Pick or unpick up to two (X or Y Ray kinds, Emergency Batteries players). Takes the ref
// itself, so the template calls these wrappers (it would unwrap a ref passed directly).
function toggleUpTo(list, value, max) {
    if (list.value.includes(value)) list.value = list.value.filter((v) => v !== value);
    else if (list.value.length < max) list.value = [...list.value, value];
}
const toggleXyKind = (value) => toggleUpTo(equipKinds, value, 2);
const togglePlayer = (id) => toggleUpTo(equipPlayers, id, 2);

function dualCut(value) {
    post('dual-cut', { target: target.value.stand, index: target.value.index, value });
}

function chooseEquipment(id) {
    target.value = null;
    tab.value = 'bomb';

    // The Triple Detector picks wires exactly like the personal Double Detector
    if (EQUIPMENT_INPUT[id] === 'detector') {
        equipping.value = null;
        detector.value = { stand: null, indices: [], size: 3, card: id };
        return;
    }

    detector.value = null;
    equipping.value = id;
}

function useEquipment(id, params = {}) {
    post(`equipment/${id}`, params);
    equipping.value = null;
    detector.value = null;
    tab.value = 'bomb';
}

function cancelEquipment(id) {
    post(`equipment/${id}/cancel`);
}

function nudge(stand) {
    post(`nudge/${stand.id}`);
    nudgeSent.value = [...nudgeSent.value, stand.id];
    setTimeout(() => (nudgeSent.value = nudgeSent.value.filter((id) => id !== stand.id)), NUDGE_COOLDOWN_MS);
}

function onChanged(payload) {
    if (payload.nudged_user_id === props.me.id) {
        nudgedBy.value = names.value[payload.nudged_by];
        navigator.vibrate?.(300);
        setTimeout(() => (nudgedBy.value = null), 4000);
    }
    resync();
}

function undo() {
    if (!confirmUndo.value) {
        confirmUndo.value = true;
        setTimeout(() => (confirmUndo.value = false), 3000);
        return;
    }
    confirmUndo.value = false;
    post('undo');
}

function abort() {
    if (!confirmAbort.value) {
        confirmAbort.value = true;
        setTimeout(() => (confirmAbort.value = false), 3000);
        return;
    }
    post('lobby');
}
</script>

<template>
    <Head :title="`${t('game.title')} ${room.code}`" />

    <AppLayout game="bombbusters">
        <RoomPresence :code="room.code" @online="online = $event" @changed="onChanged">
            <span class="hidden" data-testid="busy" :data-busy="busy" />
            <CutFx :log="game.log" :log-length="game.logLength" />
            <LogBubbles :log="game.log" :log-length="game.logLength" :undo-steps="game.undoSteps" :names="names" />

            <!-- Status: fuse, what's in the bomb, whose turn -->
            <section class="card mb-4 flex flex-col gap-3 p-3">
                <div class="flex flex-wrap items-center justify-between gap-1.5">
                    <span class="chip bg-sun">🗺️ {{ t('mission.title', { number: game.mission }) }} · {{ t(`mission.${game.mission}.name`) }}</span>
                    <span class="chip bg-white">{{ room.code }}</span>
                </div>
                <Detonator :detonator="game.detonator" />
                <div v-if="game.sequence.length" class="flex flex-wrap items-center gap-1" data-testid="sequence">
                    <span class="label mr-1">🔢 {{ t('game.sequence') }}:</span>
                    <template v-for="(step, i) in game.sequence" :key="i">
                        <span v-if="i" class="text-ink-soft" aria-hidden="true">→</span>
                        <span
                            class="chip"
                            :class="step.done ? 'bg-mint line-through' : step.open ? 'animate-pulse bg-sun' : 'bg-cloud opacity-70'"
                            :data-step="step.kind"
                            :data-state="step.done ? 'done' : step.open ? 'open' : 'locked'"
                        >
                            {{ step.done ? '✅' : step.open ? '👉' : '🔒' }} {{ kindLabel(t, step.kind) }}
                        </span>
                    </template>
                </div>
                <div v-if="candidates.length" class="flex flex-wrap gap-1.5" data-testid="candidates">
                    <span
                        v-for="group in candidates"
                        :key="group.color"
                        class="chip whitespace-normal"
                        :class="group.color === 'yellow' ? 'bg-sun-soft' : 'bg-berry-soft'"
                    >
                        {{ group.color === 'yellow' ? '🟡' : '🔴' }}
                        {{ t(`game.candidates_${group.color}`, { count: group.count, values: group.values.join(' · ') }) }}
                    </span>
                </div>
                <p
                    v-if="game.phase === 'playing' && game.turn === me.id && !game.pending"
                    class="animate-wiggle rounded-2xl border-[3px] border-line bg-sun py-2 text-center text-xl font-bold shadow-[0_4px_0_var(--color-line)]"
                    data-testid="your-turn"
                >
                    ⭐ {{ t('game.your_turn') }} ⭐
                </p>
                <p
                    v-else-if="game.pending && !game.me.mustRevealForDetector && !game.me.mustAnswerWalkie"
                    class="flex items-center justify-center gap-2 rounded-2xl bg-lilac px-3 py-2 text-center font-semibold"
                    data-testid="detector-waiting"
                >
                    <template v-if="game.pending.type === 'walkie'">📻 {{ t('walkie.waiting', { name: pendingPlayer?.name }) }}</template>
                    <template v-else>🔍 {{ t('detector.waiting', { name: pendingPlayer?.name }) }}</template>
                </p>
                <p v-else-if="game.phase === 'playing'" class="flex items-center justify-center gap-2 rounded-2xl bg-cloud py-2 font-semibold" data-testid="turn-of">
                    <Avatar :seat="turnStand?.seat" size="sm" />
                    {{ t('game.turn_of', { name: turnStand?.name }) }}
                </p>
                <p v-else-if="game.phase === 'setup'" class="rounded-2xl bg-sun-soft px-3 py-2 text-center font-medium">
                    💡 {{ placingInfo ? t('game.setup_hint') : t('game.setup_waiting') }}
                </p>
            </section>

            <!-- Mission over -->
            <section
                v-if="finished"
                class="card animate-pop mb-4 text-center"
                :class="game.phase === 'won' ? 'bg-mint-soft' : 'bg-berry-soft'"
                data-testid="result"
                :data-result="game.phase"
            >
                <p class="text-6xl">{{ game.phase === 'won' ? '🏆' : '💥' }}</p>
                <h2 class="mt-1 text-3xl font-bold">{{ t(`game.${game.phase}_title`) }}</h2>
                <p class="mt-1 text-ink-soft">{{ t(`game.${game.phase}_text`) }}</p>
                <template v-if="me.isHost">
                    <button
                        v-if="game.undoSteps"
                        type="button"
                        class="btn mt-4 w-full bg-white"
                        :disabled="busy"
                        data-testid="undo"
                        @click="undo"
                    >
                        {{ confirmUndo ? `⚠️ ${t('game.undo_confirm')}` : `↩️ ${t('game.undo')}` }}
                    </button>
                    <button
                        v-if="game.phase === 'won' && game.hasNextMission"
                        type="button"
                        class="btn mt-4 w-full bg-mint text-xl"
                        :disabled="busy"
                        data-testid="next-mission"
                        @click="post('next-mission')"
                    >
                        ▶️ {{ t('game.next_mission') }}
                    </button>
                    <button type="button" class="btn mt-3 w-full bg-sun text-xl" :disabled="busy" data-testid="back-to-lobby" @click="post('lobby')">
                        🏠 {{ t('game.back_to_lobby') }}
                    </button>
                </template>
                <p v-else class="mt-3 text-sm text-ink-soft">⏳ {{ t('game.waiting_for_host') }}</p>
            </section>

            <ErrorBanner class="mb-3" :message="error" />
            <PushToggle />
            <p v-if="nudgedBy" class="animate-pop mb-3 rounded-2xl bg-sun px-3 py-2 text-center font-semibold" data-testid="nudged">
                👋 {{ t('nudge.received', { name: nudgedBy }) }}
            </p>

            <!-- A Double Detector missed my wires: I choose which one everyone gets to see -->
            <section v-if="game.me.mustRevealForDetector && myStand" class="card animate-pop mb-4 bg-lilac" data-testid="detector-reveal">
                <p class="heading">🔍 {{ t('detector.reveal_title') }}</p>
                <p class="mb-3 font-medium">
                    {{
                        t('detector.reveal_prompt', {
                            actor: names[game.pending.actor],
                            value: kindLabel(t, game.pending.value),
                            positions: game.pending.indices.map((i) => `#${i + 1}`).join(', '),
                        })
                    }}
                </p>
                <div
                    class="flex justify-center gap-4"
                    :class="hideWires ? 'select-none [-webkit-touch-callout:none]' : ''"
                    @pointerdown="peek"
                    @contextmenu="hideWires && $event.preventDefault()"
                >
                    <div v-for="index in game.pending.indices" :key="index" class="flex flex-col items-center gap-1">
                        <WireTile :wire="myStand.wires[index]" large :masked="masked" :selectable="!busy" @select="post('detector-reveal', { index })" />
                        <span class="text-xs font-semibold text-ink-soft">#{{ index + 1 }}</span>
                    </div>
                </div>
            </section>

            <!-- Walkie-Talkies: a teammate gives me one of their wires; I pick the one I give back -->
            <section v-if="game.me.mustAnswerWalkie && myStand" class="card animate-pop mb-4 bg-lilac" data-testid="walkie-answer">
                <p class="heading">📻 {{ t('equipment.walkie_talkies.name') }}</p>
                <p class="mb-3 font-medium">{{ t('walkie.prompt', { actor: pendingActor?.name, position: game.pending.index + 1 }) }}</p>
                <div
                    class="flex flex-wrap justify-center gap-2"
                    :class="hideWires ? 'select-none [-webkit-touch-callout:none]' : ''"
                    @pointerdown="peek"
                    @contextmenu="hideWires && $event.preventDefault()"
                >
                    <WireTile
                        v-for="(wire, index) in myStand.wires"
                        :key="index"
                        :wire="wire"
                        large
                        :masked="masked"
                        :selectable="!busy && !wire.cut && wire.info === null"
                        @select="post('walkie', { index })"
                    />
                </div>
            </section>

            <!-- Tabs -->
            <div class="mb-4 grid grid-cols-2 gap-2" role="tablist">
                <button
                    type="button"
                    role="tab"
                    class="btn py-2"
                    :class="tab === 'bomb' ? 'bg-sun' : 'bg-white opacity-75'"
                    :aria-selected="tab === 'bomb'"
                    data-testid="tab-bomb"
                    @click="tab = 'bomb'"
                >
                    💣 {{ t('game.tab_board') }}
                </button>
                <button
                    type="button"
                    role="tab"
                    class="btn relative py-2"
                    :class="tab === 'equipment' ? 'bg-sun' : 'bg-white opacity-75'"
                    :aria-selected="tab === 'equipment'"
                    data-testid="tab-equipment"
                    @click="tab = 'equipment'"
                >
                    🧰 {{ t('game.tab_equipment') }}
                    <span
                        v-if="readyEquipment"
                        class="absolute -top-2 -right-2 flex size-6 items-center justify-center rounded-full border-2 border-line bg-mint text-sm"
                    >
                        {{ readyEquipment }}
                    </span>
                </button>
            </div>

            <template v-if="tab === 'equipment'">
                <EquipmentPanel
                    class="mb-4"
                    :equipment="game.equipment"
                    :my-turn="game.me.myTurn"
                    :busy="busy"
                    :stabilized="game.stabilized === me.id"
                    @use="useEquipment"
                    @choose="chooseEquipment"
                    @cancel="cancelEquipment"
                />
                <EquipmentGuide class="mb-4" :in-play="game.equipment.map((card) => card.id)" />
            </template>

            <template v-else>
                <!-- Wire zoom (display only; lives outside the stands so it can't be mistaken for a cut) -->
                <div class="mb-3 flex items-center justify-end gap-2" data-testid="wire-zoom">
                    <span class="label">{{ t('game.wire_zoom') }}</span>
                    <button
                        type="button"
                        class="btn btn-sm bg-white px-3"
                        :disabled="zoomIndex <= 0"
                        :aria-label="t('game.wire_zoom_out')"
                        data-testid="wire-zoom-out"
                        @click="stepZoom(-1)"
                    >
                        ➖
                    </button>
                    <span class="w-12 text-center font-semibold tabular-nums" data-testid="wire-zoom-level">{{ Math.round(wireZoom * 100) }}%</span>
                    <button
                        type="button"
                        class="btn btn-sm bg-white px-3"
                        :disabled="zoomIndex >= ZOOM_LEVELS.length - 1"
                        :aria-label="t('game.wire_zoom_in')"
                        data-testid="wire-zoom-in"
                        @click="stepZoom(1)"
                    >
                        ➕
                    </button>
                </div>

                <!-- A switched-on Stabilizer, visible (and cancellable) while I'm choosing a cut -->
                <div
                    v-if="game.stabilized === me.id && game.me.myTurn"
                    class="mb-3 flex items-center gap-2 rounded-2xl border-[3px] border-line bg-sky-soft px-3 py-2 text-sm font-semibold"
                    data-testid="stabilizer-on"
                >
                    <span class="flex-1">🛡️ {{ t('equipment.stabilized') }}</span>
                    <button type="button" class="btn btn-sm shrink-0 bg-white" :disabled="busy" @click="cancelEquipment('stabilizer')">
                        {{ t('equipment.cancel_stabilizer') }}
                    </button>
                </div>

                <!-- My stand -->
                <section v-if="myStand" class="card mb-4" :class="placingInfo || equipping ? 'bg-sun-soft' : ''" data-testid="my-stand">
                    <div class="mb-2 flex items-center gap-2">
                        <Avatar :seat="myStand.seat" size="sm" />
                        <span class="font-bold">
                            {{ placingInfo ? t('game.setup_title') : t('game.your_stand') }}
                        </span>
                        <span v-if="myStand.id === game.captain" class="chip ml-auto bg-sun">⭐ {{ t('game.captain') }}</span>
                        <button
                            type="button"
                            class="btn btn-sm shrink-0 px-2"
                            :class="[hideWires ? 'bg-lilac' : 'bg-white', myStand.id === game.captain ? '' : 'ml-auto']"
                            :aria-pressed="hideWires"
                            :aria-label="t(hideWires ? 'game.hide_wires_off' : 'game.hide_wires_on')"
                            :title="t(hideWires ? 'game.hide_wires_off' : 'game.hide_wires_on')"
                            data-testid="hide-wires"
                            @click="toggleHideWires"
                        >
                            {{ hideWires ? '🙈' : '👁️' }}
                        </button>
                    </div>
                    <p v-if="hideWires" class="mb-1 text-center text-xs font-semibold text-ink-soft" data-testid="hide-wires-hint">
                        👆 {{ t('game.hide_wires_hint') }}
                    </p>
                    <div v-if="game.phase === 'playing'" class="mb-2 flex items-center gap-2">
                        <button
                            v-if="game.me.canUseDetector"
                            type="button"
                            class="btn btn-sm"
                            :class="detector ? 'bg-lilac' : 'bg-white'"
                            :aria-pressed="!!detector"
                            :title="t('detector.text')"
                            data-testid="detector"
                            @click="toggleDetector"
                        >
                            🔍 {{ detector ? t('game.cancel') : t('detector.use') }}
                        </button>
                        <span v-else-if="myStand.detectorUsed" class="chip bg-cloud opacity-60">🔍 {{ t('detector.used') }}</span>
                    </div>
                    <p v-if="equipping" class="mb-2 flex items-center justify-between gap-2 rounded-2xl bg-white px-3 py-1.5 text-sm font-semibold">
                        <span>{{ EQUIPMENT_ICON[equipping] }} {{ t(`equipment.pick_${equipInput}`) }}</span>
                        <button type="button" class="text-ink-soft underline" @click="equipping = null">{{ t('game.cancel') }}</button>
                    </p>
                    <div
                        class="flex flex-wrap items-center gap-2 pt-2 pr-2"
                        :class="hideWires ? 'select-none [-webkit-touch-callout:none]' : ''"
                        :style="{ zoom: wireZoom }"
                        data-testid="my-wires"
                        @pointerdown="peek"
                        @contextmenu="hideWires && $event.preventDefault()"
                    >
                        <template v-for="(wire, index) in myStand.wires" :key="index">
                            <WireTile
                                :wire="wire"
                                large
                                :selectable="mineSelectable(wire, index)"
                                :selected="mineSelected(index)"
                                :masked="masked"
                                @select="tapMine(index)"
                            />
                            <span v-if="labelAt(myStand, index)" class="chip -mx-1 bg-white" :title="t(`game.label.${labelAt(myStand, index).equal ? 'equal' : 'different'}`)">
                                {{ labelAt(myStand, index).equal ? '=' : '≠' }}
                            </span>
                        </template>
                    </div>
                    <div v-if="equipInput === 'swap' && equipPick !== null" class="animate-pop mt-4 flex flex-col gap-2" data-testid="equipment-confirm">
                        <p class="text-sm font-semibold">📻 {{ t('walkie.pick_teammate', { position: equipPick + 1 }) }}</p>
                        <div class="flex flex-wrap gap-2">
                            <button
                                v-for="stand in others"
                                :key="stand.id"
                                type="button"
                                class="btn btn-sm bg-sun"
                                :disabled="busy || !stand.wires.some((wire) => !wire.cut && wire.info === null)"
                                :data-player="stand.id"
                                @click="useEquipment(equipping, { index: equipPick, target: stand.id })"
                            >
                                <Avatar :seat="stand.seat" size="sm" /> {{ stand.name }}
                            </button>
                            <button type="button" class="btn btn-sm bg-white" @click="equipping = null">{{ t('game.cancel') }}</button>
                        </div>
                    </div>
                    <div v-else-if="equipping && equipPick !== null" class="animate-pop mt-4 grid grid-cols-[1fr_auto] gap-2" data-testid="equipment-confirm">
                        <button type="button" class="btn bg-sun" :disabled="busy" @click="useEquipment(equipping, { index: equipPick })">
                            {{ EQUIPMENT_ICON[equipping] }}
                            {{ t(equipInput === 'pair' ? 'equipment.confirm_pair' : 'equipment.confirm_wire', { card: t(`equipment.${equipping}.name`), position: equipPick + 1, next: equipPick + 2 }) }}
                        </button>
                        <button type="button" class="btn bg-white" @click="equipping = null">{{ t('game.cancel') }}</button>
                    </div>
                    <button
                        v-if="infoPick !== null"
                        type="button"
                        class="btn animate-pop mt-4 w-full bg-sun"
                        :disabled="busy"
                        data-testid="reveal"
                        @click="post('info-token', { index: infoPick })"
                    >
                        💡 {{ t('game.reveal', { value: masked ? `#${infoPick + 1}` : myStand.wires[infoPick].value }) }}
                    </button>
                </section>

                <!-- Nothing legal: the cut order blocks every move -->
                <section v-if="game.me.mustPass" class="card animate-pop mb-4 bg-lilac" data-testid="pass">
                    <p class="mb-3 font-semibold">🔒 {{ t('game.pass_hint') }}</p>
                    <button type="button" class="btn w-full bg-white" :disabled="busy" @click="post('pass')">⏭️ {{ t('game.pass') }}</button>
                </section>

                <!-- Reveal red -->
                <section v-if="game.me.canRevealRed" class="card animate-pop mb-4 bg-berry-soft" data-testid="reveal-red">
                    <p class="mb-3 font-semibold">🔴 {{ t('game.reveal_red_hint') }}</p>
                    <button type="button" class="btn w-full bg-berry text-white" :disabled="busy" @click="post('reveal-red')">
                        {{ t('game.reveal_red') }}
                    </button>
                </section>

                <!-- Solo Cut -->
                <section v-if="game.me.soloCutValues.length" class="card animate-pop mb-4 bg-mint-soft" data-testid="solo-cut">
                    <p class="heading">🌟 {{ t('game.solo_cut') }}</p>
                    <p class="mb-3 text-sm text-ink-soft">{{ t('game.solo_cut_hint') }}</p>
                    <div class="flex flex-wrap gap-2">
                        <button
                            v-for="value in game.me.soloCutValues"
                            :key="value"
                            type="button"
                            class="btn"
                            :class="value === 'yellow' ? 'bg-sun' : 'bg-mint'"
                            :disabled="busy"
                            :data-value="value"
                            @click="post('solo-cut', { value })"
                        >
                            ✂️ {{ t('game.solo_cut_button', { value: kindLabel(t, value) }) }}
                        </button>
                    </div>
                </section>

                <p v-if="detector" class="mb-3 rounded-2xl bg-lilac px-3 py-2 text-center font-semibold" data-testid="detector-hint">
                    {{ detector.card ? EQUIPMENT_ICON[detector.card] : '🔍' }}
                    {{
                        detector.stand === null
                            ? t(detector.size === 3 ? 'detector.pick_three' : 'detector.pick_two')
                            : t('detector.pick_more', { name: detectorStand?.name, count: detector.size - detector.indices.length })
                    }}
                    <button v-if="detector.card" type="button" class="ml-1 text-sm text-ink-soft underline" @click="detector = null">{{ t('game.cancel') }}</button>
                </p>
                <p v-else-if="equipInput === 'stand' || equipInput === 'xy'" class="mb-3 rounded-2xl bg-lilac px-3 py-2 text-center font-semibold" data-testid="equipment-hint">
                    {{ EQUIPMENT_ICON[equipping] }} {{ t(`equipment.pick_${equipInput}`) }}
                    <button type="button" class="ml-1 text-sm text-ink-soft underline" @click="equipping = null">{{ t('game.cancel') }}</button>
                </p>
                <p v-else-if="game.me.myTurn && !equipping && !game.me.mustPass" class="mb-3 text-center text-lg font-semibold text-ink">
                    👇 {{ t('game.pick_target') }}
                </p>

                <!-- Teammates' stands -->
                <ol class="mb-4 flex flex-col gap-3" data-testid="stands">
                    <li
                        v-for="stand in others"
                        :key="stand.id"
                        class="rounded-3xl border-[3px] border-line p-3 shadow-[0_5px_0_var(--color-line)]"
                        :class="game.turn === stand.id ? 'bg-sun-soft' : 'bg-white'"
                        data-testid="stand"
                    >
                        <div class="mb-2 flex items-center gap-2">
                            <Avatar :seat="stand.seat" size="sm" :online="online.includes(stand.id)" />
                            <span class="truncate font-bold">{{ stand.name }}</span>
                            <span v-if="stand.id === game.captain" class="chip bg-sun">⭐ {{ t('game.captain') }}</span>
                            <span
                                class="text-sm"
                                :class="stand.detectorUsed ? 'opacity-25 grayscale' : ''"
                                :title="stand.detectorUsed ? t('detector.used') : t('detector.name')"
                                aria-hidden="true"
                            >
                                🔍
                            </span>
                            <span class="chip ml-auto" :class="wiresLeft(stand) ? 'bg-cloud' : 'bg-mint'">
                                {{ wiresLeft(stand) ? t('game.wires_left', { count: wiresLeft(stand) }) : `✅ ${t('game.done')}` }}
                            </span>
                            <button
                                v-if="!finished"
                                type="button"
                                class="btn btn-sm shrink-0 bg-white px-2 py-0.5"
                                :disabled="busy || nudgeSent.includes(stand.id)"
                                :aria-label="t('nudge.button', { name: stand.name })"
                                :title="t('nudge.button', { name: stand.name })"
                                data-testid="nudge"
                                @click="nudge(stand)"
                            >
                                {{ nudgeSent.includes(stand.id) ? '✅' : '👋' }}
                            </button>
                        </div>
                        <div class="flex flex-wrap items-center gap-1.5 pt-2 pr-2" :style="{ zoom: wireZoom }">
                            <template v-for="(wire, index) in stand.wires" :key="index">
                                <WireTile
                                    :wire="wire"
                                    :selectable="teammateSelectable(stand, wire)"
                                    :selected="isPicked(stand, index)"
                                    @select="pickTarget(stand, index)"
                                />
                                <span v-if="labelAt(stand, index)" class="chip -mx-1 bg-white" :title="t(`game.label.${labelAt(stand, index).equal ? 'equal' : 'different'}`)">
                                    {{ labelAt(stand, index).equal ? '=' : '≠' }}
                                </span>
                            </template>
                        </div>
                    </li>
                </ol>

                <section class="card mb-4">
                    <CutBoard :board="game.cutBoard" />
                </section>

                <section v-if="game.log.length" class="card mb-4">
                    <p class="label mb-2">📜 {{ t('game.log_title') }}</p>
                    <GameLog :log="game.log" :names="names" />
                </section>
            </template>

            <!-- Dual Cut: a bottom sheet, so the value picker is in reach however far down the wire was -->
            <template v-if="target && targetStand">
                <div class="fixed inset-0 z-10 bg-line/20" aria-hidden="true" @click="target = null" />
                <section
                    class="card animate-sheet fixed inset-x-0 bottom-0 z-20 mx-auto max-w-md rounded-b-none bg-sky-soft pb-[max(1rem,env(safe-area-inset-bottom))] shadow-[0_-6px_0_var(--color-line)]"
                    role="dialog"
                    aria-modal="true"
                    data-testid="dual-cut"
                >
                    <p class="heading">✂️ {{ t('game.dual_cut') }}</p>
                    <p class="font-semibold">{{ t('game.dual_cut_prompt', { name: targetStand.name, position: target.index + 1 }) }}</p>
                    <p class="mb-3 text-sm text-ink-soft">{{ t('game.dual_cut_hint') }}</p>
                    <div class="grid grid-cols-4 gap-2">
                        <button
                            v-for="value in game.me.heldValues"
                            :key="value"
                            type="button"
                            class="btn py-2 text-2xl"
                            :class="[
                                value === 'yellow' ? 'col-span-2 bg-sun text-xl' : 'bg-sky text-white [text-shadow:0_2px_0_var(--color-line)]',
                                game.me.lockedValues.includes(value) ? 'opacity-40 grayscale' : '',
                            ]"
                            :disabled="busy || game.me.lockedValues.includes(value)"
                            :title="game.me.lockedValues.includes(value) ? t('game.locked_value') : undefined"
                            :data-value="value"
                            @click="dualCut(value)"
                        >
                            {{ value === 'yellow' ? `🟡 ${t('game.cut_yellow')}` : value }}<span v-if="game.me.lockedValues.includes(value)" class="text-sm">🔒</span>
                        </button>
                    </div>
                    <button type="button" class="mt-3 w-full py-1 font-semibold text-ink-soft" @click="target = null">
                        {{ t('game.cancel') }}
                    </button>
                </section>
            </template>

            <!-- Double Detector: two wires picked, now name a value -->
            <template v-if="detectorReady && detectorStand">
                <div class="fixed inset-0 z-10 bg-line/20" aria-hidden="true" @click="detector = null" />
                <section
                    class="card animate-sheet fixed inset-x-0 bottom-0 z-20 mx-auto max-w-md rounded-b-none bg-lilac pb-[max(1rem,env(safe-area-inset-bottom))] shadow-[0_-6px_0_var(--color-line)]"
                    role="dialog"
                    aria-modal="true"
                    data-testid="detector-sheet"
                >
                    <p class="heading">
                        {{ detector.card ? `${EQUIPMENT_ICON[detector.card]} ${t(`equipment.${detector.card}.name`)}` : `🔍 ${t('detector.name')}` }}
                    </p>
                    <p class="font-semibold">
                        {{ t('detector.prompt_many', { name: detectorStand.name, positions: detector.indices.map((i) => `#${i + 1}`).join(', ') }) }}
                    </p>
                    <p class="mb-3 text-sm text-ink-soft">{{ t('detector.hint', { name: detectorStand.name }) }}</p>
                    <div class="grid grid-cols-4 gap-2">
                        <button
                            v-for="value in game.me.heldValues"
                            :key="value"
                            type="button"
                            class="btn py-2 text-2xl"
                            :class="[
                                value === 'yellow' ? 'col-span-2 bg-sun text-xl' : 'bg-sky text-white [text-shadow:0_2px_0_var(--color-line)]',
                                game.me.lockedValues.includes(value) ? 'opacity-40 grayscale' : '',
                            ]"
                            :disabled="busy || game.me.lockedValues.includes(value)"
                            :data-value="value"
                            @click="useDetector(value)"
                        >
                            {{ value === 'yellow' ? `🟡 ${t('game.cut_yellow')}` : value }}<span v-if="game.me.lockedValues.includes(value)" class="text-sm">🔒</span>
                        </button>
                    </div>
                    <button type="button" class="mt-3 w-full py-1 font-semibold text-ink-soft" @click="detector = null">
                        {{ t('game.cancel') }}
                    </button>
                </section>
            </template>

            <!-- Radar: pick a number -->
            <template v-if="equipInput === 'value'">
                <div class="fixed inset-0 z-10 bg-line/20" aria-hidden="true" @click="equipping = null" />
                <section
                    class="card animate-sheet fixed inset-x-0 bottom-0 z-20 mx-auto max-w-md rounded-b-none bg-lilac pb-[max(1rem,env(safe-area-inset-bottom))] shadow-[0_-6px_0_var(--color-line)]"
                    role="dialog"
                    aria-modal="true"
                    data-testid="equipment-value"
                >
                    <p class="heading mb-3">{{ EQUIPMENT_ICON[equipping] }} {{ t(`equipment.${equipping}.name`) }} · {{ t('equipment.pick_value') }}</p>
                    <div class="grid grid-cols-6 gap-2">
                        <button
                            v-for="value in 12"
                            :key="value"
                            type="button"
                            class="btn bg-white px-0 py-2 text-xl"
                            :disabled="busy"
                            :data-value="value"
                            @click="useEquipment(equipping, { value })"
                        >
                            {{ value }}
                        </button>
                    </div>
                    <button type="button" class="mt-3 w-full py-1 font-semibold text-ink-soft" @click="equipping = null">
                        {{ t('game.cancel') }}
                    </button>
                </section>
            </template>

            <!-- Super Detector (a teammate's whole stand) and X or Y Ray (one wire, two kinds): name what you hold -->
            <template v-if="equipTarget && equipTargetStand && (equipInput === 'stand' || equipInput === 'xy')">
                <div class="fixed inset-0 z-10 bg-line/20" aria-hidden="true" @click="equipTarget = null" />
                <section
                    class="card animate-sheet fixed inset-x-0 bottom-0 z-20 mx-auto max-w-md rounded-b-none bg-lilac pb-[max(1rem,env(safe-area-inset-bottom))] shadow-[0_-6px_0_var(--color-line)]"
                    role="dialog"
                    aria-modal="true"
                    data-testid="equipment-kinds"
                >
                    <p class="heading">{{ EQUIPMENT_ICON[equipping] }} {{ t(`equipment.${equipping}.name`) }}</p>
                    <p class="mb-3 font-semibold">
                        {{
                            equipInput === 'stand'
                                ? t('equipment.super_prompt', { name: equipTargetStand.name })
                                : t('equipment.xy_prompt', { name: equipTargetStand.name, position: equipTarget.index + 1 })
                        }}
                    </p>
                    <div class="grid grid-cols-4 gap-2">
                        <button
                            v-for="value in game.me.heldValues"
                            :key="value"
                            type="button"
                            class="btn py-2 text-2xl"
                            :class="[
                                value === 'yellow' ? 'col-span-2 bg-sun text-xl' : 'bg-sky text-white [text-shadow:0_2px_0_var(--color-line)]',
                                equipKinds.includes(value) ? 'ring-4 ring-tangerine' : '',
                                game.me.lockedValues.includes(value) ? 'opacity-40 grayscale' : '',
                            ]"
                            :disabled="busy || game.me.lockedValues.includes(value)"
                            :aria-pressed="equipInput === 'xy' ? equipKinds.includes(value) : undefined"
                            :data-value="value"
                            @click="
                                equipInput === 'stand'
                                    ? useEquipment(equipping, { target: equipTarget.stand, value })
                                    : toggleXyKind(value)
                            "
                        >
                            {{ value === 'yellow' ? `🟡 ${t('game.cut_yellow')}` : value }}
                        </button>
                    </div>
                    <button
                        v-if="equipInput === 'xy'"
                        type="button"
                        class="btn mt-3 w-full bg-sun"
                        :disabled="busy || equipKinds.length !== 2"
                        data-testid="xy-confirm"
                        @click="useEquipment(equipping, { target: equipTarget.stand, index: equipTarget.index, values: equipKinds })"
                    >
                        ✂️ {{ equipKinds.length === 2 ? t('equipment.xy_confirm', { first: kindLabel(t, equipKinds[0]), second: kindLabel(t, equipKinds[1]) }) : t('equipment.xy_pick_two') }}
                    </button>
                    <button type="button" class="mt-3 w-full py-1 font-semibold text-ink-soft" @click="equipping = null">
                        {{ t('game.cancel') }}
                    </button>
                </section>
            </template>

            <!-- Emergency Batteries (1–2 used Double Detectors) and Coffee Mug (who goes next): pick players -->
            <template v-if="equipInput === 'players' || equipInput === 'player'">
                <div class="fixed inset-0 z-10 bg-line/20" aria-hidden="true" @click="equipping = null" />
                <section
                    class="card animate-sheet fixed inset-x-0 bottom-0 z-20 mx-auto max-w-md rounded-b-none bg-lilac pb-[max(1rem,env(safe-area-inset-bottom))] shadow-[0_-6px_0_var(--color-line)]"
                    role="dialog"
                    aria-modal="true"
                    data-testid="equipment-players"
                >
                    <p class="heading">{{ EQUIPMENT_ICON[equipping] }} {{ t(`equipment.${equipping}.name`) }}</p>
                    <p class="mb-3 font-semibold">{{ t(`equipment.pick_${equipInput}`) }}</p>
                    <p v-if="equipInput === 'players' && !usedDetectors.length" class="mb-3 text-sm text-ink-soft">{{ t('equipment.batteries_none') }}</p>
                    <div class="flex flex-wrap gap-2">
                        <button
                            v-for="stand in equipInput === 'players' ? usedDetectors : nextPlayers"
                            :key="stand.id"
                            type="button"
                            class="btn btn-sm"
                            :class="equipPlayers.includes(stand.id) ? 'bg-sun ring-4 ring-tangerine' : 'bg-white'"
                            :disabled="busy"
                            :aria-pressed="equipInput === 'players' ? equipPlayers.includes(stand.id) : undefined"
                            :data-player="stand.id"
                            @click="equipInput === 'players' ? togglePlayer(stand.id) : useEquipment(equipping, { player: stand.id })"
                        >
                            <Avatar :seat="stand.seat" size="sm" /> {{ stand.name }}
                        </button>
                    </div>
                    <button
                        v-if="equipInput === 'players'"
                        type="button"
                        class="btn mt-3 w-full bg-sun"
                        :disabled="busy || !equipPlayers.length"
                        data-testid="players-confirm"
                        @click="useEquipment(equipping, { players: equipPlayers })"
                    >
                        🔋 {{ t('equipment.batteries_confirm', { count: equipPlayers.length }) }}
                    </button>
                    <button type="button" class="mt-3 w-full py-1 font-semibold text-ink-soft" @click="equipping = null">
                        {{ t('game.cancel') }}
                    </button>
                </section>
            </template>

            <div v-if="me.isHost && !finished" class="mt-auto flex flex-col">
                <button
                    v-if="game.undoSteps"
                    type="button"
                    class="py-3 font-semibold"
                    :class="confirmUndo ? 'text-berry' : 'text-ink-soft'"
                    :disabled="busy"
                    data-testid="undo"
                    @click="undo"
                >
                    {{ confirmUndo ? `⚠️ ${t('game.undo_confirm')}` : `↩️ ${t('game.undo')}` }}
                </button>
                <button
                    type="button"
                    class="py-3 font-semibold"
                    :class="confirmAbort ? 'text-berry' : 'text-ink-soft'"
                    @click="abort"
                >
                    {{ confirmAbort ? `⚠️ ${t('game.abort_confirm')}` : `🛑 ${t('game.abort')}` }}
                </button>
            </div>
        </RoomPresence>
    </AppLayout>
</template>
