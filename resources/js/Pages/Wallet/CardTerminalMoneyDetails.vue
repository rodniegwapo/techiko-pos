<script setup>
import { ref, computed, onMounted, watch } from "vue";
import { router, usePage } from "@inertiajs/vue3";
import axios from "axios";
import { notification } from "ant-design-vue";

import WalletShell from "@/Pages/Wallet/WalletShell.vue";
import { useDomainRoutes } from "@/Composables/useDomainRoutes";
import { useHelpers } from "@/Composables/useHelpers";

const { getRoute } = useDomainRoutes();
const { formattedTotal } = useHelpers();

const page = usePage();
const todayYmd = new Date().toISOString().slice(0, 10);

function firstValidationMessage(err) {
    const errors = err?.response?.data?.errors;
    if (errors && typeof errors === "object") {
        const first = Object.values(errors)[0];
        if (Array.isArray(first) && first.length) return first[0];
    }
    return err?.response?.data?.message || err?.message || null;
}

const props = defineProps({
    cardTypes: {
        type: Array,
        default: () => [],
    },
    walletCashTotals: {
        type: Object,
        default: () => ({ today_total: 0, yesterday_total: 0 }),
    },
    walletCreditTotals: {
        type: Object,
        default: () => ({ today_total: 0, yesterday_total: 0 }),
    },
    ledger: {
        type: Object,
        default: null,
    },
    runningCashBalance: {
        type: Number,
        default: null,
    },
    activeLocation: {
        type: Object,
        default: () => null,
    },
    cashControl: {
        type: Object,
        default: () => null,
    },
    walletPageMode: {
        type: String,
        default: "card-types",
    },
    canViewMoneyMovement: {
        type: Boolean,
        default: false,
    },
    canViewCardTypes: {
        type: Boolean,
        default: true,
    },
    moneyDetailsCardType: {
        type: Object,
        required: true,
    },
});

/** @param {string} url */
function queryObjectFromPageUrl(url) {
    if (!url || typeof url !== "string") {
        return {};
    }
    const idx = url.indexOf("?");
    if (idx === -1) {
        return {};
    }
    return Object.fromEntries(new URLSearchParams(url.slice(idx + 1)));
}

const activeLocationId = computed(() => {
    const q = queryObjectFromPageUrl(page.url);
    if (q.location_id && /^[0-9]+$/.test(String(q.location_id))) {
        return Number(q.location_id);
    }

    const fromWallet = props.activeLocation?.id;
    if (fromWallet != null) {
        return Number(fromWallet);
    }

    const fromShared = page.props?.currentLocation?.id;
    if (fromShared != null) {
        return Number(fromShared);
    }

    return null;
});

const activeBusinessDate = computed(() => {
    const q = queryObjectFromPageUrl(page.url);
    if (q.business_date) {
        return String(q.business_date);
    }

    return (
        props.cashControl?.business_date ||
        new Date().toISOString().slice(0, 10)
    );
});

const businessDateInput = ref("");

watch(
    () => activeBusinessDate.value,
    (v) => {
        businessDateInput.value = v;
    },
    { immediate: true },
);

const moneyLoading = ref(false);
const historyRows = ref([]);
const historySearch = ref("");
const historyDateFrom = ref("");
const historyDateTo = ref("");
const historyPagination = ref({
    current: 1,
    pageSize: 20,
    total: 0,
});

const moneyHistoryColumns = [
    {
        title: "Invoice",
        dataIndex: "invoice_number",
        key: "invoice_number",
        width: 130,
    },
    { title: "Date", key: "date", width: 200 },
    { title: "Time", key: "time", width: 120 },
    {
        title: "Amount",
        dataIndex: "grand_total",
        key: "grand_total",
        align: "right",
    },
];

function formatHistoryDate(iso) {
    if (!iso) return "—";
    try {
        return new Date(iso).toLocaleDateString(undefined, {
            weekday: "short",
            year: "numeric",
            month: "short",
            day: "numeric",
        });
    } catch {
        return "—";
    }
}

function formatHistoryTime(iso) {
    if (!iso) return "—";
    try {
        return new Date(iso).toLocaleTimeString(undefined, {
            hour: "2-digit",
            minute: "2-digit",
        });
    } catch {
        return "—";
    }
}

async function loadMoneyDetails(p = 1) {
    if (!props.moneyDetailsCardType?.id) return;
    moneyLoading.value = true;
    try {
        const params = {
            page: p,
            per_page: historyPagination.value.pageSize,
            location_id: activeLocationId.value,
            business_date: activeBusinessDate.value,
        };
        const q = String(historySearch.value || "").trim();
        if (q) {
            params.search = q;
        }
        if (historyDateFrom.value) {
            params.history_from = historyDateFrom.value;
        }
        if (historyDateTo.value) {
            params.history_to = historyDateTo.value;
        }
        const { data } = await axios.get(
            getRoute("payment-card-types.money", {
                paymentCardType: props.moneyDetailsCardType.id,
            }),
            { params },
        );
        const h = data.history;
        historyRows.value = h?.data ?? [];
        historyPagination.value = {
            current: h?.current_page ?? 1,
            pageSize: h?.per_page ?? 20,
            total: h?.total ?? 0,
        };
    } catch (e) {
        notification.error({
            message:
                firstValidationMessage(e) || "Could not load money details.",
        });
    } finally {
        moneyLoading.value = false;
    }
}

function reloadDetailsWithBusinessDate() {
    const q = {};
    if (activeLocationId.value != null) {
        q.location_id = activeLocationId.value;
    }
    q.business_date =
        businessDateInput.value || activeBusinessDate.value || todayYmd;
    router.get(
        getRoute("payment-card-types.details", {
            paymentCardType: props.moneyDetailsCardType.id,
        }),
        q,
        {
            preserveScroll: true,
            onSuccess: () => {
                loadMoneyDetails(1);
            },
        },
    );
}

function onMoneyTableChange(pag) {
    if (pag?.current) {
        loadMoneyDetails(pag.current);
    }
}

function onHistorySearch() {
    loadMoneyDetails(1);
}

function clearHistoryFilters() {
    historySearch.value = "";
    historyDateFrom.value = "";
    historyDateTo.value = "";
    loadMoneyDetails(1);
}

function goToCardTerminalsIndex() {
    const q = {};
    if (activeLocationId.value != null) {
        q.location_id = activeLocationId.value;
    }
    if (activeBusinessDate.value) {
        q.business_date = activeBusinessDate.value;
    }
    router.get(getRoute("payment-card-types.index"), q);
}

onMounted(() => {
    loadMoneyDetails(1);
});
</script>

<template>
    <WalletShell v-bind="props" :is-money-movement-page="false">
        <template #primary>
            <div class="mt-6 max-w-7xl space-y-4">
                <a-breadcrumb>
                    <a-breadcrumb-item>
                        <a
                            class="text-teal-700 cursor-pointer"
                            @click.prevent="goToCardTerminalsIndex"
                            >Card terminals</a
                        >
                    </a-breadcrumb-item>
                    <a-breadcrumb-item>{{
                        moneyDetailsCardType.name
                    }}</a-breadcrumb-item>
                </a-breadcrumb>

                <div
                    class="flex flex-wrap items-end gap-2 rounded-lg border border-gray-200 bg-white px-3 py-3 shadow-sm"
                >
                    <div class="w-full max-w-[14rem] sm:w-[14rem]">
                        <label
                            class="mb-1 block text-xs font-medium text-gray-600"
                        >
                            Business date
                        </label>
                        <div class="flex gap-2">
                            <input
                                v-model="businessDateInput"
                                type="date"
                                :max="todayYmd"
                                class="w-full rounded border border-gray-300 px-2 py-2 text-sm"
                            />
                            <a-button
                                type="primary"
                                @click="reloadDetailsWithBusinessDate"
                            >
                                Load
                            </a-button>
                        </div>
                    </div>
                </div>

                <a-spin :spinning="moneyLoading">
                    <div
                        class="rounded-lg border border-gray-200 bg-white px-4 py-4 shadow-sm"
                    >
                        <div
                            class="text-sm font-medium text-gray-700 mb-2"
                        >
                            Transaction history (paid card sales)
                        </div>
                        <div
                            class="flex flex-col sm:flex-row flex-wrap gap-2 mb-3"
                        >
                            <a-input-search
                                v-model:value="historySearch"
                                placeholder="Search invoice"
                                allow-clear
                                style="
                                    width: 100%;
                                    max-width: 280px;
                                    min-width: 160px;
                                "
                                @search="onHistorySearch"
                            />
                            <div class="flex flex-wrap items-center gap-2">
                                <label
                                    class="flex items-center gap-1 text-xs text-gray-600"
                                >
                                    <span class="whitespace-nowrap">From</span>
                                    <input
                                        v-model="historyDateFrom"
                                        type="date"
                                        class="rounded border border-gray-300 px-2 py-1 text-sm text-gray-900"
                                    />
                                </label>
                                <label
                                    class="flex items-center gap-1 text-xs text-gray-600"
                                >
                                    <span class="whitespace-nowrap">To</span>
                                    <input
                                        v-model="historyDateTo"
                                        type="date"
                                        class="rounded border border-gray-300 px-2 py-1 text-sm text-gray-900"
                                    />
                                </label>
                                <a-button
                                    type="primary"
                                    size="small"
                                    @click="onHistorySearch"
                                >
                                    Apply
                                </a-button>
                                <a-button
                                    size="small"
                                    @click="clearHistoryFilters"
                                >
                                    Clear
                                </a-button>
                            </div>
                        </div>
                        <a-table
                            size="small"
                            :columns="moneyHistoryColumns"
                            :data-source="historyRows"
                            :pagination="{
                                current: historyPagination.current,
                                pageSize: historyPagination.pageSize,
                                total: historyPagination.total,
                                showSizeChanger: false,
                                hideOnSinglePage: false,
                            }"
                            row-key="id"
                            :locale="{
                                emptyText:
                                    'No transactions for this card type yet.',
                            }"
                            @change="onMoneyTableChange"
                        >
                            <template #bodyCell="{ column, record }">
                                <template v-if="column.key === 'date'">
                                    {{
                                        formatHistoryDate(
                                            record.transaction_date,
                                        )
                                    }}
                                </template>
                                <template v-else-if="column.key === 'time'">
                                    {{
                                        formatHistoryTime(
                                            record.transaction_date,
                                        )
                                    }}
                                </template>
                                <template
                                    v-else-if="column.key === 'grand_total'"
                                >
                                    {{
                                        formattedTotal(record.grand_total)
                                    }}
                                </template>
                            </template>
                        </a-table>
                    </div>
                </a-spin>
            </div>
        </template>
    </WalletShell>
</template>
