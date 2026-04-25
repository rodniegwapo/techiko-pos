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

const myConversation = computed(() => page.props.myConversation ?? { id: null, messages: [] });
const activeConversationId = computed(() => myConversation.value?.id ?? null);

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
    }
});

const seenIds = new Set();

const echoOpenChannel = computed(() =>
    open.value && activeConversationId.value
        ? activeConversationId.value
        : null,
);
useConversationChannel(
    echoOpenChannel,
    {
        onEvent: (payload) => {
            const m = payload?.message;
            if (!m) return;
            if (seenIds.has(m.id)) return;
            seenIds.add(m.id);
            if (!localMessages.value.some((x) => x.id === m.id)) {
                const uid = page.props.auth?.user?.data?.id;
                localMessages.value = [
                    ...localMessages.value,
                    {
                        id: m.id,
                        body: m.body,
                        author_user_id: m.author_user_id,
                        is_from_customer:
                            Number(m.author_user_id) === Number(uid),
                        created_at: m.created_at,
                        author: m.author,
                    },
                ];
            }
        },
        onPoll: (data) => {
            if (data?.messages?.length) {
                localMessages.value = data.messages;
                data.messages.forEach((x) => seenIds.add(x.id));
            }
        },
    },
);

function formatWhen(iso) {
    if (!iso) return "";
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
</script>

<template>
    <div>
        <a-drawer
            v-model:visible="open"
            title="Chat with the team"
            placement="right"
            :width="400"
        >
            <p class="mb-4 text-sm text-gray-600">
                Messages are delivered in real time when online. We may also
                reply during business hours.
            </p>
            <a-textarea
                v-model:value="body"
                :rows="3"
                placeholder="Type a message…"
                :maxlength="5000"
                show-count
                class="mb-3"
            />
            <a-button
                type="primary"
                block
                :loading="submitting"
                class="mb-4"
                @click="submit"
            >
                Send
            </a-button>
            <h3 class="mb-2 text-sm font-semibold text-gray-800">
                Conversation
            </h3>
            <div
                v-if="!localMessages.length"
                class="text-sm text-gray-500"
            >
                No messages yet. Say hello to start.
            </div>
            <ul
                v-else
                class="max-h-[45vh] space-y-2 overflow-y-auto pr-1 text-sm"
            >
                <li
                    v-for="m in localMessages"
                    :key="m.id"
                    :class="[
                        'flex',
                        m.is_from_customer ? 'justify-end' : 'justify-start',
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
                        <p class="mt-1 text-right text-xs text-gray-500">
                            {{ formatWhen(m.created_at) }}
                        </p>
                    </div>
                </li>
            </ul>
        </a-drawer>

        <a-button
            type="primary"
            shape="circle"
            size="large"
            class="fixed bottom-24 right-6 z-[100] !flex h-12 w-12 items-center justify-center shadow-lg"
            aria-label="Open chat with team"
            @click="open = true"
        >
            <IconMessageCircle :size="22" />
        </a-button>
    </div>
</template>
