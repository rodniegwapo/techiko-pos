<script setup>
import { ref, computed, watch } from "vue";
import { Head, router } from "@inertiajs/vue3";
import { message as antMessage } from "ant-design-vue";
import { useTable } from "@/Composables/useTable";
import { useGlobalVariables } from "@/Composables/useGlobalVariable";
import { useConversationChannel } from "@/Composables/useConversationChannel";

import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import ContentHeader from "@/Components/ContentHeader.vue";
import ContentLayout from "@/Components/ContentLayout.vue";
import RefreshButton from "@/Components/buttons/Refresh.vue";

const { spinning } = useGlobalVariables();
const { pagination, handleTableChange, reload } = useTable("conversations", {}, { preserveQueryKeys: ["c"] });

const props = defineProps({
    conversations: { type: Object, required: true },
    thread: { type: Object, default: null },
    openConversationId: { type: [Number, null], default: null },
});

const localMessages = ref([]);

watch(
    () => props.thread,
    (t) => {
        if (t?.messages) {
            localMessages.value = [...t.messages];
        } else {
            localMessages.value = [];
        }
    },
    { immediate: true, deep: true },
);

const staffBody = ref("");
const staffLoading = ref(false);
const seenIds = new Set();

const echoChannelId = computed(() => props.openConversationId);
useConversationChannel(
    echoChannelId,
    {
        onEvent: (payload) => {
            const m = payload?.message;
            if (!m) return;
            if (props.openConversationId && m.conversation_id !== props.openConversationId) {
                return;
            }
            if (seenIds.has(m.id)) return;
            seenIds.add(m.id);
            const customerId = props.thread?.user?.id;
            const isCustomer = customerId && Number(m.author_user_id) === Number(customerId);
            if (!localMessages.value.some((x) => x.id === m.id)) {
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
            if (data?.messages?.length) {
                localMessages.value = data.messages;
                data.messages.forEach((x) => seenIds.add(x.id));
            }
        },
    },
);

const columns = [
    { title: "User", key: "user", ellipsis: true },
    { title: "Email", key: "email", dataIndex: ["user", "email"], ellipsis: true },
    { title: "Last message", key: "preview", width: 260, ellipsis: true },
    { title: "Activity", key: "last_message_at" },
    { title: "Unread", key: "unread" },
    { title: "", key: "actions", width: 120 },
];

const dataSource = computed(
    () => (props.conversations && props.conversations.data) || [],
);

function formatWhen(iso) {
    if (!iso) return "—";
    try {
        return new Date(iso).toLocaleString();
    } catch {
        return iso;
    }
}

function previewText(record) {
    const t = (record?.last_message?.body || "").trim();
    if (!t) return "—";
    return t.length > 100 ? `${t.slice(0, 100)}…` : t;
}

function openThread(row) {
    seenIds.clear();
    router.get(route("messages.index", { c: row.id }), {
        onSuccess: () => {
            row.unread_for_staff = 0;
        },
    });
}

function closeThread() {
    seenIds.clear();
    router.get(route("messages.index"));
}

function onStaffBodyKeydown(e) {
    if (e.key !== "Enter") {
        return;
    }
    if (e.shiftKey) {
        return;
    }
    e.preventDefault();
    sendStaff();
}

function sendStaff() {
    const t = staffBody.value.trim();
    if (!t || !props.openConversationId) {
        return;
    }
    staffLoading.value = true;
    router.post(
        route("messages.staff", props.openConversationId),
        { body: t },
        {
            preserveScroll: true,
            onSuccess: () => {
                staffBody.value = "";
                antMessage.success("Sent.");
                router.reload({ only: ["thread", "conversations", "inquiryUnreadCount"] });
            },
            onError: () => antMessage.error("Could not send"),
            onFinish: () => {
                staffLoading.value = false;
            },
        },
    );
}
</script>

<template>
    <AuthenticatedLayout>
        <Head title="Messages" />
        <ContentHeader class="mb-8" title="Inquiries" />

        <div class="flex flex-col gap-4 lg:flex-row">
            <div class="min-w-0 flex-1">
                <ContentLayout title="Conversations">
                    <template #filters>
                        <RefreshButton :loading="spinning" @click="reload" />
                    </template>

                    <template #table>
                        <a-table
                            :columns="columns"
                            :data-source="dataSource"
                            :loading="spinning"
                            :pagination="pagination"
                            :row-key="(r) => r.id"
                            :row-class-name="
                                (r) =>
                                    r.id === openConversationId
                                        ? 'cursor-pointer !bg-green-50'
                                        : 'cursor-pointer'
                            "
                            :custom-row="
                                (record) => ({
                                    onClick: () => openThread(record),
                                })
                            "
                            @change="handleTableChange"
                        >
                            <template #bodyCell="{ column, record, text: cellText }">
                                <template v-if="column.key === 'user'">
                                    <span v-if="record.user">
                                        {{ (record.user.name || "").trim() || record.user.email }}
                                    </span>
                                    <span v-else>—</span>
                                </template>
                                <template v-else-if="column.key === 'email'">
                                    {{ cellText || record.user?.email || "—" }}
                                </template>
                                <template v-else-if="column.key === 'preview'">
                                    {{ previewText(record) }}
                                </template>
                                <template v-else-if="column.key === 'last_message_at'">
                                    {{ formatWhen(record.last_message_at) }}
                                </template>
                                <template v-else-if="column.key === 'unread'">
                                    <a-badge
                                        v-if="(record.unread_for_staff || 0) > 0"
                                        :count="record.unread_for_staff"
                                    />
                                    <span v-else class="text-gray-400">—</span>
                                </template>
                                <template v-else-if="column.key === 'actions'">
                                    <a-button
                                        size="small"
                                        @click.stop="openThread(record)"
                                    >
                                        Open
                                    </a-button>
                                </template>
                            </template>
                        </a-table>
                    </template>
                </ContentLayout>
            </div>

            <div
                v-if="thread"
                class="flex w-full min-h-0 min-w-0 max-h-[calc(100dvh-10rem)] flex-col overflow-hidden border border-gray-200 bg-white shadow-sm lg:max-w-md xl:max-w-lg"
            >
                <div
                    class="flex shrink-0 items-start justify-between gap-2 border-b border-gray-100 p-4"
                >
                    <div>
                        <h2 class="text-lg font-semibold text-gray-800">
                            {{ thread.user?.name || "User" }}
                        </h2>
                        <p class="text-sm text-gray-500">
                            {{ thread.user?.email }}
                        </p>
                    </div>
                    <a-button type="text" @click="closeThread">Close</a-button>
                </div>
                <div
                    class="min-h-0 flex-1 overflow-y-auto bg-gray-50 p-3"
                >
                    <div class="space-y-2">
                        <div
                            v-for="m in localMessages"
                            :key="m.id"
                            :class="[
                                'flex',
                                m.is_from_customer ? 'justify-start' : 'justify-end',
                            ]"
                        >
                            <div
                                :class="[
                                    'max-w-[88%] rounded-lg px-3 py-2 text-sm',
                                    m.is_from_customer
                                        ? 'bg-white text-gray-800 shadow'
                                        : 'bg-teal-600 text-white',
                                ]"
                            >
                                <p class="whitespace-pre-wrap break-words">
                                    {{ m.body }}
                                </p>
                                <p
                                    v-if="m.author"
                                    :class="[
                                        'mt-1 text-xs',
                                        m.is_from_customer
                                            ? 'text-gray-500'
                                            : 'text-teal-100',
                                    ]"
                                >
                                    <template v-if="!m.is_from_customer">
                                        {{ m.author.name || m.author.email }}
                                    </template>
                                </p>
                                <p
                                    :class="[
                                        'mt-1 text-xs',
                                        m.is_from_customer
                                            ? 'text-gray-400'
                                            : 'text-teal-200',
                                    ]"
                                >
                                    {{ formatWhen(m.created_at) }}
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
                <div
                    class="shrink-0 border-t border-gray-200 bg-white p-4 pb-[max(0.75rem,env(safe-area-inset-bottom,0px))]"
                >
                    <a-textarea
                        v-model:value="staffBody"
                        :rows="3"
                        :maxlength="10000"
                        show-count
                        placeholder="Type a reply… (Enter to send, Shift+Enter for new line)"
                        class="mb-2"
                        @keydown="onStaffBodyKeydown"
                    />
                    <a-button
                        type="primary"
                        :loading="staffLoading"
                        block
                        @click="sendStaff"
                    >
                        Send reply
                    </a-button>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
