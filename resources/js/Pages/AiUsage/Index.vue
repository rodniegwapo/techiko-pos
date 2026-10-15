<script setup>
import { computed, ref } from "vue";
import { Head, router } from "@inertiajs/vue3";
import VueApexCharts from "vue3-apexcharts";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import ContentHeader from "@/Components/ContentHeader.vue";
import ContentLayout from "@/Components/ContentLayout.vue";
import AiCreditsMeter from "@/Components/ai/AiCreditsMeter.vue";
import { aiFeatureLabel } from "@/Components/ai/aiFeatures";

/** The organization's plan and this month's AI credits (credits only; no costs here). */
const props = defineProps({
    plan: { type: Object, required: true },
    aiCredits: { type: Object, required: true },
    usage: { type: Object, default: () => ({ daily: [], weekly: [], monthly: [] }) },
    byUser: { type: Array, default: () => [] },
    recent: { type: Object, required: true },
});

const allowanceLabel = computed(() =>
    props.aiCredits.unlimited ? "Unlimited AI credits" : `${props.aiCredits.allowance.toLocaleString()} AI credits / month`,
);

const periods = [
    { value: "daily", label: "Daily", title: "Credits per day (last 30 days)", empty: "No AI used in the last 30 days" },
    { value: "weekly", label: "Weekly", title: "Credits per week (last 12 weeks)", empty: "No AI used in the last 12 weeks" },
    { value: "monthly", label: "Monthly", title: "Credits per month (last 12 months)", empty: "No AI used in the last 12 months" },
];
const period = ref("daily");
const currentPeriod = computed(() => periods.find((p) => p.value === period.value));
const usageRows = computed(() => props.usage[period.value] ?? []);

/** Each row's date is the first day of its day, week or month. */
const periodLabel = (date) => {
    const d = new Date(`${date}T00:00:00`);
    if (period.value === "monthly") return d.toLocaleDateString(undefined, { month: "short", year: "numeric" });
    const day = d.toLocaleDateString(undefined, { month: "short", day: "numeric" });
    return period.value === "weekly" ? `Week of ${day}` : day;
};

const chartOptions = computed(() => ({
    chart: { type: "bar", toolbar: { show: false }, fontFamily: "inherit" },
    plotOptions: { bar: { columnWidth: "60%", borderRadius: 3 } },
    dataLabels: { enabled: false },
    colors: ["#2563eb"],
    xaxis: { categories: usageRows.value.map((d) => periodLabel(d.date)), labels: { rotate: -45, hideOverlappingLabels: true } },
    yaxis: { labels: { formatter: (v) => Math.round(v).toLocaleString() } },
    tooltip: { y: { formatter: (v) => `${v.toLocaleString()} credits` } },
    grid: { borderColor: "#f1f5f9" },
}));
const chartSeries = computed(() => [{ name: "Credits", data: usageRows.value.map((d) => d.credits) }]);
const hasUsage = computed(() => usageRows.value.some((d) => d.credits > 0));

const userColumns = [
    { title: "User", dataIndex: "name", key: "name", ellipsis: true },
    { title: "Answers", dataIndex: "answers", key: "answers", align: "right", width: 100 },
    { title: "Credits", dataIndex: "credits", key: "credits", align: "right", width: 100 },
];

const recentColumns = [
    {
        title: "When",
        dataIndex: "at",
        key: "at",
        width: 190,
        customRender: ({ text }) => (text ? new Date(text).toLocaleString(undefined, { dateStyle: "medium", timeStyle: "short" }) : ""),
    },
    { title: "User", dataIndex: "user", key: "user", ellipsis: true },
    { title: "Feature", dataIndex: "feature", key: "feature", customRender: ({ text }) => aiFeatureLabel(text) },
    { title: "Credits", dataIndex: "credits", key: "credits", align: "right", width: 90 },
];

const pagination = computed(() => ({
    current: props.recent.current_page,
    pageSize: props.recent.per_page,
    total: props.recent.total,
    showSizeChanger: false,
}));

const changePage = (pager) => {
    router.get(window.location.pathname, { page: pager.current }, { preserveState: true, preserveScroll: true, only: ["recent"] });
};
</script>

<template>
    <AuthenticatedLayout>
        <Head title="AI usage" />
        <ContentHeader class="mb-2" title="AI usage" />
        <p class="-mt-2 mb-6 max-w-2xl text-sm text-gray-600">
            Your plan's AI credits and where they went. Everyone in your organization shares the same credits.
        </p>

        <ContentLayout title="This month">
            <template #table>
                <div class="mx-auto max-w-6xl space-y-6 px-2 py-2 pb-10 sm:px-4">
                    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
                        <section class="rounded-lg border border-gray-200 bg-white px-5 py-4 shadow-sm" data-testid="ai-usage-plan">
                            <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Current plan</p>
                            <p class="mt-1 text-lg font-bold text-gray-900">{{ plan.name }}</p>
                            <p class="text-sm text-gray-600">
                                {{ allowanceLabel }}
                                <a-tag v-if="aiCredits.source === 'override'" color="blue" class="ml-1">Custom</a-tag>
                            </p>
                            <p v-if="aiCredits.source === 'override'" class="mt-1 text-xs text-gray-500">
                                Set specially for your organization. The plan normally includes
                                {{ plan.plan_unlimited ? "unlimited" : plan.plan_credits.toLocaleString() }}.
                            </p>
                        </section>

                        <section class="rounded-lg border border-gray-200 bg-white px-5 py-4 shadow-sm lg:col-span-2">
                            <AiCreditsMeter :credits="aiCredits" upgrade-hint="Contact us to change your plan, or wait for the reset." />
                        </section>
                    </div>

                    <section class="rounded-lg border border-gray-200 bg-white px-5 py-4 shadow-sm">
                        <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
                            <h2 class="font-semibold text-gray-900">{{ currentPeriod.title }}</h2>
                            <a-radio-group v-model:value="period" button-style="solid" size="small" data-testid="ai-usage-period">
                                <a-radio-button v-for="p in periods" :key="p.value" :value="p.value">{{ p.label }}</a-radio-button>
                            </a-radio-group>
                        </div>
                        <VueApexCharts v-if="hasUsage" type="bar" height="260" :options="chartOptions" :series="chartSeries" />
                        <a-empty v-else :description="currentPeriod.empty" />
                    </section>

                    <section class="rounded-lg border border-gray-200 bg-white px-5 py-4 shadow-sm">
                        <h2 class="mb-2 font-semibold text-gray-900">By user this month</h2>
                        <a-table
                            :columns="userColumns"
                            :data-source="byUser"
                            :pagination="false"
                            :row-key="(row) => row.user_id ?? 'system'"
                            size="small"
                            :locale="{ emptyText: 'No AI used this month' }"
                        />
                    </section>

                    <section class="rounded-lg border border-gray-200 bg-white px-5 py-4 shadow-sm">
                        <h2 class="mb-2 font-semibold text-gray-900">Recent AI answers</h2>
                        <a-table
                            :columns="recentColumns"
                            :data-source="recent.data"
                            :pagination="pagination"
                            row-key="id"
                            size="small"
                            :locale="{ emptyText: 'No AI answers yet' }"
                            @change="changePage"
                        />
                    </section>
                </div>
            </template>
        </ContentLayout>
    </AuthenticatedLayout>
</template>
