<script setup>
import { computed, ref } from "vue";
import axios from "axios";
import { useMediaQuery } from "@vueuse/core";
import { IconSparkles } from "@tabler/icons-vue";
import { useDomainRoutes } from "@/Composables/useDomainRoutes";
import { answerSections } from "../composables/answerSections";

/**
 * "Explain this": asks the AI assistant to put the figures on the page into plain words.
 * The explanation is written from the same numbers the page shows, for the same period and location.
 */
const props = defineProps({
    topic: { type: String, required: true },
    metric: { type: String, default: null },
    filters: { type: Object, required: true },
    aiEnabled: { type: Boolean, default: false },
    label: { type: String, default: "Explain this" },
    title: { type: String, default: "Explanation" },
    size: { type: String, default: "small" },
    type: { type: String, default: "default" },
    link: { type: Boolean, default: false },
});

const { getRoute } = useDomainRoutes();

const open = ref(false);
const loading = ref(false);
const error = ref("");
const answer = ref(null);
const language = ref("en");
const isMdUp = useMediaQuery("(min-width: 768px)");
const drawerWidth = computed(() => (isMdUp.value ? 480 : "100%"));

/** Splits the answer into its summary, "What to watch" and "Suggested next steps" parts. */
const sections = computed(() => answerSections(answer.value?.text));

async function fetchExplanation() {
    loading.value = true;
    error.value = "";
    try {
        const { data } = await axios.post(getRoute("finance.explain"), {
            topic: props.topic,
            metric: props.metric ?? undefined,
            language: language.value,
            period: props.filters.key,
            start_date: props.filters.key === "custom" ? props.filters.start_date : undefined,
            end_date: props.filters.key === "custom" ? props.filters.end_date : undefined,
            location_id: props.filters.location_id ?? undefined,
        });
        answer.value = data;
    } catch (e) {
        error.value = e?.response?.data?.message || "Could not get an explanation right now. Please try again.";
    } finally {
        loading.value = false;
    }
}

function show() {
    open.value = true;
    if (!answer.value && !loading.value) fetchExplanation();
}

function switchLanguage() {
    answer.value = null;
    fetchExplanation();
}
</script>

<template>
    <a-tooltip :title="aiEnabled ? null : 'The AI assistant is not set up yet'">
        <a-button
            :type="link ? 'link' : type"
            :size="size"
            :disabled="!aiEnabled"
            :class="link ? '!h-auto !p-0' : ''"
            data-testid="finance-explain"
            @click.stop="show"
        >
            <span class="inline-flex items-center gap-1">
                <IconSparkles :size="size === 'small' ? 14 : 16" />
                {{ label }}
            </span>
        </a-button>
    </a-tooltip>

    <a-drawer v-model:visible="open" :title="title" placement="right" :width="drawerWidth" destroy-on-close>
        <div class="mb-4 flex items-center justify-between gap-2">
            <span class="text-xs text-gray-500">{{ filters.start_date }} to {{ filters.end_date }}</span>
            <a-radio-group v-model:value="language" size="small" :disabled="loading" @change="switchLanguage">
                <a-radio-button value="en">English</a-radio-button>
                <a-radio-button value="taglish">Taglish</a-radio-button>
            </a-radio-group>
        </div>

        <div v-if="loading" class="py-6" data-testid="finance-explain-loading">
            <a-skeleton active :paragraph="{ rows: 6 }" />
        </div>

        <a-alert v-else-if="error" type="warning" show-icon :message="error" class="mb-4">
            <template #action>
                <a-button size="small" @click="fetchExplanation">Try again</a-button>
            </template>
        </a-alert>

        <div v-else-if="answer" class="space-y-5 text-[15px] leading-relaxed text-gray-800" data-testid="finance-explain-answer">
            <section v-for="(section, i) in sections" :key="i">
                <h3 v-if="section.heading" class="mb-2 text-sm font-semibold uppercase tracking-wide text-gray-500">
                    {{ section.heading }}
                </h3>
                <p v-for="(p, j) in section.paragraphs" :key="`p${j}`" class="mb-2">{{ p }}</p>
                <ul v-if="section.bullets.length" class="list-disc space-y-1 pl-5">
                    <li v-for="(b, j) in section.bullets" :key="`b${j}`">{{ b }}</li>
                </ul>
            </section>
            <p class="border-t border-gray-100 pt-3 text-xs text-gray-500">{{ answer.disclaimer }}</p>
        </div>
    </a-drawer>
</template>
