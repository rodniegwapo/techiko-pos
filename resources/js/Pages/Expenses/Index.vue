<script setup>
import { computed, ref } from "vue";
import { Head, router } from "@inertiajs/vue3";
import { watchDebounced } from "@vueuse/core";
import dayjs from "dayjs";
import { PlusSquareOutlined } from "@ant-design/icons-vue";
import { IconCash, IconCategory, IconListNumbers, IconCalendarEvent } from "@tabler/icons-vue";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import ContentHeader from "@/Components/ContentHeader.vue";
import ContentLayout from "@/Components/ContentLayout.vue";
import RefreshButton from "@/Components/buttons/Refresh.vue";
import FilterDropdown from "@/Components/filters/FilterDropdown.vue";
import ActiveFilters from "@/Components/filters/ActiveFilters.vue";
import ExpenseTable from "./components/ExpenseTable.vue";
import ExpenseModal from "./components/ExpenseModal.vue";
import CategoryManagerModal from "./components/CategoryManagerModal.vue";
import RecurringExpenseModal from "./components/RecurringExpenseModal.vue";
import RecurringTable from "./components/RecurringTable.vue";
import { paymentMethodLabel } from "./paymentMethods";
import { useDomainRoutes } from "@/Composables/useDomainRoutes";
import { useGlobalVariables } from "@/Composables/useGlobalVariable";
import { useHelpers } from "@/Composables/useHelpers";
import { usePermissionsV2 } from "@/Composables/usePermissionV2";
import { useFilters, toLabel } from "@/Composables/useFilters";

const props = defineProps({
    items: { type: Object, default: () => ({ data: [] }) },
    filters: { type: Object, default: () => ({}) },
    summary: { type: Object, default: () => ({}) },
    options: { type: Object, default: () => ({}) },
    canSeeAllStores: { type: Boolean, default: false },
    restrictedLocationId: { type: Number, default: null },
    activeLocationId: { type: Number, default: null },
    recurring: { type: Array, default: () => [] },
});

const { getRoute } = useDomainRoutes();
const { spinning } = useGlobalVariables();
const { formattedTotal } = useHelpers();
const { hasPermission } = usePermissionsV2();

/*** === FILTER STATE (seeded from the server's resolved filters) === ***/
// Keys differ from the query param names on purpose: useFilters seeds any key it finds in the URL
// as a string, which would not match the numeric ids the selects use.
const dateRange = ref([dayjs(props.filters.start_date), dayjs(props.filters.end_date)]);
const location = ref(props.filters.location ?? null);
const category = ref(props.filters.category_id ?? null);
const payment = ref(props.filters.payment_method ?? null);
const search = ref(props.filters.search ?? "");

const locationOptions = computed(() => [
    ...(props.canSeeAllStores ? [{ value: "business", label: "Business-wide" }] : []),
    ...(props.options.locations || []).map((l) => ({ value: l.id, label: l.name })),
]);
const categoryOptions = computed(() =>
    (props.options.categories || []).map((c) => ({ value: c.id, label: c.name })),
);
const paymentOptions = computed(() =>
    (props.options.payment_methods || []).map((m) => ({ value: m, label: paymentMethodLabel(m) })),
);

const queryParams = (extra = {}) => ({
    start_date: dateRange.value?.[0] ? dayjs(dateRange.value[0]).format("YYYY-MM-DD") : undefined,
    end_date: dateRange.value?.[1] ? dayjs(dateRange.value[1]).format("YYYY-MM-DD") : undefined,
    location: location.value || undefined,
    category_id: category.value || undefined,
    payment_method: payment.value || undefined,
    search: search.value || undefined,
    per_page: props.items.per_page,
    ...extra,
});

const load = (extra = {}) => {
    router.get(getRoute("expenses.index"), queryParams({ page: 1, ...extra }), {
        preserveState: true,
        preserveScroll: true,
        replace: true,
        onStart: () => (spinning.value = true),
        onFinish: () => (spinning.value = false),
    });
};

watchDebounced(search, () => load(), { debounce: 400 });

const rangeLabel = (v) =>
    Array.isArray(v) && v[0] && v[1]
        ? `${dayjs(v[0]).format("MMM D, YYYY")} – ${dayjs(v[1]).format("MMM D, YYYY")}`
        : null;

const filterConfigs = [
    { label: "Date", key: "date", ref: dateRange, getLabel: rangeLabel },
    ...(props.canSeeAllStores
        ? [{ label: "Store", key: "store", ref: location, getLabel: toLabel(locationOptions) }]
        : []),
    { label: "Category", key: "category", ref: category, getLabel: toLabel(categoryOptions) },
    { label: "Paid from", key: "payment", ref: payment, getLabel: toLabel(paymentOptions) },
];

const { filters, activeFilters, handleClearSelectedFilter } = useFilters({
    getItems: () => load(),
    configs: filterConfigs,
});

const filtersConfig = computed(() => [
    { key: "date", label: "Date", type: "range" },
    ...(props.canSeeAllStores
        ? [{ key: "store", label: "Store", type: "select", options: locationOptions.value }]
        : []),
    { key: "category", label: "Category", type: "select", options: categoryOptions.value },
    { key: "payment", label: "Paid from", type: "select", options: paymentOptions.value },
]);

/** The period the server actually applied (this month when no date is chosen). */
const periodLabel = computed(() => {
    const start = dayjs(props.filters.start_date);
    const end = dayjs(props.filters.end_date);
    return start.isSame(end, "day") ? start.format("MMM D, YYYY") : `${start.format("MMM D, YYYY")} – ${end.format("MMM D, YYYY")}`;
});

/*** === SUMMARY === ***/
const topCategory = computed(() => props.summary.by_category?.[0] ?? null);
const summaryCards = computed(() => [
    {
        key: "total",
        label: "Total expenses",
        value: formattedTotal(props.summary.total ?? 0),
        hint: periodLabel.value,
        icon: IconCash,
        tone: "bg-rose-50 text-rose-600",
    },
    {
        key: "top",
        label: "Biggest category",
        value: topCategory.value?.name ?? "—",
        hint: topCategory.value ? formattedTotal(topCategory.value.total) : "No expenses yet",
        icon: IconCategory,
        tone: "bg-amber-50 text-amber-600",
    },
    {
        key: "count",
        label: "Expenses recorded",
        value: (props.summary.count ?? 0).toLocaleString(),
        hint: "In this period",
        icon: IconListNumbers,
        tone: "bg-blue-50 text-blue-600",
    },
]);

const categoryShare = (total) => (props.summary.total > 0 ? Math.round((total / props.summary.total) * 100) : 0);

/*** === TABLE === ***/
const pagination = computed(() => ({
    total: props.items.total ?? 0,
    current: props.items.current_page ?? 1,
    pageSize: props.items.per_page ?? 20,
    showSizeChanger: true,
    pageSizeOptions: ["20", "50", "100"],
    showTotal: (total, range) => `${range[0]}-${range[1]} of ${total} expenses`,
}));

const handleTableChange = (p) => load({ page: p.current, per_page: p.pageSize });

/*** === MODALS === ***/
const modalOpen = ref(false);
const editing = ref(null);
const categoriesOpen = ref(false);
const activeTab = ref("expenses");
const recurringOpen = ref(false);
const editingRecurring = ref(null);

const openCreate = () => {
    editing.value = null;
    modalOpen.value = true;
};
const openEdit = (record) => {
    editing.value = record;
    modalOpen.value = true;
};
const openRecurring = (record) => {
    editingRecurring.value = record;
    recurringOpen.value = true;
};

/*** === EXPORT === ***/
const exportUrl = computed(() => {
    const base = getRoute("expenses.export");
    const params = Object.fromEntries(
        Object.entries(queryParams()).filter(([k, v]) => v !== undefined && v !== "" && k !== "per_page"),
    );
    return `${base}?${new URLSearchParams(params).toString()}`;
});
</script>

<template>
    <AuthenticatedLayout>
        <Head title="Expenses" />
        <ContentHeader class="mb-4 md:mb-6" title="Expenses" />
        <ContentLayout
            title="Business expenses"
            filter-class="flex flex-wrap items-center justify-end gap-2 w-full min-w-0"
        >
            <template #filters>
                <refresh-button :loading="spinning" @click="load({ page: pagination.current })" />
                <a-input-search
                    v-model:value="search"
                    placeholder="Description, payee or ref."
                    allow-clear
                    class="w-full min-w-0 md:max-w-[260px]"
                />
                <a-button
                    v-if="hasPermission('expenses.store')"
                    type="primary"
                    class="flex w-full items-center justify-center border border-green-500 bg-white text-green-500 md:inline-flex md:w-auto"
                    data-testid="expenses-create"
                    @click="openCreate"
                >
                    <template #icon>
                        <PlusSquareOutlined />
                    </template>
                    Record Expense
                </a-button>
                <a v-if="hasPermission('expenses.export')" :href="exportUrl" data-testid="expenses-export">
                    <a-button>Export CSV</a-button>
                </a>
                <FilterDropdown v-model="filters" :filters="filtersConfig" />
            </template>

            <template #activeFilters>
                <ActiveFilters
                    :filters="activeFilters"
                    @remove-filter="handleClearSelectedFilter"
                    @clear-all="() => Object.keys(filters).forEach((k) => (filters[k] = null))"
                />
            </template>

            <template #table>
                <div class="space-y-4 px-4 pb-6 md:px-6">
                    <div class="flex items-center gap-2 text-sm text-gray-600">
                        <IconCalendarEvent :size="18" class="text-gray-400" />
                        <span data-testid="expenses-period">{{ periodLabel }}</span>
                    </div>

                    <div class="grid grid-cols-1 gap-3 md:grid-cols-3">
                        <div
                            v-for="card in summaryCards"
                            :key="card.key"
                            :data-testid="`expenses-summary-${card.key}`"
                            class="flex items-start gap-3 rounded-xl border border-gray-200 bg-white p-4 shadow-sm transition-shadow hover:shadow-md"
                        >
                            <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg" :class="card.tone">
                                <component :is="card.icon" :size="24" :stroke-width="1.75" />
                            </div>
                            <div class="min-w-0">
                                <div class="text-xs font-medium uppercase tracking-wide text-gray-500">{{ card.label }}</div>
                                <div class="mt-0.5 truncate text-xl font-semibold text-gray-900">{{ card.value }}</div>
                                <div class="mt-0.5 truncate text-xs text-gray-500">{{ card.hint }}</div>
                            </div>
                        </div>
                    </div>

                    <div v-if="summary.by_category?.length" class="rounded-xl border border-gray-200 bg-white p-4">
                        <div class="mb-3 text-sm font-semibold text-gray-900">Where the money went</div>
                        <div class="space-y-2">
                            <div v-for="row in summary.by_category" :key="row.name" class="text-sm">
                                <div class="flex justify-between">
                                    <span>{{ row.name }}</span>
                                    <span class="text-gray-600">
                                        {{ formattedTotal(row.total) }}
                                        <span class="text-xs text-gray-400">({{ categoryShare(row.total) }}%)</span>
                                    </span>
                                </div>
                                <div class="mt-1 h-1.5 rounded-full bg-gray-100">
                                    <div class="h-1.5 rounded-full bg-rose-400" :style="{ width: `${categoryShare(row.total)}%` }" />
                                </div>
                            </div>
                        </div>
                    </div>

                    <a-tabs v-model:activeKey="activeTab" class="expenses-tabs">
                        <a-tab-pane key="expenses" tab="Expenses">
                            <ExpenseTable
                                :expenses="items.data"
                                :pagination="pagination"
                                @handle-table-change="handleTableChange"
                                @edit="openEdit"
                            />
                        </a-tab-pane>

                        <a-tab-pane key="recurring" :tab="`Recurring (${recurring.length})`">
                            <RecurringTable
                                :items="recurring"
                                :can-create="hasPermission('expenses.recurring.store')"
                                :can-update="hasPermission('expenses.recurring.update')"
                                :can-delete="hasPermission('expenses.recurring.destroy')"
                                @create="openRecurring(null)"
                                @edit="openRecurring"
                            />
                        </a-tab-pane>
                    </a-tabs>
                </div>
            </template>
        </ContentLayout>

        <ExpenseModal
            :open="modalOpen"
            :expense="editing"
            :options="options"
            :can-see-all-stores="canSeeAllStores"
            :restricted-location-id="restrictedLocationId"
            :active-location-id="activeLocationId"
            @close="modalOpen = false"
            @manage-categories="categoriesOpen = true"
        />
        <RecurringExpenseModal
            :open="recurringOpen"
            :recurring="editingRecurring"
            :options="options"
            :can-see-all-stores="canSeeAllStores"
            :restricted-location-id="restrictedLocationId"
            :active-location-id="activeLocationId"
            @close="recurringOpen = false"
        />
        <CategoryManagerModal
            :open="categoriesOpen"
            :categories="options.categories || []"
            @close="categoriesOpen = false"
        />
    </AuthenticatedLayout>
</template>

<style scoped>
/* Tabs in the app's green rather than the ant default blue. */
.expenses-tabs :deep(.ant-tabs-tab.ant-tabs-tab-active .ant-tabs-tab-btn),
.expenses-tabs :deep(.ant-tabs-tab:hover) {
    color: #287e47;
}
.expenses-tabs :deep(.ant-tabs-ink-bar) {
    background: #287e47;
}
</style>
