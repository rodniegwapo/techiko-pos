<script setup>
import { computed } from "vue";
import { Head, Link, router, useForm } from "@inertiajs/vue3";
import { message } from "ant-design-vue";
import { IconCircleCheck, IconAlertTriangle, IconListCheck, IconSparkles } from "@tabler/icons-vue";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import ContentHeader from "@/Components/ContentHeader.vue";
import ContentLayout from "@/Components/ContentLayout.vue";
import RefreshButton from "@/Components/buttons/Refresh.vue";
import { useHelpers } from "@/Composables/useHelpers";
import { useDomainRoutes } from "@/Composables/useDomainRoutes";
import { useGlobalVariables } from "@/Composables/useGlobalVariable";
import { usePermissionsV2 } from "@/Composables/usePermissionV2";
import FinanceNav from "./components/FinanceNav.vue";
import MetricCard from "./components/MetricCard.vue";
import { answerSections } from "./composables/answerSections";

const props = defineProps({
    reviews: { type: Array, default: () => [] },
    selected: { type: Object, default: null },
    lastMonth: { type: String, required: true },
    lastMonthLabel: { type: String, required: true },
    lastMonthMissing: { type: Boolean, default: false },
    aiEnabled: { type: Boolean, default: false },
    domainName: { type: String, default: "" },
});

const { formattedTotal } = useHelpers();
const { getRoute } = useDomainRoutes();
const { spinning } = useGlobalVariables();
const { hasPermission } = usePermissionsV2();

const generator = useForm({ month: null });
function generate(month) {
    generator.month = month;
    generator.post(getRoute("finance.reviews.generate"), {
        preserveScroll: true,
        onSuccess: () => message.success("Review ready"),
        onError: (e) => message.error(Object.values(e)[0] ?? "Could not write the review"),
    });
}

const f = computed(() => props.selected?.figures ?? {});
const change = (now, before) =>
    before === undefined || before === null ? null : { amount: now - before, pct: before ? Math.round(((now - before) / Math.abs(before)) * 1000) / 10 : null };
const summary = computed(() => answerSections(props.selected?.ai_summary));
</script>

<template>
    <AuthenticatedLayout>
        <Head title="Monthly reviews" />
        <ContentHeader class="mb-4 md:mb-6" title="Monthly business reviews" />
        <ContentLayout title="How did the month go?" filter-class="flex flex-wrap items-center justify-end gap-2 w-full min-w-0">
            <template #filters>
                <RefreshButton :loading="spinning" @click="router.reload()" />
                <a-button v-if="lastMonthMissing && hasPermission('finance.reviews.generate')" type="primary" :loading="generator.processing"
                    data-testid="generate-review" @click="generate(lastMonth)">
                    Write the {{ lastMonthLabel }} review
                </a-button>
            </template>

            <template #table>
                <div class="space-y-6 px-4 pb-8 md:px-6">
                    <FinanceNav active="finance.reviews.index" :filters="{ key: 'month' }" />

                    <div v-if="!reviews.length" class="rounded-lg border border-gray-200 bg-white p-6 text-center text-sm text-gray-500" data-testid="no-reviews">
                        No reviews yet. A review of each month is written on the 1st of the next month.
                        <template v-if="lastMonthMissing && hasPermission('finance.reviews.generate')"> You can also write last month's now.</template>
                    </div>

                    <div v-else class="grid grid-cols-1 gap-6 lg:grid-cols-4">
                        <nav class="space-y-1 lg:col-span-1" data-testid="review-months">
                            <Link v-for="r in reviews" :key="r.id" :href="`${getRoute('finance.reviews.index')}?month=${r.month}`"
                                class="flex items-center justify-between rounded-md px-3 py-2 text-sm"
                                :class="selected?.month === r.month ? 'bg-blue-50 font-medium text-blue-700' : 'text-gray-700 hover:bg-gray-50'">
                                {{ r.label }}
                                <span v-if="!r.read_at" class="h-2 w-2 rounded-full bg-blue-600" title="Not read yet" />
                            </Link>
                        </nav>

                        <article v-if="selected" class="space-y-6 lg:col-span-3" data-testid="review">
                            <div class="flex flex-wrap items-baseline justify-between gap-2">
                                <h2 class="text-xl font-semibold text-gray-900">Your {{ selected.label }} business review</h2>
                                <a-button v-if="hasPermission('finance.reviews.generate')" size="small" :loading="generator.processing"
                                    @click="generate(selected.month)">Write it again</a-button>
                            </div>

                            <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                                <MetricCard label="Sales" :value="f.sales" :change="change(f.sales, f.previous?.total_paid)" />
                                <MetricCard label="Gross profit" :value="f.gross_profit" :change="change(f.gross_profit, f.previous?.gross_profit)"
                                    :hint="`${f.gross_margin_pct}% of each sale kept`" />
                                <MetricCard label="Net profit" :value="f.net_profit" :change="change(f.net_profit, f.previous?.net_profit)"
                                    :hint="f.expenses_recorded ? `${f.net_margin_pct}% after all costs` : 'No expenses recorded: overstated'" />
                            </div>

                            <section v-if="selected.ai_summary" class="space-y-2 rounded-lg border border-blue-100 bg-blue-50 p-4 text-[15px] leading-relaxed text-gray-800">
                                <h3 class="flex items-center gap-2 text-sm font-semibold text-blue-700"><IconSparkles :size="16" /> Summary</h3>
                                <template v-for="(section, i) in summary" :key="i">
                                    <p v-if="section.heading" class="mt-2 text-xs font-semibold uppercase text-gray-500">{{ section.heading }}</p>
                                    <p v-for="(p, j) in section.paragraphs" :key="`p${j}`">{{ p }}</p>
                                    <ul v-if="section.bullets.length" class="list-disc space-y-1 pl-5">
                                        <li v-for="(b, j) in section.bullets" :key="`b${j}`">{{ b }}</li>
                                    </ul>
                                </template>
                            </section>

                            <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
                                <section class="space-y-2 rounded-lg border border-gray-200 bg-white p-4" data-testid="went-well">
                                    <h3 class="flex items-center gap-2 font-semibold text-emerald-700"><IconCircleCheck :size="18" /> What went well</h3>
                                    <p v-if="!selected.went_well.length" class="text-sm text-gray-500">Nothing stood out this month.</p>
                                    <ul class="list-disc space-y-1 pl-5 text-sm text-gray-700">
                                        <li v-for="item in selected.went_well" :key="item">{{ item }}</li>
                                    </ul>
                                </section>
                                <section class="space-y-2 rounded-lg border border-gray-200 bg-white p-4" data-testid="needs-attention">
                                    <h3 class="flex items-center gap-2 font-semibold text-amber-700"><IconAlertTriangle :size="18" /> What needs attention</h3>
                                    <p v-if="!selected.needs_attention.length" class="text-sm text-gray-500">Nothing worrying this month.</p>
                                    <ul class="list-disc space-y-1 pl-5 text-sm text-gray-700">
                                        <li v-for="item in selected.needs_attention" :key="item">{{ item }}</li>
                                    </ul>
                                </section>
                            </div>

                            <section class="space-y-2 rounded-lg border border-gray-200 bg-white p-4" data-testid="actions">
                                <h3 class="flex items-center gap-2 font-semibold text-gray-900"><IconListCheck :size="18" /> Recommended actions</h3>
                                <p v-if="!selected.actions.length" class="text-sm text-gray-500">No actions suggested.</p>
                                <ol class="list-decimal space-y-1 pl-5 text-sm text-gray-700">
                                    <li v-for="item in selected.actions" :key="item">{{ item }}</li>
                                </ol>
                            </section>

                            <p class="text-xs text-gray-500">
                                Written {{ new Date(selected.generated_at).toLocaleString("en-PH") }} from your POS records. Suggestions only, not
                                professional financial advice.<template v-if="!aiEnabled"> An AI summary appears here once the AI assistant is set up.</template>
                            </p>
                        </article>
                    </div>
                </div>
            </template>
        </ContentLayout>
    </AuthenticatedLayout>
</template>
