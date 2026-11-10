<script setup>
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import ErrorBanner from '../components/ErrorBanner.vue';
import AppLayout from '../layouts/AppLayout.vue';
import { useT } from '../i18n';

const props = defineProps({
    activeRoom: Object,
    capacity: Object,
});

const page = usePage();
const t = useT();
const user = computed(() => page.props.auth.user);

const nameForm = useForm({ name: user.value?.name ?? '' });
const renaming = ref(false);
const createForm = useForm({ capacity: props.capacity.default });
const joinForm = useForm({ code: '' });

const sizes = computed(() => {
    const list = [];
    for (let n = props.capacity.min; n <= props.capacity.max; n++) list.push(n);
    return list;
});

function saveName() {
    nameForm.post('/player', {
        preserveScroll: true,
        onSuccess: () => (renaming.value = false),
    });
}

function startRename() {
    nameForm.name = user.value.name;
    nameForm.clearErrors();
    renaming.value = true;
}
</script>

<template>
    <Head :title="t('lobby.title')" />

    <AppLayout>
        <p class="-mt-2 mb-5 text-center text-lg font-medium text-ink-soft">{{ t('app.tagline') }} 🎲</p>

        <!-- First visit: the device gets a guest player -->
        <form v-if="!user || renaming" class="card animate-pop flex flex-col gap-3" @submit.prevent="saveName">
            <h2 class="heading">👋 {{ t('home.name_title') }}</h2>
            <label class="sr-only" for="name">{{ t('home.name_label') }}</label>
            <input
                id="name"
                v-model="nameForm.name"
                maxlength="40"
                autocomplete="nickname"
                autofocus
                class="input"
                :placeholder="t('home.name_placeholder')"
            />
            <ErrorBanner :message="nameForm.errors.name" />
            <div class="flex gap-2">
                <button type="submit" class="btn flex-1 bg-sun" :disabled="nameForm.processing || !nameForm.name.trim()">
                    {{ user ? t('home.save') : t('home.continue') }} →
                </button>
                <button v-if="user" type="button" class="btn bg-white" @click="renaming = false">
                    {{ t('home.cancel') }}
                </button>
            </div>
        </form>

        <template v-else>
            <div class="mb-4 flex items-center justify-between">
                <p class="text-2xl font-bold">{{ t('home.greeting', { name: user.name }) }} 🎉</p>
                <button type="button" class="btn btn-sm bg-white" @click="startRename">✏️ {{ t('home.rename') }}</button>
            </div>

            <Link
                v-if="activeRoom"
                :href="`/rooms/${activeRoom.code}`"
                class="btn mb-5 justify-between bg-lilac text-base"
                data-testid="rejoin"
            >
                <span>
                    {{ t(activeRoom.status === 'playing' ? 'home.active_game' : 'home.active_room', { code: activeRoom.code }) }}
                </span>
                <span>{{ t('home.rejoin') }} →</span>
            </Link>

            <form class="card mb-5 flex flex-col gap-4" data-testid="create-room" @submit.prevent="createForm.post('/rooms')">
                <h2 class="heading">🎈 {{ t('home.create_title') }}</h2>

                <div class="flex flex-col gap-2">
                    <span class="label">{{ t('home.table_size') }}</span>
                    <div class="grid grid-cols-7 gap-1.5">
                        <button
                            v-for="n in sizes"
                            :key="n"
                            type="button"
                            class="btn btn-sm px-0 py-2 text-lg"
                            :class="createForm.capacity === n ? 'bg-sky text-white' : 'bg-white'"
                            :aria-pressed="createForm.capacity === n"
                            :data-capacity="n"
                            @click="createForm.capacity = n"
                        >
                            {{ n }}
                        </button>
                    </div>
                    <p v-if="createForm.capacity >= 6" class="text-sm text-ink-soft">✨ {{ t('home.big_table_note') }}</p>
                </div>

                <ErrorBanner :message="createForm.errors.capacity || createForm.errors.lobby" />
                <button type="submit" class="btn bg-sun text-xl" :disabled="createForm.processing" data-testid="create">
                    🚀 {{ t('home.create') }}
                </button>
            </form>

            <form class="card flex flex-col gap-3" @submit.prevent="joinForm.post('/rooms/find')">
                <h2 class="heading">🔑 {{ t('home.join_title') }}</h2>
                <label class="sr-only" for="code">{{ t('home.code_label') }}</label>
                <div class="flex gap-2">
                    <input
                        id="code"
                        v-model="joinForm.code"
                        maxlength="8"
                        autocapitalize="characters"
                        autocomplete="off"
                        spellcheck="false"
                        class="input min-w-0 flex-1 text-center text-2xl font-bold tracking-[0.3em] uppercase"
                        placeholder="ABCD"
                        :aria-label="t('home.code_label')"
                    />
                    <button type="submit" class="btn bg-mint" :disabled="joinForm.processing || !joinForm.code.trim()" data-testid="join">
                        {{ t('home.join') }}
                    </button>
                </div>
                <ErrorBanner :message="joinForm.errors.code" />
            </form>
        </template>
    </AppLayout>
</template>
