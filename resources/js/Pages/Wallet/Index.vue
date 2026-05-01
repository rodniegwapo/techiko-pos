<script setup>
import { ref, computed, watch, createVNode } from "vue";
import { Head, router, usePage } from "@inertiajs/vue3";
import { ExclamationCircleOutlined } from "@ant-design/icons-vue";
import {
    IconPlus,
    IconReportMoney,
    IconEdit,
    IconTrash,
    IconLock,
    IconLockOpen,
} from "@tabler/icons-vue";
import axios from "axios";
import { Modal, notification } from "ant-design-vue";

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
    activeLocation: {
        type: Object,
        default: () => null,
    },
    cashControl: {
        type: Object,
        default: () => null,
    },
});

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
    if (activeLocationId.value) {
        base.location_id = activeLocationId.value;
    }
    base.business_date = activeBusinessDate.value;
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
                    location_id: activeLocationId.value,
                    name,
                    is_active: formActive.value,
                },
            );
            notification.success({ message: "Card type updated." });
        } else {
            await axios.post(getRoute("payment-card-types.store"), {
                location_id: activeLocationId.value,
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
                "cashControl",
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
            {
                params: {
                    location_id: activeLocationId.value,
                },
            },
        );
        notification.success({ message: "Done." });
        router.reload({
            only: [
                "cardTypes",
                "walletCashTotals",
                "walletCreditTotals",
                "ledger",
                "runningCashBalance",
                "cashControl",
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
            {
                params: {
                    page,
                    per_page: historyPagination.value.pageSize,
                    location_id: activeLocationId.value,
                    business_date: activeBusinessDate.value,
                },
            },
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

const cashControlForm = ref({
    business_date: activeBusinessDate.value,
    opening_cash: null,
    opening_reason: "",
    counted_cash: null,
    notes: "",
});
const savingOpeningCash = ref(false);
const savingCountedCash = ref(false);
const endingShift = ref(false);
const reopeningShift = ref(false);
const countedCardRef = ref(null);

const canManageCashControl = computed(
    () =>
        hasPermission("wallet-cash-ledger.opening-cash.store") ||
        hasPermission("wallet-cash-ledger.counted-cash.store") ||
        hasPermission("wallet-cash-ledger.store"),
);
const isShiftClosed = computed(() => !!props.cashControl?.is_closed);

watch(
    () => props.cashControl,
    (v) => {
        cashControlForm.value.business_date =
            v?.business_date || activeBusinessDate.value;
        if (v?.opening_is_saved) {
            cashControlForm.value.opening_cash =
                v?.opening_cash != null ? Number(v.opening_cash) : 0;
        } else if (v?.opening_suggestion != null) {
            cashControlForm.value.opening_cash = Number(v.opening_suggestion);
        } else {
            cashControlForm.value.opening_cash =
                v?.opening_cash != null ? Number(v.opening_cash) : 0;
        }
        cashControlForm.value.opening_reason = "";
        cashControlForm.value.counted_cash =
            v?.counted_cash != null ? Number(v.counted_cash) : null;
        cashControlForm.value.notes = v?.notes || "";
    },
    { immediate: true, deep: true },
);

function reloadWalletForBusinessDate() {
    const q = queryObjectFromPageUrl(page.url);
    q.business_date =
        cashControlForm.value.business_date || activeBusinessDate.value;
    if (activeLocationId.value) {
        q.location_id = activeLocationId.value;
    }
    if (!q.tab && props.ledger) {
        q.tab = activeWalletTab.value;
    }
    router.get(getRoute("payment-card-types.index"), q, {
        preserveScroll: true,
        preserveState: true,
        replace: true,
    });
}

async function saveOpeningCash() {
    if (isShiftClosed.value) return;
    savingOpeningCash.value = true;
    try {
        await axios.post(getRoute("wallet-cash-ledger.opening-cash.store"), {
            location_id: activeLocationId.value,
            business_date:
                cashControlForm.value.business_date || activeBusinessDate.value,
            opening_cash: Number(cashControlForm.value.opening_cash || 0),
            reason: cashControlForm.value.opening_reason || null,
        });
        notification.success({ message: "Opening cash saved." });
        reloadWalletForBusinessDate();
    } catch (e) {
        notification.error({
            message:
                firstValidationMessage(e) || "Could not save opening cash.",
        });
    } finally {
        savingOpeningCash.value = false;
    }
}

async function saveCountedCash() {
    if (isShiftClosed.value) return;
    savingCountedCash.value = true;
    try {
        await axios.post(getRoute("wallet-cash-ledger.counted-cash.store"), {
            location_id: activeLocationId.value,
            business_date:
                cashControlForm.value.business_date || activeBusinessDate.value,
            counted_cash: Number(cashControlForm.value.counted_cash || 0),
            notes: cashControlForm.value.notes || null,
        });
        notification.success({ message: "Counted cash saved." });
        reloadWalletForBusinessDate();
    } catch (e) {
        notification.error({
            message:
                firstValidationMessage(e) || "Could not save counted cash.",
        });
    } finally {
        savingCountedCash.value = false;
    }
}

function onEndShiftClick() {
    if (
        !props.cashControl?.counted_cash &&
        props.cashControl?.counted_cash !== 0
    ) {
        Modal.confirm({
            title: "Count cash first before End Shift",
            icon: createVNode(ExclamationCircleOutlined),
            content:
                "Please submit counted cash for this date first. The next day opening cash is not filled automatically.",
            okText: "Go to Submit Counted Cash",
            cancelText: "Cancel",
            onOk: goToSubmitCountedCash,
        });
        return;
    }
    endShift();
}

function goToSubmitCountedCash() {
    countedCardRef.value?.scrollIntoView({
        behavior: "smooth",
        block: "center",
    });
}

async function endShift() {
    endingShift.value = true;
    try {
        await axios.post(getRoute("wallet-cash-ledger.end-shift"), {
            location_id: activeLocationId.value,
            business_date:
                cashControlForm.value.business_date || activeBusinessDate.value,
        });
        notification.success({ message: "Shift closed." });
        reloadWalletForBusinessDate();
    } catch (e) {
        notification.error({
            message:
                firstValidationMessage(e) ||
                "Could not close shift. Submit counted cash first.",
        });
    } finally {
        endingShift.value = false;
    }
}

async function reopenShift() {
    reopeningShift.value = true;
    try {
        await axios.post(getRoute("wallet-cash-ledger.reopen-shift"), {
            location_id: activeLocationId.value,
            business_date:
                cashControlForm.value.business_date || activeBusinessDate.value,
        });
        notification.success({ message: "Shift reopened." });
        reloadWalletForBusinessDate();
    } catch (e) {
        notification.error({
            message: firstValidationMessage(e) || "Could not reopen shift.",
        });
    } finally {
        reopeningShift.value = false;
    }
}
</script>

<template>
    <AuthenticatedLayout>
        <Head title="Payment wallet" />
        <ContentHeader class="mb-8" title="Payment wallet" />

        <div
            v-if="cashControl"
            class="mb-6 max-w-7xl rounded-lg border border-gray-200 bg-white px-4 py-4 shadow-sm"
        >
            <div
                class="mb-4 flex flex-wrap items-end justify-between gap-3 border-b border-gray-100 pb-3"
            >
                <div>
                    <div class="text-base font-semibold text-gray-900">
                        Cash control
                    </div>
                    <div class="text-xs text-gray-500">
                        Daily expected vs counted cash for this location
                    </div>
                    <div
                        v-if="cashControl.is_closed"
                        class="mt-2 inline-flex items-center gap-1 rounded bg-amber-100 px-2 py-1 text-[11px] font-medium text-amber-800"
                    >
                        <IconLock class="h-3 w-3" />
                        Shift closed for this date
                    </div>
                </div>
                <div class="w-full max-w-[14rem]">
                    <label class="mb-1 block text-xs font-medium text-gray-600">
                        Business date
                    </label>
                    <div class="flex gap-2">
                        <input
                            v-model="cashControlForm.business_date"
                            type="date"
                            class="w-full rounded border border-gray-300 px-2 py-2 text-sm"
                        />
                        <a-button @click="reloadWalletForBusinessDate">
                            Load
                        </a-button>
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-6">
                <div
                    class="rounded border border-gray-200 bg-gray-50 px-3 py-2"
                >
                    <div class="text-xs uppercase text-gray-500">Opening</div>
                    <div class="text-lg font-semibold text-gray-900">
                        {{
                            formattedTotal(
                                Number(cashControl.opening_cash) || 0,
                            )
                        }}
                    </div>
                </div>
                <div
                    class="rounded border border-gray-200 bg-gray-50 px-3 py-2"
                >
                    <div class="text-xs uppercase text-gray-500">
                        Paid cash sales
                    </div>
                    <div class="text-lg font-semibold text-green-700">
                        {{
                            formattedTotal(
                                Number(cashControl.paid_cash_sales) || 0,
                            )
                        }}
                    </div>
                </div>
                <div
                    class="rounded border border-gray-200 bg-gray-50 px-3 py-2"
                >
                    <div class="text-xs uppercase text-gray-500">Manual in</div>
                    <div class="text-lg font-semibold text-green-700">
                        {{ formattedTotal(Number(cashControl.manual_in) || 0) }}
                    </div>
                </div>
                <div
                    class="rounded border border-gray-200 bg-gray-50 px-3 py-2"
                >
                    <div class="text-xs uppercase text-gray-500">
                        Manual out
                    </div>
                    <div class="text-lg font-semibold text-rose-700">
                        {{
                            formattedTotal(Number(cashControl.manual_out) || 0)
                        }}
                    </div>
                </div>
                <div
                    class="rounded border border-teal-200 bg-teal-50 px-3 py-2"
                >
                    <div class="text-xs uppercase text-teal-700">Expected</div>
                    <div class="text-lg font-semibold text-teal-800">
                        {{
                            formattedTotal(
                                Number(cashControl.expected_cash) || 0,
                            )
                        }}
                    </div>
                </div>
                <div
                    class="rounded border border-gray-200 bg-gray-50 px-3 py-2"
                >
                    <div class="text-xs uppercase text-gray-500">Variance</div>
                    <div
                        class="text-lg font-semibold"
                        :class="
                            Number(cashControl.variance || 0) === 0
                                ? 'text-gray-800'
                                : Number(cashControl.variance || 0) > 0
                                  ? 'text-amber-700'
                                  : 'text-red-700'
                        "
                    >
                        {{
                            cashControl.variance == null
                                ? "—"
                                : formattedTotal(
                                      Number(cashControl.variance) || 0,
                                  )
                        }}
                    </div>
                </div>
            </div>

            <div
                v-if="canManageCashControl"
                class="mt-4 grid grid-cols-1 gap-4 border-t border-gray-100 pt-4 lg:grid-cols-2"
            >
                <div class="space-y-2 rounded border border-gray-200 p-3">
                    <div class="text-sm font-medium text-gray-800">
                        Set opening cash
                    </div>
                    <p
                        v-if="
                            cashControl.opening_is_saved === false &&
                            cashControl.suggestion_source_date
                        "
                        class="text-xs text-amber-700"
                    >
                        Suggested from previous counted cash on
                        {{ cashControl.suggestion_source_date }}.
                    </p>
                    <a-input-number
                        v-model:value="cashControlForm.opening_cash"
                        :min="0"
                        :step="0.01"
                        class="w-full"
                        placeholder="0.00"
                        :disabled="isShiftClosed"
                    />
                    <a-textarea
                        v-model:value="cashControlForm.opening_reason"
                        :rows="2"
                        placeholder="Optional reason for override/change"
                        :disabled="isShiftClosed"
                    />
                    <a-button
                        type="primary"
                        :loading="savingOpeningCash"
                        :disabled="isShiftClosed"
                        @click="saveOpeningCash"
                    >
                        Save opening
                    </a-button>
                </div>
                <div
                    ref="countedCardRef"
                    class="space-y-2 rounded border border-gray-200 p-3"
                >
                    <div class="text-sm font-medium text-gray-800">
                        Submit counted cash
                    </div>
                    <a-input-number
                        v-model:value="cashControlForm.counted_cash"
                        :min="0"
                        :step="0.01"
                        class="w-full"
                        placeholder="0.00"
                        :disabled="isShiftClosed"
                    />
                    <a-textarea
                        v-model:value="cashControlForm.notes"
                        :rows="2"
                        placeholder="Optional reconciliation notes"
                        :disabled="isShiftClosed"
                    />
                    <a-button
                        type="primary"
                        :loading="savingCountedCash"
                        :disabled="isShiftClosed"
                        @click="saveCountedCash"
                    >
                        Save counted cash
                    </a-button>
                </div>
            </div>
            <div
                v-if="canManageCashControl"
                class="mt-4 flex items-center gap-2 border-t border-gray-100 pt-4"
            >
                <a-button
                    v-if="!cashControl.is_closed"
                    type="primary"
                    :loading="endingShift"
                    @click="onEndShiftClick"
                    class="flex items-center gap-2"
                >
                    <template #icon>
                        <IconLock class="h-4 w-4" />
                    </template>
                    End Shift
                </a-button>
                <a-button
                    v-else-if="cashControl.can_reopen"
                    :loading="reopeningShift"
                    @click="reopenShift"
                >
                    <template #icon>
                        <IconLockOpen class="h-4 w-4" />
                    </template>
                    Reopen Shift
                </a-button>
                <span
                    v-else-if="cashControl.is_closed"
                    class="text-xs text-gray-500"
                >
                    Only the user who closed this shift can reopen it.
                </span>
            </div>
        </div>

        <div
            class="mb-6 grid max-w-7xl grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-2"
        >
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
                    All-time manual ledger net only (cash in minus cash out),
                    not expected drawer cash for the selected business date.
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
                    Includes manual entries like owner withdrawals and
                    adjustments from Money Movement.
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
                        :active-location-id="activeLocationId"
                        :is-shift-closed="isShiftClosed"
                    />
                </a-tab-pane>
                <a-tab-pane key="card-types" tab="Payment card types">
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
                                                    ? "Active"
                                                    : "Inactive"
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
                                                ? "Active"
                                                : "Inactive"
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
