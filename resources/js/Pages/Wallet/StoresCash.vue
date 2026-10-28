<script setup>
import { computed, reactive } from "vue";
import { Head, router } from "@inertiajs/vue3";
import { useMediaQuery } from "@vueuse/core";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import ContentHeader from "@/Components/ContentHeader.vue";
import ContentLayout from "@/Components/ContentLayout.vue";
import AllStoresTable from "@/Pages/Wallet/components/balances/AllStoresTable.vue";
import { useDomainRoutes } from "@/Composables/useDomainRoutes";
import { useHelpers } from "@/Composables/useHelpers";

/**
 * The warehouse's Cash & Wallet: each store's closed shifts with their last count and the cash
 * taken out at end of shift. Read-only; stores record these on their own Today's drawer page.
 */
const props = defineProps({
    storesCash: { type: Object, required: true },
    /** Owners and admins: where each store's money is right now. */
    allStores: { type: Object, default: null },
    /** May start a shift: shifts run at a store, so they're told to switch to one. */
    canStartShift: { type: Boolean, default: false },
});

const { getRoute } = useDomainRoutes();
const { formattedTotal } = useHelpers();
const isMdUp = useMediaQuery("(min-width: 768px)");

const filters = reactive({
    date_from: props.storesCash.filters?.date_from ?? "",
    date_to: props.storesCash.filters?.date_to ?? "",
});

const apply = () =>
    router.get(getRoute("wallet.drawer"), { ...filters }, { preserveScroll: true, preserveState: true });

const money = (v) => (v === null || v === undefined ? "—" : formattedTotal(Number(v)));

const columns = [
    { title: "Date", dataIndex: "business_date", key: "business_date" },
    { title: "Store", dataIndex: "store", key: "store" },
    { title: "Counted", key: "counted_cash", align: "right" },
    { title: "Expected", key: "expected_cash", align: "right" },
    { title: "Difference", key: "variance", align: "right" },
    { title: "Cash out", key: "cash_out", align: "right" },
    { title: "Closed by", key: "closed_by" },
];

const rows = computed(() => props.storesCash.rows ?? []);
const totals = computed(() => props.storesCash.totals ?? {});
</script>

<template>
    <Head title="Stores' cash" />
    <AuthenticatedLayout>
        <ContentHeader class="mb-4" title="Stores' cash" />

        <a-alert
            v-if="canStartShift"
            type="info"
            show-icon
            class="mb-4"
            data-testid="warehouse-shift-hint"
            message="You're viewing the warehouse, which has no cash drawer. To start your shift, switch to a store with the store picker at the top right."
        />

        <div class="mb-4 grid grid-cols-2 gap-3 md:grid-cols-4" data-testid="stores-cash-totals">
            <div class="rounded-lg border border-gray-200 bg-white px-4 py-3 shadow-sm">
                <div class="text-xs uppercase text-gray-500">Closed shifts</div>
                <div class="text-lg font-bold">{{ totals.shifts ?? 0 }}</div>
            </div>
            <div class="rounded-lg border border-gray-200 bg-white px-4 py-3 shadow-sm">
                <div class="text-xs uppercase text-gray-500">Counted</div>
                <div class="font-mono text-lg font-bold">{{ money(totals.counted) }}</div>
            </div>
            <div class="rounded-lg border border-gray-200 bg-white px-4 py-3 shadow-sm">
                <div class="text-xs uppercase text-gray-500">Cash out</div>
                <div class="font-mono text-lg font-bold">{{ money(totals.cash_out) }}</div>
            </div>
            <div class="rounded-lg border border-gray-200 bg-white px-4 py-3 shadow-sm">
                <div class="text-xs uppercase text-gray-500">Difference</div>
                <div class="font-mono text-lg font-bold" :class="(totals.variance ?? 0) < 0 ? 'text-red-700' : 'text-gray-900'">
                    {{ money(totals.variance) }}
                </div>
            </div>
        </div>

        <div v-if="allStores" class="mb-6">
            <h3 class="mb-2 text-base font-semibold text-gray-800">Money in each store now</h3>
            <AllStoresTable :all-stores="allStores" />
        </div>

        <ContentLayout title="Closed shifts by store">
            <template #filters>
                <div class="flex flex-wrap items-end gap-2">
                    <div>
                        <label class="mb-1 block text-xs text-gray-600">From</label>
                        <input v-model="filters.date_from" type="date" class="rounded border border-gray-300 px-2 py-1 text-sm" />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs text-gray-600">To</label>
                        <input v-model="filters.date_to" type="date" class="rounded border border-gray-300 px-2 py-1 text-sm" />
                    </div>
                    <a-button type="primary" @click="apply">Apply</a-button>
                </div>
            </template>
            <template #table>
                <p class="mb-3 px-1 text-xs text-gray-500">
                    What each store counted when it closed its shift, and the cash it took out. Stores record these on their own Today's drawer page.
                </p>
                <a-table
                    v-if="isMdUp"
                    :columns="columns"
                    :data-source="rows"
                    row-key="id"
                    :pagination="{ pageSize: 20 }"
                    :locale="{ emptyText: 'No closed shifts in this period.' }"
                    data-testid="stores-cash-table"
                >
                    <template #bodyCell="{ column, record }">
                        <template v-if="column.key === 'counted_cash'">{{ money(record.counted_cash) }}</template>
                        <template v-else-if="column.key === 'expected_cash'">{{ money(record.expected_cash) }}</template>
                        <template v-else-if="column.key === 'variance'">
                            <span :class="(record.variance ?? 0) < 0 ? 'text-red-700' : ''">{{ money(record.variance) }}</span>
                        </template>
                        <template v-else-if="column.key === 'cash_out'">{{ money(record.cash_out) }}</template>
                        <template v-else-if="column.key === 'closed_by'">
                            {{ record.closed_by || "—" }}
                            <div v-if="record.counted_by" class="text-xs text-gray-500">Counted by {{ record.counted_by }}</div>
                        </template>
                    </template>
                </a-table>
                <div v-else class="flex flex-col gap-3">
                    <p v-if="!rows.length" class="py-8 text-center text-sm text-gray-500">No closed shifts in this period.</p>
                    <div v-for="record in rows" :key="record.id" class="rounded-lg border border-gray-200 bg-white p-3 text-sm shadow-sm">
                        <div class="flex justify-between font-medium"><span>{{ record.store }}</span><span>{{ record.business_date }}</span></div>
                        <div class="mt-2 grid grid-cols-2 gap-1">
                            <span class="text-gray-500">Counted</span><span class="text-right">{{ money(record.counted_cash) }}</span>
                            <span class="text-gray-500">Expected</span><span class="text-right">{{ money(record.expected_cash) }}</span>
                            <span class="text-gray-500">Difference</span><span class="text-right">{{ money(record.variance) }}</span>
                            <span class="text-gray-500">Cash out</span><span class="text-right">{{ money(record.cash_out) }}</span>
                            <span class="text-gray-500">Closed by</span><span class="text-right">{{ record.closed_by || "—" }}</span>
                        </div>
                    </div>
                </div>
            </template>
        </ContentLayout>
    </AuthenticatedLayout>
</template>
