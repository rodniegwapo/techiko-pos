import { onUnmounted, watch, unref, ref } from "vue";
import axios from "axios";

const pusherKey = import.meta.env.VITE_PUSHER_APP_KEY;

/**
 * Subscribe to new messages: Laravel Echo when Pusher is configured, else HTTP polling.
 */
export function useConversationChannel(conversationIdRef, { onEvent, onPoll } = {}) {
    const pollTimer = ref(null);
    const activeEchoId = ref(null);

    const clearPoll = () => {
        if (pollTimer.value) {
            clearInterval(pollTimer.value);
            pollTimer.value = null;
        }
    };

    const leaveEcho = (cid) => {
        if (!cid || !window.Echo) {
            return;
        }
        try {
            window.Echo.leave(`private-conversations.${cid}`);
        } catch {
            // ignore
        }
    };

    const runPoll = (cid) => {
        if (!cid || !onPoll) {
            return;
        }
        axios
            .get(route("conversations.messages", cid), { params: { _t: Date.now() } })
            .then((res) => onPoll(res.data))
            .catch(() => {
                // ignore
            });
    };

    watch(
        () => unref(conversationIdRef),
        (newId, oldId) => {
            clearPoll();
            if (oldId) {
                leaveEcho(oldId);
            }
            activeEchoId.value = newId;
            if (!newId) {
                return;
            }
            if (window.Echo && pusherKey) {
                try {
                    window.Echo.private(`conversations.${newId}`).listen(
                        ".message.created",
                        (payload) => onEvent?.(payload),
                    );
                } catch {
                    // fall back to polling only
                }
            }
            runPoll(newId);
            pollTimer.value = setInterval(() => runPoll(newId), 5000);
        },
        { immediate: true },
    );

    onUnmounted(() => {
        clearPoll();
        leaveEcho(unref(conversationIdRef));
    });
}
