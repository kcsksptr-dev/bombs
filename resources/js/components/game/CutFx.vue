<script setup>
import { onBeforeUnmount, ref, watch } from 'vue';
import { useT } from '../../i18n';

/**
 * Celebrates (or groans at) each move as it lands on this phone: a snip for a good cut, a shake
 * and red flash for a miss, a big boom, confetti for a win. Purely decorative: it never blocks
 * taps, and the reduced-motion setting in app.css turns the motion off.
 */
const props = defineProps({
    log: { type: Array, required: true },
    logLength: { type: Number, required: true },
});

const t = useT();
const fx = ref(null); // { kind, key, word }
let timer = null;
let lastWord = 0;

function kindOf(entries) {
    const types = entries.map((entry) => entry.type);
    if (types.includes('won')) return 'win';
    if (types.includes('lost')) return 'boom';

    const cut = entries.findLast((entry) => ['dual_cut', 'solo_cut', 'double_detector'].includes(entry.type));
    if (!cut) return null;
    if (cut.type === 'solo_cut' || cut.success) return 'snip';

    return cut.stabilized ? 'saved' : 'miss';
}

const DURATION = { snip: 1300, saved: 1300, miss: 1300, boom: 2200, win: 3200 };

// Each kind has this many words in the dictionaries (fx.snip.1 ...)
const WORDS = 6;

// A random word for this move, never the same number twice in a row
function pickWord() {
    let word;
    do {
        word = Math.floor(Math.random() * WORDS) + 1;
    } while (word === lastWord);
    lastWord = word;

    return word;
}

watch(
    () => props.logLength,
    (now, before) => {
        // Only react to moves that arrive while we're watching, not to the history on page load
        if (before === undefined || now <= before) return;

        const kind = kindOf(props.log.slice(-(now - before)));
        if (!kind) return;

        clearTimeout(timer);
        fx.value = { kind, key: now, word: pickWord() };
        if (kind === 'miss' || kind === 'boom') {
            document.body.classList.remove('animate-shake');
            void document.body.offsetWidth; // restart the animation
            document.body.classList.add('animate-shake');
        }
        timer = setTimeout(() => {
            fx.value = null;
            document.body.classList.remove('animate-shake');
        }, DURATION[kind]);
    },
);

onBeforeUnmount(() => {
    clearTimeout(timer);
    document.body.classList.remove('animate-shake');
});

const sparks = Array.from({ length: 10 }, (_, i) => {
    const angle = (i / 10) * Math.PI * 2;
    return { dx: `${Math.cos(angle) * 110}px`, dy: `${Math.sin(angle) * 110}px`, emoji: i % 2 ? '✨' : '⭐' };
});
const confetti = Array.from({ length: 28 }, (_, i) => ({
    left: `${(i * 37) % 100}%`,
    delay: `${(i % 7) * 0.12}s`,
    spin: `${360 + ((i * 53) % 360)}deg`,
    color: ['bg-sun', 'bg-berry', 'bg-sky', 'bg-mint', 'bg-grape', 'bg-tangerine'][i % 6],
}));

const BUBBLE = {
    snip: { emoji: '✂️', tone: 'bg-mint' },
    saved: { emoji: '🛡️', tone: 'bg-sky' },
    miss: { emoji: '😬', tone: 'bg-berry text-white' },
    boom: { emoji: '💥', tone: 'bg-tangerine' },
    win: { emoji: '🏆', tone: 'bg-sun' },
};
</script>

<template>
    <div v-if="fx" :key="fx.key" class="pointer-events-none fixed inset-0 z-40 overflow-hidden" aria-hidden="true" data-testid="cut-fx" :data-fx="fx.kind">
        <div v-if="fx.kind === 'miss'" class="animate-flash absolute inset-0 bg-berry/35" />
        <div v-if="fx.kind === 'boom'" class="animate-flash absolute inset-0 bg-tangerine/60" />

        <template v-if="fx.kind === 'win'">
            <span
                v-for="(piece, i) in confetti"
                :key="i"
                class="absolute top-0 h-4 w-2.5 rounded-sm border border-ink"
                :class="piece.color"
                :style="{ left: piece.left, '--spin': piece.spin, animation: `confetti 2.6s ease-in ${piece.delay} forwards` }"
            />
        </template>

        <div class="absolute inset-0 flex items-center justify-center">
            <div class="relative">
                <template v-if="fx.kind === 'snip'">
                    <span
                        v-for="(spark, i) in sparks"
                        :key="i"
                        class="absolute top-1/2 left-1/2 -mt-3 -ml-3 text-2xl"
                        :style="{ '--dx': spark.dx, '--dy': spark.dy, animation: 'spark 0.9s ease-out forwards' }"
                    >
                        {{ spark.emoji }}
                    </span>
                </template>
                <div
                    class="animate-burst flex flex-col items-center rounded-[2rem] border-4 border-ink px-8 py-4 shadow-[0_8px_0_var(--color-ink)]"
                    :class="BUBBLE[fx.kind].tone"
                >
                    <span class="text-7xl" :class="fx.kind === 'snip' ? 'animate-snip inline-block' : ''">{{ BUBBLE[fx.kind].emoji }}</span>
                    <span class="text-center text-3xl font-bold">{{ t(`fx.${fx.kind}.${fx.word}`) }}</span>
                </div>
            </div>
        </div>
    </div>
</template>
