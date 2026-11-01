import { router } from '@inertiajs/vue3';
import { onBeforeUnmount, ref } from 'vue';

/**
 * POSTs a player's actions and keeps the screen in sync with broadcasts, so that the newest server
 * state always wins. Inertia v3 reloads are async and run in parallel, so without this, an older
 * response can land after a newer one and put the phone back on an earlier turn.
 *
 * - Reloads run one at a time. A broadcast during a reload queues one follow-up reload; each one
 *   starts after the previous answered, so responses can't overtake each other.
 * - While one of our own actions is in flight, `busy` is true (disable inputs with it) and further
 *   actions are ignored, so a double tap can't send a move twice. A reload still in flight is
 *   cancelled first (its answer would be older than the action's), and broadcasts that arrive
 *   meanwhile are deferred until the action finishes.
 * - If a request never finishes (flaky Wi-Fi), a watchdog clears `busy` and resyncs, so a phone
 *   can't get stuck on an old screen.
 */
export function useServerActions(baseUrl, reloadProps, { watchdogMs = 10000 } = {}) {
    const busy = ref(false);
    let reloading = false;
    let resyncPending = false;
    let cancelReload = null;
    let watchdog = null;

    function settle() {
        clearTimeout(watchdog);
        busy.value = false;
        flushPending();
    }

    function flushPending() {
        if (resyncPending && !busy.value && !reloading) {
            resyncPending = false;
            resync();
        }
    }

    function post(action, data = {}) {
        if (busy.value) return;

        cancelReload?.();

        router.post(`${baseUrl()}/${action}`, data, {
            preserveScroll: true,
            preserveState: true,
            onStart: () => {
                busy.value = true;
                watchdog = setTimeout(() => {
                    resyncPending = true;
                    settle();
                }, watchdogMs);
            },
            onFinish: settle,
        });
    }

    function resync() {
        if (busy.value || reloading) {
            resyncPending = true;
            return;
        }

        reloading = true;
        router.reload({
            only: reloadProps,
            onCancelToken: (token) =>
                (cancelReload = () => {
                    // Reset here too: don't rely on onFinish firing for a cancelled visit
                    token.cancel();
                    reloading = false;
                    cancelReload = null;
                }),
            onFinish: () => {
                reloading = false;
                cancelReload = null;
                flushPending();
            },
        });
    }

    onBeforeUnmount(() => {
        clearTimeout(watchdog);
        cancelReload?.();
    });

    return { busy, post, resync };
}
