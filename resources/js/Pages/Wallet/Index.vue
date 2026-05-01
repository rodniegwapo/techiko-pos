<script setup>
import { ref, computed } from "vue";
import { Head, router, usePage } from "@inertiajs/vue3";
import {
    IconPlus,
    IconReportMoney,
    IconEdit,
    IconTrash,
} from "@tabler/icons-vue";
import axios from "axios";
import { notification } from "ant-design-vue";

import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import ContentHeader from "@/Components/ContentHeader.vue";
import ContentLayout from "@/Components/ContentLayout.vue";
import CashLedgerPanel from "@/Pages/Wallet/partials/CashLedgerPanel.vue";
import IconTooltipButton from "@/Components/buttons/IconTooltip.vue";
import { useDomainRoutes } from "@/Composables/useDomainRoutes";
import { usePermissionsV2 } from "@/Composables/usePermissionV2";
import { useHelpers } from "@/Composables/useHelpers";

const { getRoute } = useDomainRoutes();
const { hasPermission } = usePermissionsV2();
const { formattedTotal } = useHelpers();

const page = usePage();

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
});

const activeWalletTab = computed(() => {
    const q = queryObjectFromPageUrl(page.url);
    const tab = q.tab;
    if (!props.ledger) {
        return "card-types";
    }
    if (tab === "card-types") {
        return "card-types";
    }
    return "ledger";
});

function onWalletTabChange(key) {
    const base = queryObjectFromPageUrl(page.url);
    base.tab = key;
    router.get(getRoute("payment-card-types.index"), base, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
}

const rows = computed(() => props.cardTypes ?? []);

const modalOpen = ref(false);
const editing = ref(null);
const formName = ref("");
const formActive = ref(true);
const saving = ref(false);

function openCreate() {
    editing.value = null;
    formName.value = "";
    formActive.value = true;
    modalOpen.value = true;
}

function openEdit(row) {
    editing.value = row;
    formName.value = row.name;
    formActive.value = !!row.is_active;
    modalOpen.value = true;
}

function closeModal() {
    modalOpen.value = false;
    editing.value = null;
}

async function save() {
    const name = String(formName.value || "").trim();
    if (!name) {
        notification.warning({ message: "Name is required." });
        return;
    }
    saving.value = true;
    try {
        if (editing.value?.id) {
            await axios.put(
                getRoute("payment-card-types.update", {
                    paymentCardType: editing.value.id,
                }),
                {
                    name,
                    is_active: formActive.value,
                },
            );
            notification.success({ message: "Card type updated." });
        } else {
            await axios.post(getRoute("payment-card-types.store"), {
                name,
                sort_order: 0,
            });
            notification.success({ message: "Card type created." });
        }
        closeModal();
        router.reload({
            only: [
                "cardTypes",
                "walletCashTotals",
                "walletCreditTotals",
                "ledger",
                "runningCashBalance",
            ],
        });
    } catch (e) {
        const msg = firstValidationMessage(e) || "Could not save card type.";
        notification.error({ message: msg });
    } finally {
        saving.value = false;
    }
}

const deletingId = ref(null);

async function remove(row) {
    deletingId.value = row.id;
    try {
        await axios.delete(
            getRoute("payment-card-types.destroy", {
                paymentCardType: row.id,
            }),
        );
        notification.success({ message: "Done." });
        router.reload({
            only: [
                "cardTypes",
                "walletCashTotals",
                "walletCreditTotals",
                "ledger",
                "runningCashBalance",
            ],
        });
    } catch (e) {
        const msg = firstValidationMessage(e) || "Could not remove card type.";
        notification.error({ message: msg });
    } finally {
        deletingId.value = null;
    }
}

const columns = [
    { title: "Name", dataIndex: "name", key: "name" },
    {
        title: "Status",
        key: "is_active",
        width: 100,
    },
    { title: "", key: "actions", width: 220 },
];

const moneyModalVisible = ref(false);
const moneyDetailsType = ref(null);
const moneyLoading = ref(false);
const todayTotal = ref(0);
const yesterdayTotal = ref(0);
const historyRows = ref([]);
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

async function loadMoneyDetails(page = 1) {
    if (!moneyDetailsType.value?.id) return;
    moneyLoading.value = true;
    try {
        const { data } = await axios.get(
            getRoute("payment-card-types.money", {
                paymentCardType: moneyDetailsType.value.id,
            }),
            { params: { page, per_page: historyPagination.value.pageSize } },
        );
        todayTotal.value = Number(data.today_total) || 0;
        yesterdayTotal.value = Number(data.yesterday_total) || 0;
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

function openMoneyDetails(record) {
    moneyDetailsType.value = record;
    moneyModalVisible.value = true;
    loadMoneyDetails(1);
}

function closeMoneyModal() {
    moneyModalVisible.value = false;
    moneyDetailsType.value = null;
    historyRows.value = [];
    todayTotal.value = 0;
    yesterdayTotal.value = 0;
}

function onMoneyTableChange(pag) {
    if (pag?.current) {
        loadMoneyDetails(pag.current);
    }
}
</script>

<template>
    <AuthenticatedLayout>
        <Head title="Payment wallet" />
        <ContentHeader class="mb-8" title="Payment wallet" />

        <div
            class="mb-6 grid max-w-7xl grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3"
        >
            <div
                class="rounded-lg border border-gray-200 bg-white px-4 py-4 shadow-sm"
            >
                <div class="text-base font-semibold text-gray-900">
                    Paid cash sales
                </div>
                <div class="mb-3 text-xs text-gray-500">
                    Paid cash sales (by transaction date)
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div
                        class="rounded-lg border border-gray-200 bg-gray-50 px-3 py-2"
                    >
                        <div class="text-xs uppercase text-gray-500">Today</div>
                        <div class="text-lg font-semibold text-green-700">
                            {{
                                formattedTotal(
                                    Number(walletCashTotals.today_total) || 0,
                                )
                            }}
                        </div>
                    </div>
                    <div
                        class="rounded-lg border border-gray-200 bg-gray-50 px-3 py-2"
                    >
                        <div class="text-xs uppercase text-gray-500">
                            Yesterday
                        </div>
                        <div class="text-lg font-semibold text-gray-800">
                            {{
                                formattedTotal(
                                    Number(walletCashTotals.yesterday_total) ||
                                        0,
                                )
                            }}
                        </div>
                    </div>
                </div>
                <div
                    v-if="ledger"
                    class="mt-4 border-t border-gray-100 pt-3 text-xs leading-relaxed text-gray-600"
                >
                    <span class="font-medium text-gray-700"
                        >Manual ledger net (today)</span
                    >
                    —
                    <span class="tabular-nums font-semibold text-gray-900">{{
                        formattedTotal(Number(ledger.todayManualNet) || 0)
                    }}</span>
                    <span class="mt-1 block text-[11px] text-gray-500">
                        Ledger lines for today's calendar date only; not the
                        same as paid cash sales above unless you reconcile.
                    </span>
                </div>
            </div>
            <div
                class="rounded-lg border border-gray-200 bg-white px-4 py-4 shadow-sm"
            >
                <div class="text-base font-semibold text-gray-900">Credit</div>
                <div class="mb-3 text-xs text-gray-500">
                    Paid credit sales (charge to account)
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div
                        class="rounded-lg border border-gray-200 bg-gray-50 px-3 py-2"
                    >
                        <div class="text-xs uppercase text-gray-500">Today</div>
                        <div class="text-lg font-semibold text-green-700">
                            {{
                                formattedTotal(
                                    Number(walletCreditTotals.today_total) || 0,
                                )
                            }}
                        </div>
                    </div>
                    <div
                        class="rounded-lg border border-gray-200 bg-gray-50 px-3 py-2"
                    >
                        <div class="text-xs uppercase text-gray-500">
                            Yesterday
                        </div>
                        <div class="text-lg font-semibold text-gray-800">
                            {{
                                formattedTotal(
                                    Number(
                                        walletCreditTotals.yesterday_total,
                                    ) || 0,
                                )
                            }}
                        </div>
                    </div>
                </div>
            </div>

            <div
                v-if="ledger && runningCashBalance !== null"
                class="rounded-lg border border-gray-200 bg-white px-4 py-4 shadow-sm"
            >
                <div class="text-base font-semibold text-gray-900">
                    Running cash balance
                </div>
                <div class="mb-3 text-xs text-gray-500">
                    Manual ledger running net (all time): cash in minus cash out
                </div>
                <div
                    class="text-2xl font-bold tabular-nums"
                    :class="
                        Number(runningCashBalance) >= 0
                            ? 'text-green-700'
                            : 'text-red-700'
                    "
                >
                    {{ formattedTotal(Number(runningCashBalance) || 0) }}
                </div>
                <p class="mt-2 text-xs text-gray-500">
                    Includes owner withdrawals and adjustments from Money
                    Movement.
                </p>
            </div>
        </div>

        <div class="mt-10 max-w-7xl">
            <a-tabs
                v-if="ledger"
                :activeKey="activeWalletTab"
                size="large"
                class="wallet-main-tabs"
                @change="onWalletTabChange"
            >
                <a-tab-pane key="ledger" tab="Money movement">
                    <CashLedgerPanel
                        :movements="ledger.movements"
                        :filters="ledger.filters"
                        :ledger-balance="ledger.ledgerBalance"
                        :rail-card-types="ledger.railCardTypes"
                    />
                </a-tab-pane>
                <a-tab-pane key="card-types" tab="Payment card types">
                    <ContentLayout title="Payment card types">
                        <template #filters>
                            <a-button
                                v-if="
                                    hasPermission('payment-card-types.store')
                                "
                                type="primary"
                                class="bg-white border flex items-center border-green-500 text-green-500"
                                @click="openCreate"
                            >
                                <template #icon>
                                    <IconPlus class="w-4 h-4" />
                                </template>
                                Add card type
                            </a-button>
                        </template>

                        <template #table>
                            <a-table
                                :columns="columns"
                                :data-source="rows"
                                :pagination="false"
                                row-key="id"
                                :locale="{
                                    emptyText:
                                        'No card types yet. Add one to use Pay in Card on Sales.',
                                }"
                            >
                                <template #bodyCell="{ column, record }">
                                    <template
                                        v-if="column.key === 'is_active'"
                                    >
                                        <a-tag
                                            :color="
                                                record.is_active
                                                    ? 'green'
                                                    : 'default'
                                            "
                                        >
                                            {{
                                                record.is_active
                                                    ? 'Active'
                                                    : 'Inactive'
                                            }}
                                        </a-tag>
                                    </template>
                                    <template
                                        v-else-if="column.key === 'actions'"
                                    >
                                        <a-space>
                                            <IconTooltipButton
                                                v-if="
                                                    hasPermission(
                                                        'payment-card-types.money',
                                                    )
                                                "
                                                name="View money details"
                                                hover="hover:bg-emerald-600"
                                                @click="
                                                    openMoneyDetails(record)
                                                "
                                            >
                                                <IconReportMoney
                                                    size="20"
                                                    class="mx-auto"
                                                />
                                            </IconTooltipButton>
                                            <IconTooltipButton
                                                v-if="
                                                    hasPermission(
                                                        'payment-card-types.update',
                                                    )
                                                "
                                                name="Edit card type"
                                                hover="hover:bg-blue-500"
                                                @click="openEdit(record)"
                                            >
                                                <IconEdit
                                                    size="20"
                                                    class="mx-auto"
                                                />
                                            </IconTooltipButton>
                                            <IconTooltipButton
                                                v-if="
                                                    hasPermission(
                                                        'payment-card-types.destroy',
                                                    )
                                                "
                                                name="Remove card type"
                                                hover="hover:bg-red-600"
                                                :loading="
                                                    deletingId === record.id
                                                "
                                                @click="remove(record)"
                                            >
                                                <IconTrash
                                                    size="20"
                                                    class="mx-auto"
                                                />
                                            </IconTooltipButton>
                                        </a-space>
                                    </template>
                                </template>
                            </a-table>
                        </template>
                    </ContentLayout>
                </a-tab-pane>
            </a-tabs>

            <div v-else>
                <ContentLayout title="Payment card types">
                    <template #filters>
                        <a-button
                            v-if="hasPermission('payment-card-types.store')"
                            type="primary"
                            class="bg-white border flex items-center border-green-500 text-green-500"
                            @click="openCreate"
                        >
                            <template #icon>
                                <IconPlus class="w-4 h-4" />
                            </template>
                            Add card type
                        </a-button>
                    </template>

                    <template #table>
                        <a-table
                            :columns="columns"
                            :data-source="rows"
                            :pagination="false"
                            row-key="id"
                            :locale="{
                                emptyText:
                                    'No card types yet. Add one to use Pay in Card on Sales.',
                            }"
                        >
                            <template #bodyCell="{ column, record }">
                                <template v-if="column.key === 'is_active'">
                                    <a-tag
                                        :color="
                                            record.is_active
                                                ? 'green'
                                                : 'default'
                                        "
                                    >
                                        {{
                                            record.is_active
                                                ? 'Active'
                                                : 'Inactive'
                                        }}
                                    </a-tag>
                                </template>
                                <template v-else-if="column.key === 'actions'">
                                    <a-space>
                                        <IconTooltipButton
                                            v-if="
                                                hasPermission(
                                                    'payment-card-types.money',
                                                )
                                            "
                                            name="View money details"
                                            hover="hover:bg-emerald-600"
                                            @click="openMoneyDetails(record)"
                                        >
                                            <IconReportMoney
                                                size="20"
                                                class="mx-auto"
                                            />
                                        </IconTooltipButton>
                                        <IconTooltipButton
                                            v-if="
                                                hasPermission(
                                                    'payment-card-types.update',
                                                )
                                            "
                                            name="Edit card type"
                                            hover="hover:bg-blue-500"
                                            @click="openEdit(record)"
                                        >
                                            <IconEdit
                                                size="20"
                                                class="mx-auto"
                                            />
                                        </IconTooltipButton>
                                        <IconTooltipButton
                                            v-if="
                                                hasPermission(
                                                    'payment-card-types.destroy',
                                                )
                                            "
                                            name="Remove card type"
                                            hover="hover:bg-red-600"
                                            :loading="deletingId === record.id"
                                            @click="remove(record)"
                                        >
                                            <IconTrash
                                                size="20"
                                                class="mx-auto"
                                            />
                                        </IconTooltipButton>
                                    </a-space>
                                </template>
                            </template>
                        </a-table>
                    </template>
                </ContentLayout>
            </div>
        </div>

        <a-modal
            v-model:visible="modalOpen"
            :title="editing ? 'Edit card type' : 'Add card type'"
            :confirm-loading="saving"
            ok-text="Save"
            destroy-on-close
            @ok="save"
            @cancel="closeModal"
        >
            <div class="flex flex-col gap-4 pt-2">
                <div>
                    <div class="text-sm text-gray-600 mb-1">Display name</div>
                    <a-input
                        v-model:value="formName"
                        placeholder="e.g. BDO POS, Visa terminal"
                        maxlength="255"
                    />
                </div>
                <div
                    v-if="editing"
                    class="flex items-center justify-between gap-4"
                >
                    <span class="text-sm text-gray-600">Active</span>
                    <a-switch v-model:checked="formActive" />
                </div>
            </div>
        </a-modal>

        <a-modal
            v-model:visible="moneyModalVisible"
            :title="
                moneyDetailsType
                    ? `Money — ${moneyDetailsType.name}`
                    : 'Money details'
            "
            width="800px"
            :footer="null"
            destroy-on-close
            @cancel="closeMoneyModal"
        >
            <a-spin :spinning="moneyLoading">
                <div v-if="moneyDetailsType" class="space-y-4">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div
                            class="rounded-lg border border-gray-200 bg-gray-50 px-4 py-3"
                        >
                            <div class="text-xs text-gray-500 uppercase">
                                Today
                            </div>
                            <div class="text-xl font-semibold text-green-700">
                                {{ formattedTotal(todayTotal) }}
                            </div>
                        </div>
                        <div
                            class="rounded-lg border border-gray-200 bg-gray-50 px-4 py-3"
                        >
                            <div class="text-xs text-gray-500 uppercase">
                                Yesterday
                            </div>
                            <div class="text-xl font-semibold text-gray-800">
                                {{ formattedTotal(yesterdayTotal) }}
                            </div>
                        </div>
                    </div>
                    <div>
                        <div class="text-sm font-medium text-gray-700 mb-2">
                            Transaction history (paid card sales)
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
                                    {{ formattedTotal(record.grand_total) }}
                                </template>
                            </template>
                        </a-table>
                    </div>
                </div>
            </a-spin>
        </a-modal>
    </AuthenticatedLayout>
</template>
