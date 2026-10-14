<script setup>
import { computed } from "vue";
import { aiFeatureLabel } from "@/Components/ai/aiFeatures";

/** This month's AI credits (AiCreditService::propsForFrontend): used / allowance, reset date, by feature. */
const props = defineProps({
    credits: { type: Object, required: true },
    /** Hide the by-feature list and the "how credits work" note. */
    compact: { type: Boolean, default: false },
    /** What the out-of-credits / near-limit warning suggests. */
    upgradeHint: { type: String, default: "Upgrade your plan for more." },
});

const resetsOn = computed(() =>
    props.credits.resets_on
        ? new Date(`${props.credits.resets_on}T00:00:00`).toLocaleDateString(undefined, { month: "long", day: "numeric" })
        : "",
);

const status = computed(() => {
    if (props.credits.unlimited) return "normal";
    if (props.credits.remaining === 0) return "exception";
    return props.credits.near_limit ? "active" : "normal";
});

const warning = computed(() =>
    props.credits.remaining === 0
        ? `Your organization has used all of this month's AI credits. ${props.upgradeHint}`
        : `Your organization is close to this month's AI credit limit. ${props.upgradeHint}`,
);
</script>

<template>
    <div class="text-sm text-gray-800">
        <div class="flex flex-wrap items-baseline justify-between gap-2">
            <p class="font-semibold text-gray-900">
                AI credits this month
                <a-tag v-if="credits.source === 'override'" color="blue" class="ml-1">Custom</a-tag>
            </p>
            <p v-if="!credits.unlimited" class="text-xs text-gray-500">Resets on {{ resetsOn }}</p>
        </div>

        <p v-if="credits.unlimited" class="mt-1">
            Your plan includes <strong>unlimited</strong> AI credits. {{ credits.used.toLocaleString() }} used this month.
        </p>
        <template v-else>
            <p class="mt-1">
                <strong class="tabular-nums">{{ credits.used.toLocaleString() }}</strong>
                of <span class="tabular-nums">{{ credits.allowance.toLocaleString() }}</span> used ·
                <span class="tabular-nums">{{ credits.remaining.toLocaleString() }}</span> left
            </p>
            <a-progress :percent="credits.percent" :status="status" :show-info="false" class="!mb-0" />
            <a-alert
                v-if="credits.near_limit"
                class="mt-2"
                show-icon
                :type="credits.remaining === 0 ? 'error' : 'warning'"
                :message="warning"
            />
        </template>

        <template v-if="!compact">
            <ul v-if="credits.by_feature.length" class="mt-3 space-y-1 text-xs text-gray-600 list-none m-0 p-0">
                <li v-for="row in credits.by_feature" :key="row.feature" class="flex justify-between gap-4">
                    <span>{{ aiFeatureLabel(row.feature) }}</span>
                    <span class="tabular-nums">{{ row.credits.toLocaleString() }} credits · {{ row.answers.toLocaleString() }} answers</span>
                </li>
            </ul>
            <p class="mt-3 text-xs text-gray-500">
                Credits are shared by everyone in your organization. A chat message uses
                {{ credits.credits_per_feature.assistant ?? 1 }}; a finance question or explanation uses
                {{ credits.credits_per_feature.finance_question ?? 2 }}. Failed answers are free.
            </p>
        </template>
        <slot />
    </div>
</template>
