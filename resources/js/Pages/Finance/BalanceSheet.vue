<script setup>
import { computed } from "vue";
import { Head } from "@inertiajs/vue3";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import ContentHeader from "@/Components/ContentHeader.vue";
import ContentLayout from "@/Components/ContentLayout.vue";
import RefreshButton from "@/Components/buttons/Refresh.vue";
import { useHelpers } from "@/Composables/useHelpers";
import { useFinanceFilters } from "./composables/useFinanceFilters";
import FinanceNav from "./components/FinanceNav.vue";
import FinancePeriod from "./components/FinancePeriod.vue";
import ExplainButton from "./components/ExplainButton.vue";

const props = defineProps({
    filters: { type: Object, required: true },
    locations: { type: Array, default: () => [] },
    aiEnabled: { type: Boolean, default: false },
    domainName: { type: String, default: "" },
    balanceSheet: { type: Object, required: true },
    payables: { type: Object, required: true },
});

const { formattedTotal } = useHelpers();
// The balance sheet is a snapshot of today for the whole business: no period or location to filter.
const { load, spinning } = useFinanceFilters({ routeName: "finance.balance-sheet", serverFilters: () => props.filters, showLocation: false });

const bs = computed(() => props.balanceSheet);
const asOf = computed(() =>
    new Date(`${bs.value.as_of}T00:00:00`).toLocaleDateString("en-PH", { year: "numeric", month: "long", day: "numeric" }),
);

const sections = computed(() => [
    { key: "owns", title: "What the business owns", hint: "Assets", rows: bs.value.assets, total: bs.value.total_assets },
    { key: "owes", title: "What the business owes", hint: "Liabilities", rows: bs.value.liabilities, total: bs.value.total_liabilities },
]);
</script>

<template>
    <AuthenticatedLayout>
        <Head title="Balance sheet" />
        <ContentHeader class="mb-4 md:mb-6" title="Balance sheet" />
        <ContentLayout title="What is my business worth?" filter-class="flex flex-wrap items-center justify-end gap-2 w-full min-w-0">
            <template #filters>
                <RefreshButton :loading="spinning" @click="load()" />
                <ExplainButton topic="balance_sheet" :filters="props.filters" :ai-enabled="aiEnabled"
                    label="Explain my financial position" title="Your financial position in plain words" type="primary" size="middle" />
            </template>

            <template #table>
                <div class="space-y-6 px-4 pb-8 md:px-6">
                    <FinanceNav active="finance.balance-sheet" :filters="props.filters" />
                    <FinancePeriod :label="`As of ${asOf}`" note="Whole business" />

                    <div class="rounded-lg border border-blue-100 bg-blue-50 p-4 text-gray-800" data-testid="net-worth">
                        Your business has <strong>{{ formattedTotal(bs.total_assets) }}</strong> in things it owns and
                        <strong>{{ formattedTotal(bs.total_liabilities) }}</strong> it owes, so it is worth about
                        <strong :class="bs.net_worth < 0 ? 'text-red-600' : 'text-emerald-700'">{{ formattedTotal(bs.net_worth) }}</strong>
                        (from what Techiko records).
                    </div>

                    <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
                        <section v-for="section in sections" :key="section.key" class="rounded-lg border border-gray-200 bg-white">
                            <header class="flex items-baseline justify-between border-b border-gray-100 px-4 py-3">
                                <h2 class="text-base font-semibold text-gray-900">{{ section.title }}</h2>
                                <span class="text-xs uppercase tracking-wide text-gray-400">{{ section.hint }}</span>
                            </header>
                            <div class="divide-y divide-gray-100 text-sm">
                                <div v-for="row in section.rows" :key="row.key" class="flex justify-between gap-4 px-4 py-3">
                                    <span class="text-gray-700">{{ row.label }}</span>
                                    <span class="text-gray-900">{{ formattedTotal(row.amount) }}</span>
                                </div>
                                <div class="flex justify-between gap-4 bg-gray-50/60 px-4 py-3">
                                    <span class="font-semibold text-gray-900">Total</span>
                                    <span class="font-semibold text-gray-900">{{ formattedTotal(section.total) }}</span>
                                </div>
                            </div>
                        </section>
                    </div>

                    <section class="rounded-lg border border-gray-200 bg-white">
                        <div class="flex items-center justify-between gap-4 px-4 py-3">
                            <div>
                                <h2 class="text-base font-semibold text-gray-900">Owner's value in the business</h2>
                                <p class="text-xs text-gray-500">What it owns minus what it owes (owner's equity)</p>
                            </div>
                            <span class="text-xl font-semibold" :class="bs.net_worth < 0 ? 'text-red-600' : 'text-gray-900'">
                                {{ formattedTotal(bs.net_worth) }}
                            </span>
                        </div>
                    </section>

                    <div class="rounded-lg border border-dashed border-gray-300 bg-gray-50 p-4 text-sm text-gray-500">
                        <p class="font-medium text-gray-600">Not included yet</p>
                        <p>{{ bs.not_tracked.join(", ") }} aren't recorded in Techiko yet, so the real figure may differ.</p>
                        <p v-if="bs.cash_locations_counted === 0" class="mt-1">
                            No store has recorded today's opening cash, so cash in drawers shows ₱0.00.
                        </p>
                    </div>
                </div>
            </template>
        </ContentLayout>
    </AuthenticatedLayout>
</template>
