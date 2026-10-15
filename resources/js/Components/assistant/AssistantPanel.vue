<script setup>
import { computed, nextTick, onMounted, ref, watch } from "vue";
import axios from "axios";
import { router, usePage } from "@inertiajs/vue3";
import dayjs from "dayjs";
import { IconSparkles, IconSend, IconExternalLink, IconTrash, IconHistory, IconMessagePlus, IconArrowLeft } from "@tabler/icons-vue";
import { useDomainRoutes } from "@/Composables/useDomainRoutes";
import ActionConfirmCard from "./ActionConfirmCard.vue";
import FillInFormCard from "./FillInFormCard.vue";
import { answerBlocks, suggestionsFor } from "./assistantAnswer";

/**
 * The AI assistant tab of the chat drawer. Staff ask how to do things ("how do I add a
 * product?") and get the steps for the tasks their role allows, with a button to open the page,
 * or ask it to make a change: it shows a card, and nothing is saved until they press Confirm.
 * Chats are saved to the user's account for 90 days; History reopens or deletes them.
 */
const emit = defineEmits(["ask-human", "navigate"]);

const page = usePage();
const { getRoute } = useDomainRoutes();

const userId = computed(() => page.props.auth?.user?.data?.id ?? "guest");
const storageKey = computed(() => `assistant-chat:${userId.value}`);
const currentRoute = computed(() => {
    try {
        return route().current() || "";
    } catch {
        return "";
    }
});
const suggestions = computed(() => suggestionsFor(currentRoute.value));

const message = ref("");
const turns = ref([]); // { question, answer, links, actions, forms, error }
const asking = ref(false);
const language = ref("en");
const remaining = ref(null);
const creditsLeft = ref(null); // the organization's AI credits left this month (null = unlimited or not known yet)
const list = ref(null);
const input = ref(null);

// Chats are saved to the user's account; this tab only remembers which one is open.
const conversationId = ref(null);
const view = ref("chat"); // "chat" | "history"
const chats = ref([]); // { id, title, last_message_at }
const loadingChats = ref(false);
const loadingChat = ref(false);

onMounted(() => {
    let saved = null;
    try {
        saved = JSON.parse(sessionStorage.getItem(storageKey.value) || "null");
    } catch {
        // Storage unavailable (private mode): start a new chat.
    }
    language.value = saved?.language || "en";
    if (saved?.conversationId) {
        openChat(saved.conversationId);
    }
});

watch([conversationId, language], () => {
    try {
        sessionStorage.setItem(storageKey.value, JSON.stringify({ conversationId: conversationId.value, language: language.value }));
    } catch {
        // ignore
    }
});

async function openChat(id) {
    loadingChat.value = true;
    view.value = "chat";
    try {
        const { data } = await axios.get(getRoute("assistant.conversations.show", { conversation: id }));
        conversationId.value = data.id;
        turns.value = data.turns;
        language.value = data.language || language.value;
    } catch {
        // Deleted or older than 90 days: start fresh.
        newChat();
    } finally {
        loadingChat.value = false;
        scrollDown();
    }
}

function newChat() {
    conversationId.value = null;
    turns.value = [];
    view.value = "chat";
    input.value?.focus();
}

async function showHistory() {
    view.value = "history";
    loadingChats.value = true;
    try {
        const { data } = await axios.get(getRoute("assistant.conversations.index"));
        chats.value = data.data;
    } catch {
        chats.value = [];
    } finally {
        loadingChats.value = false;
    }
}

async function deleteChat(chat) {
    await axios.delete(getRoute("assistant.conversations.destroy", { conversation: chat.id }));
    chats.value = chats.value.filter((c) => c.id !== chat.id);
    if (conversationId.value === chat.id) {
        conversationId.value = null;
        turns.value = [];
    }
}

const chatTime = (at) => {
    const d = dayjs(at);
    return d.isSame(dayjs(), "day") ? d.format("h:mm A") : d.isSame(dayjs(), "year") ? d.format("MMM D") : d.format("MMM D, YYYY");
};

async function scrollDown() {
    await nextTick();
    list.value?.scrollTo({ top: list.value.scrollHeight, behavior: "smooth" });
}

async function ask(text = message.value) {
    const q = (text ?? "").trim();
    if (q.length < 2 || asking.value) return;
    message.value = "";
    asking.value = true;
    view.value = "chat";
    const turn = { question: q, answer: null, links: [], actions: [], forms: [], error: null };
    turns.value.push(turn);
    scrollDown();
    try {
        const { data } = await axios.post(getRoute("assistant.ask"), {
            message: q,
            conversation_id: conversationId.value ?? undefined,
            page: currentRoute.value || undefined,
            language: language.value,
            location_id: page.props.currentLocation?.id ?? undefined,
        });
        conversationId.value = data.conversation_id ?? conversationId.value;
        turn.id = data.message_id;
        turn.answer = data.answer;
        turn.links = data.links || [];
        turn.actions = data.pending_actions || [];
        turn.forms = data.forms || [];
        remaining.value = data.remaining;
        creditsLeft.value = data.credits_remaining ?? null;
    } catch (e) {
        turn.error = e?.response?.data?.message || "Could not get an answer right now. Please try again.";
        // Out of the organization's monthly AI credits.
        if (e?.response?.status === 402) {
            creditsLeft.value = 0;
        }
    } finally {
        asking.value = false;
        turns.value = [...turns.value];
        scrollDown();
        // Ready for the next message without clicking back into the box.
        input.value?.focus();
    }
}

function onSettled(turn, action, result) {
    action.state = result.state;
    action.result = { message: result.message, errors: result.errors };
    turns.value = [...turns.value];
    if (result.state === "done") {
        router.reload({ preserveScroll: true });
    }
}

/** Puts a fill-in form in the message box, with the cursor after the first label ("Name: "). */
async function fillIn(text) {
    message.value = text;
    await nextTick();
    const root = input.value?.$el;
    const box = root?.tagName === "TEXTAREA" ? root : root?.querySelector?.("textarea");
    box?.focus();
    const firstLineEnd = text.indexOf("\n");
    box?.setSelectionRange?.(firstLineEnd, firstLineEnd);
}

function openLink(link) {
    emit("navigate");
    router.visit(link.url);
}

function onKeydown(e) {
    // Leave Enter alone while an IME (e.g. Japanese input) is composing a word.
    if (e.key === "Enter" && !e.shiftKey && !e.isComposing) {
        e.preventDefault();
        ask();
    }
}
</script>

<template>
    <div class="flex h-full min-h-0 min-w-0 flex-1 flex-col" data-testid="assistant-panel">
        <div class="flex shrink-0 items-center justify-between gap-2 px-4 pb-2 pt-1">
            <a-button v-if="view === 'history'" size="small" type="text" class="!flex items-center gap-1 !px-1" @click="view = 'chat'">
                <IconArrowLeft :size="16" /> Back to chat
            </a-button>
            <p v-else class="text-sm text-gray-600">Ask how to do anything in Techiko POS.</p>
            <div class="flex items-center gap-1">
                <a-radio-group v-if="view === 'chat'" v-model:value="language" size="small">
                    <a-radio-button value="en">EN</a-radio-button>
                    <a-radio-button value="taglish">Taglish</a-radio-button>
                </a-radio-group>
                <a-tooltip v-if="view === 'chat'" title="Past chats">
                    <a-button size="small" type="text" aria-label="Past chats" data-testid="assistant-history" @click="showHistory">
                        <IconHistory :size="16" />
                    </a-button>
                </a-tooltip>
                <a-tooltip v-if="turns.length || view === 'history'" title="New chat">
                    <a-button size="small" type="text" aria-label="New chat" data-testid="assistant-new-chat" @click="newChat">
                        <IconMessagePlus :size="16" />
                    </a-button>
                </a-tooltip>
            </div>
        </div>

        <div v-if="view === 'history'" class="min-h-0 flex-1 overflow-y-auto px-4 pr-3" data-testid="assistant-chats">
            <a-skeleton v-if="loadingChats" active :paragraph="{ rows: 4 }" :title="false" />
            <a-empty v-else-if="!chats.length" description="No saved chats yet" class="!mt-10" />
            <ul v-else class="divide-y divide-gray-100">
                <li v-for="chat in chats" :key="chat.id" class="flex items-center gap-2">
                    <button
                        type="button"
                        class="min-w-0 flex-1 rounded px-2 py-2 text-left hover:bg-gray-50"
                        :class="{ 'bg-blue-50': chat.id === conversationId }"
                        @click="openChat(chat.id)"
                    >
                        <span class="block truncate text-sm text-gray-800">{{ chat.title }}</span>
                        <span class="block text-xs text-gray-500">{{ chatTime(chat.last_message_at) }}</span>
                    </button>
                    <a-popconfirm title="Delete this chat?" ok-text="Delete" cancel-text="Keep" @confirm="deleteChat(chat)">
                        <a-button size="small" type="text" danger aria-label="Delete chat">
                            <IconTrash :size="15" />
                        </a-button>
                    </a-popconfirm>
                </li>
            </ul>
        </div>

        <div v-else ref="list" class="min-h-0 flex-1 space-y-4 overflow-y-auto px-4 pr-3" data-testid="assistant-turns">
            <a-skeleton v-if="loadingChat" active :paragraph="{ rows: 3 }" :title="false" />
            <div v-else-if="!turns.length" class="space-y-3">
                <div class="flex items-start gap-2 rounded-lg bg-blue-50 px-3 py-2 text-sm text-gray-800">
                    <IconSparkles :size="18" class="mt-0.5 shrink-0 text-blue-600" />
                    <p>Hi! I can show you step by step how to add products, update your store, change settings and more, or do it for you. What would you like to do?</p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <a-button v-for="s in suggestions" :key="s" size="small" class="!h-auto whitespace-normal py-1 text-left" @click="ask(s)">
                        {{ s }}
                    </a-button>
                </div>
            </div>

            <div v-for="(turn, i) in loadingChat ? [] : turns" :key="i" class="space-y-2">
                <div class="ml-auto w-fit max-w-[85%] whitespace-pre-wrap break-words rounded-lg bg-green-100 px-3 py-2 text-sm text-gray-800">{{ turn.question }}</div>
                <div class="w-fit max-w-[95%] rounded-lg bg-gray-100 px-3 py-2 text-sm text-gray-800" data-testid="assistant-answer">
                    <a-skeleton v-if="!turn.answer && !turn.error" active :paragraph="{ rows: 2 }" :title="false" />
                    <p v-else-if="turn.error" class="text-red-600">{{ turn.error }}</p>
                    <template v-else>
                        <template v-for="(block, j) in answerBlocks(turn.answer)" :key="j">
                            <component
                                :is="block.type"
                                :class="{ 'mb-1': block.type === 'p', 'mb-2 list-decimal space-y-1 pl-5': block.type === 'ol', 'mb-2 list-disc space-y-1 pl-5': block.type === 'ul' }"
                            >
                                <template v-if="block.type === 'p'">
                                    <template v-for="(part, k) in block.items[0]" :key="k">
                                        <strong v-if="part.bold">{{ part.text }}</strong><template v-else>{{ part.text }}</template>
                                    </template>
                                </template>
                                <template v-else>
                                    <li v-for="(item, k) in block.items" :key="k">
                                        <template v-for="(part, m) in item" :key="m">
                                            <strong v-if="part.bold">{{ part.text }}</strong><template v-else>{{ part.text }}</template>
                                        </template>
                                    </li>
                                </template>
                            </component>
                        </template>
                        <ActionConfirmCard
                            v-for="action in turn.actions || []"
                            :key="action.token"
                            :action="action"
                            @settled="(result) => onSettled(turn, action, result)"
                        />
                        <FillInFormCard v-for="form in turn.forms || []" :key="form.action" :form="form" @fill="fillIn" />
                        <div v-if="turn.links?.length" class="mt-2 flex flex-wrap gap-2">
                            <a-button v-for="link in turn.links" :key="link.url" size="small" type="primary" ghost @click="openLink(link)">
                                <span class="inline-flex items-center gap-1">Open: {{ link.title }} <IconExternalLink :size="14" /></span>
                            </a-button>
                        </div>
                    </template>
                </div>
            </div>
        </div>

        <div class="shrink-0 border-t border-gray-200 bg-white px-4 pt-3 pb-[max(0.75rem,env(safe-area-inset-bottom,0px))]">
            <!-- Enter sends, Shift+Enter adds a line. The box stays usable while waiting so focus isn't lost. -->
            <div
                class="relative rounded-lg border border-gray-300 bg-white transition-colors focus-within:border-blue-500"
            >
                <a-textarea
                    ref="input"
                    v-model:value="message"
                    :auto-size="{ minRows: 3, maxRows: 8 }"
                    :maxlength="1000"
                    :bordered="false"
                    class="!resize-none !pr-14 !shadow-none"
                    placeholder="e.g. How do I add a product? (Enter to send, Shift+Enter for a new line)"
                    data-testid="assistant-input"
                    @keydown="onKeydown"
                />
                <a-button
                    type="primary"
                    shape="circle"
                    size="large"
                    class="!absolute bottom-2 right-2 !flex items-center justify-center !p-0"
                    :loading="asking"
                    :disabled="message.trim().length < 2"
                    aria-label="Send"
                    data-testid="assistant-send"
                    @click="ask()"
                >
                    <IconSend v-if="!asking" :size="18" class="block" />
                </a-button>
            </div>
            <p class="mt-2 text-xs text-gray-500">
                AI answers can be wrong.
                <a-button type="link" size="small" class="!h-auto !p-0 !text-xs" @click="emit('ask-human')">Ask a person instead</a-button>
                <span v-if="remaining !== null"> · {{ remaining }} messages left today</span>
                <span v-if="creditsLeft !== null"> · {{ creditsLeft.toLocaleString() }} AI credits left this month</span>
            </p>
            <p class="mt-0.5 text-xs text-gray-400" data-testid="assistant-saved-notice">Chats are saved to your account for 90 days.</p>
        </div>
    </div>
</template>
