<script setup>
import { computed, nextTick, onMounted, ref, watch } from "vue";
import axios from "axios";
import { router, usePage } from "@inertiajs/vue3";
import { IconSparkles, IconSend, IconExternalLink, IconTrash } from "@tabler/icons-vue";
import { useDomainRoutes } from "@/Composables/useDomainRoutes";
import ActionConfirmCard from "./ActionConfirmCard.vue";
import FillInFormCard from "./FillInFormCard.vue";
import { answerBlocks, suggestionsFor } from "./assistantAnswer";

/**
 * The AI assistant tab of the chat drawer. Staff ask how to do things ("how do I add a
 * product?") and get the steps for the tasks their role allows, with a button to open the page,
 * or ask it to make a change: it shows a card, and nothing is saved until they press Confirm.
 * The conversation is kept for this browser tab, so it survives moving between pages.
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
const list = ref(null);
const input = ref(null);

onMounted(() => {
    try {
        const saved = JSON.parse(sessionStorage.getItem(storageKey.value) || "null");
        if (saved) {
            turns.value = (saved.turns || []).filter((t) => t.answer);
            language.value = saved.language || "en";
        }
    } catch {
        // Storage unavailable (private mode): start empty.
    }
    scrollDown();
});

watch(
    [turns, language],
    () => {
        try {
            sessionStorage.setItem(
                storageKey.value,
                JSON.stringify({ turns: turns.value.filter((t) => t.answer).slice(-20), language: language.value }),
            );
        } catch {
            // ignore
        }
    },
    { deep: true },
);

async function scrollDown() {
    await nextTick();
    list.value?.scrollTo({ top: list.value.scrollHeight, behavior: "smooth" });
}

async function ask(text = message.value) {
    const q = (text ?? "").trim();
    if (q.length < 2 || asking.value) return;
    message.value = "";
    asking.value = true;
    const history = turns.value
        .filter((t) => t.answer)
        .slice(-6)
        .map((t) => ({ question: t.question, answer: withOutcomes(t) }));
    const turn = { question: q, answer: null, links: [], actions: [], forms: [], error: null };
    turns.value.push(turn);
    scrollDown();
    try {
        const { data } = await axios.post(getRoute("assistant.ask"), {
            message: q,
            history,
            page: currentRoute.value || undefined,
            language: language.value,
            location_id: page.props.currentLocation?.id ?? undefined,
        });
        turn.answer = data.answer;
        turn.links = data.links || [];
        turn.actions = data.pending_actions || [];
        turn.forms = data.forms || [];
        remaining.value = data.remaining;
    } catch (e) {
        turn.error = e?.response?.data?.message || "Could not get an answer right now. Please try again.";
    } finally {
        asking.value = false;
        turns.value = [...turns.value];
        scrollDown();
        // Ready for the next message without clicking back into the box.
        input.value?.focus();
    }
}

/** The answer plus what became of its cards, so the assistant knows on the next message. */
function withOutcomes(turn) {
    const outcomes = (turn.actions || [])
        .filter((a) => a.state)
        .map((a) => `[${a.title}: ${a.state}${a.result?.message ? " - " + a.result.message : ""}]`);
    return [turn.answer, ...outcomes].join("\n").slice(0, 4000);
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

function clearChat() {
    turns.value = [];
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
            <p class="text-sm text-gray-600">Ask how to do anything in Techiko POS.</p>
            <div class="flex items-center gap-1">
                <a-radio-group v-model:value="language" size="small">
                    <a-radio-button value="en">EN</a-radio-button>
                    <a-radio-button value="taglish">Taglish</a-radio-button>
                </a-radio-group>
                <a-tooltip v-if="turns.length" title="Clear conversation">
                    <a-button size="small" type="text" aria-label="Clear conversation" @click="clearChat">
                        <IconTrash :size="16" />
                    </a-button>
                </a-tooltip>
            </div>
        </div>

        <div ref="list" class="min-h-0 flex-1 space-y-4 overflow-y-auto px-4 pr-3" data-testid="assistant-turns">
            <div v-if="!turns.length" class="space-y-3">
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

            <div v-for="(turn, i) in turns" :key="i" class="space-y-2">
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
            </p>
        </div>
    </div>
</template>
