<script setup>
import { computed } from "vue";
import { Head, Link } from "@inertiajs/vue3";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import ContentHeader from "@/Components/ContentHeader.vue";
import ContentLayout from "@/Components/ContentLayout.vue";
import RefreshButton from "@/Components/buttons/Refresh.vue";
import { useHelpers } from "@/Composables/useHelpers";
import { useDomainRoutes } from "@/Composables/useDomainRoutes";
import { usePermissionsV2 } from "@/Composables/usePermissionV2";
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
const { getRoute } = useDomainRoutes();
const { hasPermission } = usePermissionsV2();
// The balance sheet is a snapshot of today for the whole business: no period or location to filter.
const { load, spinning } = useFinanceFilters({ routeName: "finance.balance-sheet", serverFilters: () => props.filters, showLocation: false });

const bs = computed(() => props.balanceSheet);
const asOf = computed(() =>
    new Date(`${bs.value.as_of}T00:00:00`).toLocaleDateString("en-PH", { year: "numeric", month: "long", day: "numeric" }),
);

/** Detail lines shown under some balance sheet lines. */
const details = computed(() => ({
    bank: bs.value.accounts.filter((a) => a.type !== "ewallet").map((a) => ({ label: a.name, note: a.as_of ? `as of ${a.as_of}` : "no balance yet", amount: a.balance })),
    ewallet: bs.value.accounts.filter((a) => a.type === "ewallet").map((a) => ({ label: a.name, note: a.as_of ? `as of ${a.as_of}` : "no balance yet", amount: a.balance })),
    fixed_assets: bs.value.fixed_assets.map((a) => ({ label: a.name, note: `cost ${formattedTotal(a.cost)}`, amount: a.book_value })),
    loans: bs.value.loans.map((l) => ({ label: l.lender, note: `borrowed ${formattedTotal(l.principal)}`, amount: l.balance })),
    other_liabilities: (bs.value.other_liabilities ?? []).map((l) => ({ label: l.name, note: l.due_date ? `due ${l.due_date}` : "no due date", amount: l.amount })),
}));

const equityNotes = computed(() => ({
    accumulated_profit: bs.value.profit_since ? `Net profit from ${bs.value.profit_since} (your first sale in Techiko) until today` : "No sales recorded yet",
    other_changes: "What your records don't explain: stock, cash or equipment you had before using Techiko, and anything not recorded",
}));

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
                <Link v-if="hasPermission('finance.balance-items.index')" :href="getRoute('finance.balance-items.index')">
                    <a-button>Accounts, loans &amp; assets</a-button>
                </Link>
            </template>

            <template #table>
                <div class="space-y-6 px-4 pb-8 md:px-6">
                    <FinanceNav active="finance.balance-sheet" :filters="props.filters" />
                    <FinancePeriod :label="`As of ${asOf}`" note="Whole business" />

                    <div class="rounded-lg border border-blue-100 bg-blue-50 p-4 text-gray-800" data-testid="net-worth">
                        Your business has <strong>{{ formattedTotal(bs.total_assets) }}</strong> in things it owns and
                        <strong>{{ formattedTotal(bs.total_liabilities) }}</strong> it owes, so it is worth about
                        <strong :class="bs.net_worth < 0 ? 'text-red-600' : 'text-emerald-700'">{{ formattedTotal(bs.net_worth) }}</strong>.
                    </div>

                    <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
                        <section v-for="section in sections" :key="section.key" class="rounded-lg border border-gray-200 bg-white">
                            <header class="flex items-baseline justify-between border-b border-gray-100 px-4 py-3">
                                <h2 class="text-base font-semibold text-gray-900">{{ section.title }}</h2>
                                <span class="text-xs uppercase tracking-wide text-gray-400">{{ section.hint }}</span>
                            </header>
                            <div class="divide-y divide-gray-100 text-sm">
                                <div v-for="row in section.rows" :key="row.key" class="px-4 py-3">
                                    <div class="flex justify-between gap-4">
                                        <span class="text-gray-700">{{ row.label }}</span>
                                        <span class="text-gray-900">{{ formattedTotal(row.amount) }}</span>
                                    </div>
                                    <div v-for="d in details[row.key] ?? []" :key="d.label" class="mt-1 flex justify-between gap-4 pl-4 text-xs text-gray-500">
                                        <span>{{ d.label }} <span class="text-gray-400">· {{ d.note }}</span></span>
                                        <span>{{ formattedTotal(d.amount) }}</span>
                                    </div>
                                </div>
                                <div class="flex justify-between gap-4 bg-gray-50/60 px-4 py-3">
                                    <span class="font-semibold text-gray-900">Total</span>
                                    <span class="font-semibold text-gray-900">{{ formattedTotal(section.total) }}</span>
                                </div>
                            </div>
                        </section>
                    </div>

                    <section class="rounded-lg border border-gray-200 bg-white" data-testid="owner-equity">
                        <header class="flex items-baseline justify-between border-b border-gray-100 px-4 py-3">
                            <h2 class="text-base font-semibold text-gray-900">Owner's value in the business</h2>
                            <span class="text-xs uppercase tracking-wide text-gray-400">Equity</span>
                        </header>
                        <div class="divide-y divide-gray-100 text-sm">
                            <div v-for="row in bs.equity" :key="row.key" class="flex justify-between gap-4 px-4 py-3" :data-testid="`equity-${row.key}`">
                                <span class="text-gray-700">
                                    {{ row.label }}
                                    <span v-if="equityNotes[row.key]" class="block text-xs text-gray-500">{{ equityNotes[row.key] }}</span>
                                </span>
                                <span :class="row.amount < 0 ? 'text-red-600' : 'text-gray-900'">{{ formattedTotal(row.amount) }}</span>
                            </div>
                            <div class="flex justify-between gap-4 bg-gray-50/60 px-4 py-3">
                                <span class="font-semibold text-gray-900">Owner's equity (what it owns minus what it owes)</span>
                                <span class="font-semibold" :class="bs.net_worth < 0 ? 'text-red-600' : 'text-gray-900'">{{ formattedTotal(bs.net_worth) }}</span>
                            </div>
                        </div>
                    </section>

                    <div class="rounded-lg border border-dashed border-gray-300 bg-gray-50 p-4 text-sm text-gray-500">
                        <p>
                            Bank and e-wallet balances are the ones you entered, plus sales received through a linked payment channel since then;
                            there is no live bank feed.
                        </p>
                        <p v-if="bs.accounts_missing.length" class="mt-1">
                            No balance entered yet for: {{ bs.accounts_missing.join(", ") }}.
                        </p>
                        <p v-if="bs.cash_locations_counted === 0" class="mt-1">
                            No store has recorded today's opening cash, so cash in drawers shows ₱0.00.
                        </p>
                    </div>
                </div>
            </template>
        </ContentLayout>
    </AuthenticatedLayout>
</template>
