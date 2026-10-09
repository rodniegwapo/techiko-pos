<script setup>
import { computed } from "vue";
import { Head, Link } from "@inertiajs/vue3";
import { useDomainRoutes } from "@/Composables/useDomainRoutes";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import ContentHeader from "@/Components/ContentHeader.vue";
import ContentLayout from "@/Components/ContentLayout.vue";
import RefreshButton from "@/Components/buttons/Refresh.vue";
import FilterDropdown from "@/Components/filters/FilterDropdown.vue";
import ActiveFilters from "@/Components/filters/ActiveFilters.vue";
import { useHelpers } from "@/Composables/useHelpers";
import { useFinanceFilters } from "./composables/useFinanceFilters";
import FinanceNav from "./components/FinanceNav.vue";
import FinancePeriod from "./components/FinancePeriod.vue";
import ExplainButton from "./components/ExplainButton.vue";
import MetricCard from "./components/MetricCard.vue";

const props = defineProps({
    filters: { type: Object, required: true },
    locations: { type: Array, default: () => [] },
    aiEnabled: { type: Boolean, default: false },
    domainName: { type: String, default: "" },
    current: { type: Object, required: true },
    previous: { type: Object, required: true },
});

const { formattedTotal } = useHelpers();
const { getRoute } = useDomainRoutes();
const { filters, filtersConfig, activeFilters, handleClearSelectedFilter, clearAll, load, spinning, periodLabel, previousLabel } =
    useFinanceFilters({ routeName: "finance.cash-flow", serverFilters: () => props.filters, locations: props.locations });

const change = (now, before) => ({ amount: now - before, pct: before ? Math.round(((now - before) / Math.abs(before)) * 1000) / 10 : null });

const sections = computed(() => [
    {
        title: "Money in",
        sign: "+",
        total: { label: "Total money in", now: props.current.total_in, before: props.previous.total_in },
        rows: [
            { key: "customer_payments", label: "Paid by customers for sales", note: "Cash, card, e-wallet and bank" },
            { key: "credit_collections", label: "Collected from customers on credit" },
            { key: "other_income", label: "Other income" },
            { key: "loans_received", label: "Loans received" },
            { key: "owner_investments", label: "Owner investments", note: "Money the owner put into the business" },
        ].map((r) => ({ ...r, now: props.current.in[r.key], before: props.previous.in[r.key] })),
    },
    {
        title: "Money out",
        sign: "−",
        total: { label: "Total money out", now: props.current.total_out, before: props.previous.total_out },
        rows: [
            { key: "stock_purchases", label: "Paid to suppliers for stock" },
            { key: "operating_expenses", label: "Expenses paid", note: "Including expense bills paid to suppliers and loan interest" },
            { key: "loan_repayments", label: "Loan repayments", note: "The principal part; interest is under expenses" },
            { key: "equipment_purchases", label: "Equipment and other assets bought" },
            { key: "owner_withdrawals", label: "Owner withdrawals", note: "End-of-shift cash-outs are not counted: that money stays in the business" },
        ].map((r) => ({ ...r, now: props.current.out[r.key], before: props.previous.out[r.key] })),
    },
]);

const accounts = computed(() => props.current.account_balances ?? { accounts: [] });
</script>

<template>
    <AuthenticatedLayout>
        <Head title="Cash flow" />
        <ContentHeader class="mb-4 md:mb-6" title="Cash flow" />
        <ContentLayout title="Where did my money go?" filter-class="flex flex-wrap items-center justify-end gap-2 w-full min-w-0">
            <template #filters>
                <RefreshButton :loading="spinning" @click="load()" />
                <ExplainButton topic="cash_flow" :filters="props.filters" :ai-enabled="aiEnabled"
                    label="Explain my cash flow" title="Your cash flow in plain words" type="primary" size="middle" />
                <FilterDropdown v-model="filters" :filters="filtersConfig" />
            </template>

            <template #activeFilters>
                <ActiveFilters :filters="activeFilters" @remove-filter="handleClearSelectedFilter" @clear-all="clearAll" />
            </template>

            <template #table>
                <div class="space-y-6 px-4 pb-8 md:px-6">
                    <FinanceNav active="finance.cash-flow" :filters="props.filters" />
                    <FinancePeriod :label="periodLabel" :previous="previousLabel" note="Drawer, e-wallets, card terminals and bank channels together" />

                    <div class="grid grid-cols-1 gap-4 md:grid-cols-3" data-testid="cash-flow-summary">
                        <MetricCard label="Money in" :value="current.total_in" :change="change(current.total_in, previous.total_in)" />
                        <MetricCard label="Money out" :value="current.total_out" :change="change(current.total_out, previous.total_out)" :up-is-good="false" />
                        <MetricCard label="Net change in money" :value="current.net_change" :change="change(current.net_change, previous.net_change)"
                            :hint="`Net profit for the same period: ${formattedTotal(current.net_profit)}`" />
                    </div>

                    <div class="overflow-x-auto rounded-lg border border-gray-200 bg-white">
                        <table class="w-full min-w-[520px] text-sm" data-testid="cash-flow-statement">
                            <thead class="bg-gray-50 text-xs uppercase tracking-wide text-gray-500">
                                <tr>
                                    <th class="px-4 py-3 text-left font-medium"></th>
                                    <th class="px-4 py-3 text-right font-medium">This period</th>
                                    <th class="px-4 py-3 text-right font-medium">Previous</th>
                                </tr>
                            </thead>
                            <template v-for="section in sections" :key="section.title">
                                <tbody>
                                    <tr class="border-t border-gray-200 bg-gray-50/60">
                                        <td colspan="3" class="px-4 py-2 text-xs font-semibold uppercase tracking-wide text-gray-500">{{ section.title }}</td>
                                    </tr>
                                    <tr v-for="row in section.rows" :key="row.key" class="border-t border-gray-100">
                                        <td class="px-4 py-3 pl-8">
                                            <span class="text-gray-700"><span class="mr-1 text-gray-400">{{ section.sign }}</span>{{ row.label }}</span>
                                            <div v-if="row.note" class="text-xs text-gray-500">{{ row.note }}</div>
                                        </td>
                                        <td class="px-4 py-3 text-right text-gray-900">{{ formattedTotal(row.now) }}</td>
                                        <td class="px-4 py-3 text-right text-gray-500">{{ formattedTotal(row.before) }}</td>
                                    </tr>
                                    <tr class="border-t border-gray-100">
                                        <td class="px-4 py-3 font-semibold text-gray-900">{{ section.total.label }}</td>
                                        <td class="px-4 py-3 text-right font-semibold text-gray-900">{{ formattedTotal(section.total.now) }}</td>
                                        <td class="px-4 py-3 text-right text-gray-500">{{ formattedTotal(section.total.before) }}</td>
                                    </tr>
                                </tbody>
                            </template>
                            <tbody>
                                <tr class="border-t border-gray-200">
                                    <td class="px-4 py-3">
                                        <span class="text-gray-700">± Drawer top-ups and adjustments</span>
                                        <div class="text-xs text-gray-500">Float added to the drawer and cash count differences</div>
                                    </td>
                                    <td class="px-4 py-3 text-right text-gray-900">{{ formattedTotal(current.other_movements) }}</td>
                                    <td class="px-4 py-3 text-right text-gray-500">{{ formattedTotal(previous.other_movements) }}</td>
                                </tr>
                                <tr class="border-t-2 border-gray-300 bg-gray-50/60">
                                    <td class="px-4 py-3 font-semibold text-gray-900">Net change in money</td>
                                    <td class="px-4 py-3 text-right font-semibold" :class="current.net_change < 0 ? 'text-red-600' : 'text-gray-900'">
                                        {{ formattedTotal(current.net_change) }}
                                    </td>
                                    <td class="px-4 py-3 text-right text-gray-500">{{ formattedTotal(previous.net_change) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <section class="space-y-3 rounded-lg border border-gray-200 bg-white p-4" data-testid="account-balances">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <h2 class="text-base font-semibold text-gray-900">Bank and e-wallet balances</h2>
                            <Link :href="getRoute('finance.balance-items.index')" class="text-sm text-blue-600 hover:underline">Update balances</Link>
                        </div>
                        <p v-if="!accounts.accounts.length" class="text-sm text-gray-500">
                            No bank or e-wallet accounts yet. Add them under Accounts, loans &amp; assets to see your full cash position.
                        </p>
                        <table v-else class="w-full text-sm">
                            <thead class="text-xs uppercase tracking-wide text-gray-500">
                                <tr>
                                    <th class="py-2 text-left font-medium">Account</th>
                                    <th class="py-2 text-right font-medium">Start of period</th>
                                    <th class="py-2 text-right font-medium">End of period</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="a in accounts.accounts" :key="a.id" class="border-t border-gray-100">
                                    <td class="py-2 text-gray-700">{{ a.name }}<span v-if="a.as_of" class="text-xs text-gray-400"> · as of {{ a.as_of }}</span></td>
                                    <td class="py-2 text-right text-gray-500">{{ a.opening === null ? "—" : formattedTotal(a.opening) }}</td>
                                    <td class="py-2 text-right text-gray-900">{{ formattedTotal(a.balance) }}</td>
                                </tr>
                            </tbody>
                        </table>
                        <p class="text-xs text-gray-500">Balances are the ones you entered, not a live bank feed.</p>
                    </section>

                    <section class="space-y-3 rounded-lg border border-gray-200 bg-white p-4" data-testid="profit-vs-cash">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <h2 class="text-base font-semibold text-gray-900">Why profit and money are different</h2>
                            <ExplainButton topic="metric" metric="Profit versus cash" :filters="props.filters" :ai-enabled="aiEnabled"
                                title="Profit versus cash" label="Explain" />
                        </div>
                        <p class="text-sm text-gray-600">
                            Profit counts what you earned; money counts what actually came in and went out. Starting from net profit:
                        </p>
                        <div class="divide-y divide-gray-100 text-sm">
                            <div class="flex justify-between py-2">
                                <span class="font-medium text-gray-900">Net profit</span>
                                <span class="font-medium text-gray-900">{{ formattedTotal(current.net_profit) }}</span>
                            </div>
                            <div v-for="row in current.bridge" :key="row.key" class="flex justify-between gap-4 py-2">
                                <span class="text-gray-600">{{ row.label }}</span>
                                <span :class="row.amount < 0 ? 'text-red-600' : 'text-emerald-600'">
                                    {{ row.amount >= 0 ? "+" : "−" }}{{ formattedTotal(Math.abs(row.amount)) }}
                                </span>
                            </div>
                            <div class="flex justify-between py-2">
                                <span class="font-semibold text-gray-900">Net change in money</span>
                                <span class="font-semibold text-gray-900">{{ formattedTotal(current.net_change) }}</span>
                            </div>
                        </div>
                    </section>
                </div>
            </template>
        </ContentLayout>
    </AuthenticatedLayout>
</template>
