<script setup>
import { ref, computed, watch } from "vue";
import { router, usePage } from "@inertiajs/vue3";
import { IconMessageCircle } from "@tabler/icons-vue";
import { message as antMessage } from "ant-design-vue";
import { useConversationChannel } from "@/Composables/useConversationChannel";

const page = usePage();
const open = ref(false);
const body = ref("");
const submitting = ref(false);
const localMessages = ref([]);
const floatReplyUnread = ref(0);

const myConversation = computed(
    () => page.props.myConversation ?? { id: null, messages: [] },
);
const activeConversationId = computed(() => myConversation.value?.id ?? null);

const seenIds = new Set();

watch(
    () => myConversation.value?.messages,
    (msgs) => {
        if (!msgs?.length) {
            return;
        }
        for (const m of msgs) {
            seenIds.add(m.id);
        }
    },
    { immediate: true, deep: true },
);

function syncFromProps() {
    localMessages.value = [...(myConversation.value?.messages || [])];
}

watch(
    () => myConversation.value,
    () => {
        if (open.value) {
            syncFromProps();
        }
    },
    { deep: true },
);

watch(open, (o) => {
    if (o) {
        syncFromProps();
        floatReplyUnread.value = 0;
    }
});

function maybeNotifyStaffReply(m) {
    if (typeof Notification === "undefined") {
        return;
    }
    if (document.visibilityState === "visible") {
        return;
    }
    if (Notification.permission !== "granted") {
        return;
    }
    try {
        new Notification("New reply from the team", {
            body: (m.body || "").trim().slice(0, 120),
            tag: `conv-reply-${m.id}`,
        });
    } catch {
        // ignore
    }
}

const echoChannelId = computed(() => activeConversationId.value);

useConversationChannel(echoChannelId, {
    onEvent: (payload) => {
        const m = payload?.message;
        if (!m) {
            return;
        }
        if (seenIds.has(m.id)) {
            return;
        }
        seenIds.add(m.id);
        const uid = page.props.auth?.user?.data?.id;
        const isCustomer = Number(m.author_user_id) === Number(uid);
        if (!open.value && !isCustomer) {
            floatReplyUnread.value++;
            maybeNotifyStaffReply(m);
        }
        if (open.value && !localMessages.value.some((x) => x.id === m.id)) {
            localMessages.value = [
                ...localMessages.value,
                {
                    id: m.id,
                    body: m.body,
                    author_user_id: m.author_user_id,
                    is_from_customer: isCustomer,
                    created_at: m.created_at,
                    author: m.author,
                },
            ];
        }
    },
    onPoll: (data) => {
        if (!data?.messages?.length) {
            return;
        }
        const uid = page.props.auth?.user?.data?.id;
        if (open.value) {
            localMessages.value = data.messages;
            data.messages.forEach((x) => seenIds.add(x.id));
            return;
        }
        for (const x of data.messages) {
            const isCustomer = Number(x.author_user_id) === Number(uid);
            if (!isCustomer && !seenIds.has(x.id)) {
                seenIds.add(x.id);
                floatReplyUnread.value++;
                maybeNotifyStaffReply(x);
            }
        }
    },
});

function formatWhen(iso) {
    if (!iso) {
        return "";
    }
    try {
        return new Date(iso).toLocaleString();
    } catch {
        return iso;
    }
}

function submit() {
    const value = body.value.trim();
    if (!value) {
        antMessage.warning("Please enter a message.");
        return;
    }
    submitting.value = true;
    router.post(
        route("inquiries.store"),
        { body: value },
        {
            preserveScroll: true,
            onSuccess: () => {
                body.value = "";
                antMessage.success("Message sent.");
                router.reload({
                    only: ["myConversation", "inquiryUnreadCount"],
                    preserveScroll: true,
                });
            },
            onError: (errors) => {
                const first = errors?.body?.[0] || "Could not send message.";
                antMessage.error(first);
            },
            onFinish: () => {
                submitting.value = false;
            },
        },
    );
}

function onComposerKeydown(e) {
    if (e.key !== "Enter") {
        return;
    }
    if (e.shiftKey) {
        return;
    }
    e.preventDefault();
    submit();
}
</script>

<template>
    <div>
        <a-drawer
            v-model:visible="open"
            title="Chat with the team"
            placement="right"
            :width="400"
            :content-wrapper-style="{ height: '100dvh', maxHeight: '100dvh' }"
            :drawer-style="{
                height: '100%',
                display: 'flex',
                flexDirection: 'column',
            }"
            :header-style="{ flexShrink: 0 }"
            :body-style="{
                padding: 0,
                display: 'flex',
                flexDirection: 'column',
                flex: 1,
                minHeight: 0,
                height: '100%',
                overflow: 'hidden',
            }"
            :style="{ overflow: 'hidden' }"
        >
            <div class="flex h-full min-h-0 min-w-0 flex-1 flex-col">
                <p class="shrink-0 px-4 pb-2 pt-1 text-sm text-gray-600">
                    Messages are delivered in real time when online. We may also
                    reply during business hours. Enable browser notifications in
                    your system settings to get alerts when the tab is in the
                    background.
                </p>
                <h3
                    class="shrink-0 px-4 pb-2 text-sm font-semibold text-gray-800"
                >
                    Conversation
                </h3>
                <div class="min-h-0 flex-1 overflow-y-auto px-4 pr-3">
                    <div
                        v-if="!localMessages.length"
                        class="text-sm text-gray-500"
                    >
                        No messages yet. Say hello to start.
                    </div>
                    <ul v-else class="space-y-2 text-sm">
                        <li
                            v-for="m in localMessages"
                            :key="m.id"
                            :class="[
                                'flex',
                                m.is_from_customer
                                    ? 'justify-end'
                                    : 'justify-start',
                            ]"
                        >
                            <div
                                :class="[
                                    'max-w-[90%] rounded-lg px-3 py-2',
                                    m.is_from_customer
                                        ? 'bg-green-100 text-gray-800'
                                        : 'bg-gray-200 text-gray-800',
                                ]"
                            >
                                <p class="whitespace-pre-wrap break-words">
                                    {{ m.body }}
                                </p>
                                <p
                                    v-if="!m.is_from_customer && m.author"
                                    class="mt-1 text-xs text-gray-500"
                                >
                                    {{ m.author.name || m.author.email }}
                                </p>
                                <p
                                    class="mt-1 text-right text-xs text-gray-500"
                                >
                                    {{ formatWhen(m.created_at) }}
                                </p>
                            </div>
                        </li>
                    </ul>
                </div>
                <div
                    class="shrink-0 border-t border-gray-200 bg-white px-4 pt-3 pb-[max(0.75rem,env(safe-area-inset-bottom,0px))]"
                >
                    <a-textarea
                        v-model:value="body"
                        :rows="3"
                        placeholder="Type a message… (Enter to send, Shift+Enter for new line)"
                        :maxlength="5000"
                        show-count
                        class="mb-2"
                        @keydown="onComposerKeydown"
                    />
                    <a-button
                        type="primary"
                        block
                        :loading="submitting"
                        @click="submit"
                    >
                        Send
                    </a-button>
                </div>
            </div>
        </a-drawer>

        <!-- Floating launcher: tablet/desktop only — avoids overlapping mobile POS/checkout UI -->
        <div
            v-show="!open"
            class="fixed bottom-2 right-6 z-[100] max-md:hidden flex h-12 w-12 items-center justify-center"
        >
            <a-badge
                v-if="floatReplyUnread > 0"
                :count="floatReplyUnread"
                :number-style="{
                    minWidth: '1.1rem',
                    lineHeight: '1.1rem',
                    fontSize: '10px',
                }"
            >
                <a-button
                    type="primary"
                    shape="circle"
                    size="large"
                    class="!flex h-12 w-12 items-center justify-center shadow-lg"
                    aria-label="Open chat with team"
                    @click="open = true"
                >
                    <IconMessageCircle :size="22" />
                </a-button>
            </a-badge>
            <a-button
                v-else
                type="primary"
                shape="circle"
                size="large"
                class="!flex h-12 w-12 items-center justify-center shadow-lg"
                aria-label="Open chat with team"
                @click="open = true"
            >
                <IconMessageCircle :size="22" />
            </a-button>
        </div>
    </div>
</template>
