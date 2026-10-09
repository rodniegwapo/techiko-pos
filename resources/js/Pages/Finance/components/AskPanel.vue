<script setup>
import { nextTick, ref } from "vue";
import axios from "axios";
import { IconSparkles, IconSend } from "@tabler/icons-vue";
import { useDomainRoutes } from "@/Composables/useDomainRoutes";
import { answerSections } from "../composables/answerSections";

/**
 * "Ask about your business": the owner types a question in their own words; the AI answers from
 * the business's figures. The last few questions go along so follow-ups make sense.
 */
const props = defineProps({
    aiEnabled: { type: Boolean, default: false },
    suggestions: { type: Array, default: () => [] },
    locationId: { type: Number, default: null },
});

const { getRoute } = useDomainRoutes();

const question = ref("");
const turns = ref([]); // { question, answer, error }
const asking = ref(false);
const language = ref("en");
const remaining = ref(null);
const list = ref(null);

async function ask(text = question.value) {
    const q = (text ?? "").trim();
    if (!q || asking.value) return;
    question.value = "";
    asking.value = true;
    const history = turns.value.filter((t) => t.answer).slice(-4).map((t) => ({ question: t.question, answer: t.answer }));
    const turn = { question: q, answer: null, error: null };
    turns.value.push(turn);
    await nextTick();
    list.value?.scrollTo({ top: list.value.scrollHeight, behavior: "smooth" });
    try {
        const { data } = await axios.post(getRoute("finance.ask"), {
            question: q,
            history,
            language: language.value,
            location_id: props.locationId ?? undefined,
        });
        turn.answer = data.answer;
        remaining.value = data.remaining;
    } catch (e) {
        turn.error = e?.response?.data?.message || "Could not get an answer right now. Please try again.";
    } finally {
        asking.value = false;
        turns.value = [...turns.value];
        await nextTick();
        list.value?.scrollTo({ top: list.value.scrollHeight, behavior: "smooth" });
    }
}
</script>

<template>
    <section class="space-y-3 rounded-lg border border-blue-100 bg-white p-4" data-testid="ask-panel">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h2 class="flex items-center gap-2 text-base font-semibold text-gray-900">
                <IconSparkles :size="18" class="text-blue-600" /> Ask about your business
            </h2>
            <a-radio-group v-model:value="language" size="small">
                <a-radio-button value="en">English</a-radio-button>
                <a-radio-button value="taglish">Taglish</a-radio-button>
            </a-radio-group>
        </div>

        <a-alert v-if="!aiEnabled" type="info" show-icon
            message="The AI assistant is not set up yet. Ask your administrator to add an Anthropic API key." />

        <template v-else>
            <div v-if="turns.length" ref="list" class="max-h-96 space-y-4 overflow-y-auto pr-1" data-testid="ask-turns">
                <div v-for="(turn, i) in turns" :key="i" class="space-y-2">
                    <div class="ml-auto w-fit max-w-[85%] rounded-lg bg-blue-600 px-3 py-2 text-sm text-white">{{ turn.question }}</div>
                    <div class="w-fit max-w-[95%] rounded-lg bg-gray-50 px-3 py-2 text-sm text-gray-800">
                        <a-skeleton v-if="!turn.answer && !turn.error" active :paragraph="{ rows: 2 }" :title="false" />
                        <p v-else-if="turn.error" class="text-red-600">{{ turn.error }}</p>
                        <template v-else>
                            <template v-for="(section, j) in answerSections(turn.answer)" :key="j">
                                <p v-if="section.heading" class="mb-1 mt-2 text-xs font-semibold uppercase text-gray-500">{{ section.heading }}</p>
                                <p v-for="(p, k) in section.paragraphs" :key="`p${k}`" class="mb-1">{{ p }}</p>
                                <ul v-if="section.bullets.length" class="list-disc space-y-1 pl-5">
                                    <li v-for="(b, k) in section.bullets" :key="`b${k}`">{{ b }}</li>
                                </ul>
                            </template>
                        </template>
                    </div>
                </div>
            </div>

            <div v-else class="flex flex-wrap gap-2">
                <a-button v-for="s in suggestions" :key="s" size="small" class="!h-auto whitespace-normal py-1 text-left" @click="ask(s)">
                    {{ s }}
                </a-button>
            </div>

            <form class="flex gap-2" @submit.prevent="ask()">
                <a-input v-model:value="question" :maxlength="500" :disabled="asking"
                    placeholder="e.g. Why is my profit lower than last month?" data-testid="ask-input" />
                <a-button type="primary" html-type="submit" :loading="asking" :disabled="!question.trim()">
                    <template #icon><IconSend :size="16" /></template>
                </a-button>
            </form>
            <p class="text-xs text-gray-500">
                Answers come from your POS records. Suggestions only, not professional financial advice.
                <span v-if="remaining !== null"> · {{ remaining }} questions left today</span>
            </p>
        </template>
    </section>
</template>
