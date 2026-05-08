<script setup>
import { ref, computed, watch, createVNode, nextTick } from "vue";
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
const todayYmd = new Date().toISOString().slice(0, 10);

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
});

const isMoneyMovementPage = computed(
    () => props.walletPageMode === "money-movement",
);

const pageTitle = computed(() =>
    isMoneyMovementPage.value ? "Money movement" : "Card terminals",
);

const headTitle = computed(() => pageTitle.value);

/** Inertia / primary navigation route for this page (reload, date load). */
function walletPageRouteName() {
    return isMoneyMovementPage.value
        ? "wallet.money-movement"
        : "payment-card-types.index";
}

function visitWalletPage(query) {
    const q = { ...query };
    delete q.tab;
    router.get(getRoute(walletPageRouteName()), q, {
        preserveScroll: true,
        preserveState: true,
        replace: true,
    });
}

function goToCardTypes() {
    const q = {};
    if (activeBusinessDate.value) {
        q.business_date = activeBusinessDate.value;
    }
    router.get(getRoute("payment-card-types.index"), q);
}

function goToMoneyMovement() {
    const q = {};
    if (activeBusinessDate.value) {
        q.business_date = activeBusinessDate.value;
    }
    router.get(getRoute("wallet.money-movement"), q);
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

/** Money movement page defaults to collapsed cash control; card terminals page expands it. */
const cashControlExpanded = ref(true);

function syncCashControlExpandedToPage() {
    if (isMoneyMovementPage.value && props.ledger) {
        cashControlExpanded.value = false;
        return;
    }
    cashControlExpanded.value = true;
}

watch(
    [() => props.walletPageMode, () => props.ledger],
    () => {
        syncCashControlExpandedToPage();
    },
    { immediate: true },
);

const showCashControlFullDetail = computed(() => {
    if (!isMoneyMovementPage.value || !props.ledger) {
        return true;
    }
    return cashControlExpanded.value;
});

function toggleCashControlDetail() {
    cashControlExpanded.value = !cashControlExpanded.value;
}

/** One-line expected formula for disclosure / expanded section. */
function cashControlBreakdownLine(c) {
    if (!c) return "";
    const opening = formattedTotal(Number(c.opening_cash) || 0);
    const sales = formattedTotal(Number(c.paid_cash_sales) || 0);
    const min = formattedTotal(Number(c.manual_in) || 0);
    const mout = formattedTotal(Number(c.manual_out) || 0);
    return `${opening} opening + ${sales} cash sales + ${min} manual in − ${mout} manual out = ${formattedTotal(Number(c.expected_cash) || 0)} expected`;
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

/** User-facing timestamp for ISO strings from cash control snapshot props. */
function formatCashControlDateTime(iso) {
    if (!iso) return "";
    try {
        const d = new Date(iso);
        if (Number.isNaN(d.getTime())) return "";
        const dateStr = d.toLocaleDateString(undefined, {
            year: "numeric",
            month: "short",
            day: "numeric",
        });
        const timeStr = d.toLocaleTimeString(undefined, {
            hour: "2-digit",
            minute: "2-digit",
        });
        return `${dateStr} · ${timeStr}`;
    } catch {
        return "";
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
const endShiftAction = ref("cashout_now");

const canManageCashControl = computed(
    () =>
        hasPermission("wallet-cash-ledger.opening-cash.store") ||
        hasPermission("wallet-cash-ledger.counted-cash.store") ||
        hasPermission("wallet-cash-ledger.store"),
);
const isShiftClosed = computed(() => !!props.cashControl?.is_closed);

/** Treat null/empty/non-finite/zero as unusable amounts for enabling save buttons. */
function isCashInputNullOrZero(value) {
    if (value === null || value === undefined || value === "") {
        return true;
    }
    const n = Number(value);
    if (!Number.isFinite(n)) {
        return true;
    }
    return n === 0;
}

const disableSaveOpeningCashButton = computed(
    () =>
        isShiftClosed.value ||
        isCashInputNullOrZero(cashControlForm.value.opening_cash),
);

const disableSaveCountedCashButton = computed(
    () =>
        isShiftClosed.value ||
        isCashInputNullOrZero(cashControlForm.value.counted_cash),
);

const canCashOutOnEndShift = computed(() => {
    const c = props.cashControl;
    if (!c?.counted_at) return false;
    return Number(c.counted_cash || 0) > 0;
});

/** Daily expected is off, but counted cash matches bridge-from-last-count. */
const bridgeOpeningHint = computed(() => {
    const c = props.cashControl;
    if (!c || c.bridge_expected_cash == null) {
        return false;
    }
    if (c.variance == null || c.bridge_variance == null) {
        return false;
    }
    const bridgeV = Number(c.bridge_variance);
    const dailyV = Number(c.variance);

    return Math.abs(bridgeV) <= 0.01 && Math.abs(dailyV) > 0.01;
});

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

watch(
    [
        () => props.cashControl?.counted_at,
        () => props.cashControl?.counted_cash,
        endShiftAction,
    ],
    () => {
        const c = props.cashControl;
        if (
            c?.counted_at &&
            !(Number(c.counted_cash ?? 0) > 0) &&
            endShiftAction.value === "cashout_now"
        ) {
            endShiftAction.value = "save_as_opening_cash";
        }
    },
    { flush: "sync", deep: true },
);

function reloadWalletForBusinessDate() {
    const q = queryObjectFromPageUrl(page.url);
    q.business_date =
        cashControlForm.value.business_date || activeBusinessDate.value;
    visitWalletPage(q);
}

async function saveOpeningCash() {
    if (disableSaveOpeningCashButton.value) return;
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
    if (disableSaveCountedCashButton.value) return;
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
    const modalBodyChildren = [
        createVNode(
            "p",
            { class: "text-sm text-gray-700" },
            "Choose what to do with counted cash before closing shift.",
        ),
    ];
    if (!canCashOutOnEndShift.value) {
        modalBodyChildren.push(
            createVNode(
                "p",
                { class: "text-xs text-amber-800" },
                "Cash out requires counted cash greater than zero. Save as opening is available.",
            ),
        );
    }
    modalBodyChildren.push(
        createVNode("div", { class: "flex flex-col gap-2" }, [
            createVNode(
                "label",
                {
                    class: [
                        "inline-flex items-center gap-2 text-sm",
                        canCashOutOnEndShift.value
                            ? "text-gray-700"
                            : "cursor-not-allowed text-gray-400",
                    ].join(" "),
                },
                [
                    createVNode("input", {
                        type: "radio",
                        name: "endShiftAction",
                        disabled: !canCashOutOnEndShift.value,
                        checked: endShiftAction.value === "cashout_now",
                        onChange: () => {
                            if (!canCashOutOnEndShift.value) return;
                            endShiftAction.value = "cashout_now";
                        },
                    }),
                    "Cash out now",
                ],
            ),
            createVNode(
                "label",
                {
                    class: "inline-flex items-center gap-2 text-sm text-gray-700",
                },
                [
                    createVNode("input", {
                        type: "radio",
                        name: "endShiftAction",
                        checked:
                            endShiftAction.value === "save_as_opening_cash",
                        onChange: () => {
                            endShiftAction.value = "save_as_opening_cash";
                        },
                    }),
                    "Save as opening cash",
                ],
            ),
        ]),
    );

    Modal.confirm({
        title: "End Shift action",
        icon: createVNode(ExclamationCircleOutlined),
        content: createVNode(
            "div",
            { class: "space-y-2" },
            modalBodyChildren,
        ),
        okText: "Confirm End Shift",
        cancelText: "Cancel",
        onOk: endShift,
    });
}

function goToSubmitCountedCash() {
    cashControlExpanded.value = true;
    nextTick(() => {
        countedCardRef.value?.scrollIntoView({
            behavior: "smooth",
            block: "center",
        });
    });
}

async function endShift() {
    if (
        endShiftAction.value === "cashout_now" &&
        !canCashOutOnEndShift.value
    ) {
        notification.warning({
            message: "Cash out requires counted cash greater than zero.",
        });

        return;
    }
    endingShift.value = true;
    try {
        await axios.post(getRoute("wallet-cash-ledger.end-shift"), {
            location_id: activeLocationId.value,
            business_date:
                cashControlForm.value.business_date || activeBusinessDate.value,
            end_shift_action: endShiftAction.value,
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
        <Head :title="headTitle" />
        <ContentHeader class="mb-4" :title="pageTitle" />
        <div class="mb-6 max-w-7xl flex flex-wrap items-center gap-3 text-sm">
            <template v-if="isMoneyMovementPage && canViewCardTypes">
                <span class="text-gray-600">Need to edit card rails?</span>
                <a-button type="link" class="h-auto p-0" @click="goToCardTypes">
                    Open card terminals
                </a-button>
            </template>
            <template v-else-if="!isMoneyMovementPage && canViewMoneyMovement">
                <span class="text-gray-600">Daily cash and ledger</span>
                <a-button
                    type="link"
                    class="h-auto p-0"
                    @click="goToMoneyMovement"
                >
                    Open money movement
                </a-button>
            </template>
        </div>

        <div
            v-if="cashControl"
            class="mb-6 max-w-7xl rounded-lg border border-gray-200 bg-white px-4 py-4 shadow-sm"
        >
            <div
                class="mb-3 flex flex-wrap items-end justify-between gap-3 border-b border-gray-100 pb-3"
            >
                <div>
                    <div class="text-base font-semibold text-gray-900">
                        Cash control
                    </div>
                    <div class="text-xs text-gray-500">
                        Daily expected vs counted cash for this location
                    </div>
                </div>
                <div class="flex flex-wrap items-end gap-3">
                    <a-button
                        v-if="ledger && isMoneyMovementPage"
                        type="link"
                        class="h-auto px-0 text-teal-700"
                        @click="toggleCashControlDetail"
                    >
                        {{
                            showCashControlFullDetail
                                ? "Hide full cash control"
                                : "Full cash control"
                        }}
                    </a-button>
                    <div class="w-full max-w-[14rem] sm:w-[14rem]">
                        <label class="mb-1 block text-xs font-medium text-gray-600">
                            Business date
                        </label>
                        <div class="flex gap-2">
                            <input
                                v-model="cashControlForm.business_date"
                                type="date"
                                :max="todayYmd"
                                class="w-full rounded border border-gray-300 px-2 py-2 text-sm"
                            />
                            <a-button @click="reloadWalletForBusinessDate">
                                Load
                            </a-button>
                        </div>
                    </div>
                </div>
            </div>

            <div
                class="flex flex-wrap items-end gap-x-8 gap-y-3 rounded-lg border border-gray-200 bg-gray-50 px-3 py-3"
            >
                <div>
                    <div class="text-xs font-medium uppercase text-gray-500">
                        Expected
                    </div>
                    <div class="text-lg font-semibold tabular-nums text-gray-900">
                        {{
                            formattedTotal(
                                Number(cashControl.expected_cash) || 0,
                            )
                        }}
                    </div>
                </div>
                <div>
                    <div class="text-xs font-medium uppercase text-gray-500">
                        Counted
                    </div>
                    <div class="text-lg font-semibold tabular-nums text-gray-900">
                        {{
                            cashControl.counted_cash == null
                                ? "—"
                                : formattedTotal(
                                      Number(cashControl.counted_cash) || 0,
                                  )
                        }}
                    </div>
                </div>
                <div>
                    <div class="text-xs font-medium uppercase text-gray-500">
                        Variance
                    </div>
                    <div
                        class="text-lg font-semibold tabular-nums"
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
                <div
                    v-if="cashControl.is_closed"
                    class="inline-flex items-center gap-1 rounded border border-amber-200 bg-amber-50 px-2 py-1 text-[11px] font-medium text-amber-900"
                >
                    <IconLock class="h-3 w-3 shrink-0" />
                    Shift closed for this date
                </div>
            </div>

            <div
                v-show="showCashControlFullDetail"
                class="mt-4 space-y-4 border-t border-gray-100 pt-4"
            >
                <details
                    class="rounded border border-gray-200 bg-white px-3 py-2 text-sm text-gray-800"
                >
                    <summary
                        class="cursor-pointer select-none font-medium text-gray-800"
                    >
                        How expected cash is calculated
                    </summary>
                    <p class="mt-2 text-xs leading-relaxed text-gray-600">
                        {{ cashControlBreakdownLine(cashControl) }}
                    </p>
                </details>

                <div
                    v-if="
                        (cashControl.opening_is_saved &&
                            cashControl.opening_last_updated_by_user) ||
                        (cashControl.counted_at && cashControl.counted_by_user)
                    "
                    class="space-y-1 text-xs text-gray-600"
                >
                <p
                    v-if="
                        cashControl.opening_is_saved &&
                        cashControl.opening_last_updated_by_user
                    "
                >
                    Opening last saved by
                    <span class="font-medium text-gray-800">{{
                        cashControl.opening_last_updated_by_user.name
                    }}</span>
                    <template
                        v-if="
                            formatCashControlDateTime(
                                cashControl.opening_last_updated_at,
                            )
                        "
                    >
                        ·
                        {{
                            formatCashControlDateTime(
                                cashControl.opening_last_updated_at,
                            )
                        }}
                    </template>
                </p>
                <p v-if="cashControl.counted_at && cashControl.counted_by_user">
                    Counted by
                    <span class="font-medium text-gray-800">{{
                        cashControl.counted_by_user.name
                    }}</span>
                    <template
                        v-if="
                            formatCashControlDateTime(cashControl.counted_at)
                        "
                    >
                        ·
                        {{ formatCashControlDateTime(cashControl.counted_at) }}
                    </template>
                </p>
            </div>

            <a-collapse
                v-if="
                    (cashControl.count_submission_history ?? []).length > 0 ||
                    (cashControl.opening_audit_history ?? []).length > 0
                "
                ghost
                size="small"
                class="mt-3 border-t border-gray-100 pt-2"
            >
                <a-collapse-panel
                    v-if="
                        (cashControl.count_submission_history ?? []).length > 0
                    "
                    key="count-history"
                    :header="`Count submissions for ${cashControl.business_date}`"
                >
                    <ul class="divide-y divide-gray-100 rounded border border-gray-100 bg-gray-50/80 text-xs text-gray-700">
                        <li
                            v-for="entry in cashControl.count_submission_history"
                            :key="entry.id"
                            class="px-2 py-2"
                        >
                            <span class="tabular-nums font-medium text-gray-900">{{
                                formattedTotal(Number(entry.counted_cash) || 0)
                            }}</span>
                            counted
                            <template v-if="entry.counted_by_user?.name">
                                by
                                <span class="font-medium text-gray-800">{{
                                    entry.counted_by_user.name
                                }}</span>
                            </template>
                            <template
                                v-if="formatCashControlDateTime(entry.counted_at)"
                            >
                                ·
                                {{
                                    formatCashControlDateTime(entry.counted_at)
                                }}
                            </template>
                            <div
                                v-if="
                                    entry.expected_cash_snapshot != null ||
                                    entry.variance_snapshot != null
                                "
                                class="mt-0.5 tabular-nums text-gray-500"
                            >
                                <span v-if="entry.expected_cash_snapshot != null"
                                    >Expected
                                    {{
                                        formattedTotal(
                                            Number(entry.expected_cash_snapshot) ||
                                                0,
                                        )
                                    }}</span
                                >
                                <span
                                    v-if="
                                        entry.expected_cash_snapshot != null &&
                                        entry.variance_snapshot != null
                                    "
                                >
                                    ·
                                </span>
                                <span v-if="entry.variance_snapshot != null"
                                    >Variance at submit
                                    {{
                                        formattedTotal(
                                            Number(entry.variance_snapshot) || 0,
                                        )
                                    }}</span
                                >
                            </div>
                            <div
                                v-if="entry.notes && String(entry.notes).trim()"
                                class="mt-1 text-[11px] text-gray-500"
                            >
                                {{ entry.notes }}
                            </div>
                        </li>
                    </ul>
                </a-collapse-panel>

                <a-collapse-panel
                    v-if="
                        (cashControl.opening_audit_history ?? []).length > 0
                    "
                    key="opening-audits"
                    header="Opening cash change history"
                >
                    <ul class="divide-y divide-gray-100 rounded border border-gray-100 bg-gray-50/80 text-xs text-gray-700">
                        <li
                            v-for="audit in cashControl.opening_audit_history"
                            :key="audit.id"
                            class="px-2 py-2"
                        >
                            <template v-if="audit.old_opening_cash != null">
                                {{
                                    formattedTotal(Number(audit.old_opening_cash))
                                }}
                                →
                            </template>
                            {{
                                formattedTotal(Number(audit.new_opening_cash))
                            }}
                            <span class="tabular-nums text-gray-500">
                                (Δ
                                {{
                                    formattedTotal(Number(audit.delta_amount))
                                }})
                            </span>
                            <template v-if="audit.changed_by_user?.name">
                                ·
                                {{ audit.changed_by_user.name }}
                            </template>
                            <template
                                v-if="
                                    formatCashControlDateTime(audit.changed_at)
                                "
                            >
                                ·
                                {{
                                    formatCashControlDateTime(audit.changed_at)
                                }}
                            </template>
                            <div
                                v-if="audit.reason && String(audit.reason).trim()"
                                class="mt-1 text-[11px] text-gray-500"
                            >
                                {{ audit.reason }}
                            </div>
                        </li>
                    </ul>
                </a-collapse-panel>
            </a-collapse>

            <a-collapse
                ghost
                size="small"
                class="mt-3 rounded border border-gray-200 bg-gray-50/80"
            >
                <a-collapse-panel
                    key="bridge-from-count"
                    header="Bridge from last count"
                >
                    <div class="px-1 pb-1 text-sm">
                        <template
                            v-if="cashControl.bridge_anchor_business_date"
                        >
                            <p class="text-xs text-gray-600">
                                Last counted
                                {{
                                    formattedTotal(
                                        Number(
                                            cashControl.bridge_anchor_counted_cash,
                                        ) || 0,
                                    )
                                }}
                                on {{ cashControl.bridge_anchor_business_date }}
                                <span
                                    v-if="
                                        cashControl.bridge_day_span != null &&
                                        cashControl.bridge_day_span > 0
                                    "
                                >
                                    ({{ cashControl.bridge_day_span }} day span)
                                </span>
                            </p>
                            <div
                                class="mt-2 grid grid-cols-1 gap-3 sm:grid-cols-2 lg:max-w-xl"
                            >
                                <div
                                    class="rounded border border-gray-200 bg-white px-3 py-2"
                                >
                                    <div class="text-xs uppercase text-gray-500">
                                        Bridge expected
                                    </div>
                                    <div
                                        class="font-mono text-lg font-semibold text-gray-900 tabular-nums"
                                    >
                                        {{
                                            formattedTotal(
                                                Number(
                                                    cashControl.bridge_expected_cash,
                                                ) || 0,
                                            )
                                        }}
                                    </div>
                                </div>
                                <div
                                    class="rounded border border-gray-200 bg-white px-3 py-2"
                                >
                                    <div class="text-xs uppercase text-gray-500">
                                        Bridge variance
                                    </div>
                                    <div
                                        class="font-mono text-lg font-semibold tabular-nums"
                                        :class="
                                            cashControl.bridge_variance == null
                                                ? 'text-gray-500'
                                                : Number(
                                                        cashControl.bridge_variance,
                                                    ) === 0
                                                  ? 'text-gray-800'
                                                  : Number(
                                                          cashControl.bridge_variance,
                                                      ) > 0
                                                    ? 'text-amber-700'
                                                    : 'text-red-700'
                                        "
                                    >
                                        {{
                                            cashControl.bridge_variance == null
                                                ? "—"
                                                : formattedTotal(
                                                      Number(
                                                          cashControl.bridge_variance,
                                                      ) || 0,
                                                  )
                                        }}
                                    </div>
                                </div>
                            </div>
                            <p
                                v-if="cashControl.bridge_span_warning"
                                class="mt-2 text-xs text-amber-800"
                            >
                                Span exceeds 366 days; review totals carefully.
                            </p>
                        </template>
                        <p v-else class="text-xs text-gray-600">
                            No prior counted cash on file for this location.
                        </p>
                        <p
                            v-if="bridgeOpeningHint"
                            class="mt-2 rounded border border-amber-200 bg-amber-50 px-2 py-2 text-xs text-amber-900"
                        >
                            Bridge matches your counted cash, but daily
                            expected does not. Check
                            <strong>Set opening cash</strong> for
                            {{ cashControl.business_date }} so daily figures
                            align next time.
                        </p>
                    </div>
                </a-collapse-panel>
            </a-collapse>

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
                        :disabled="disableSaveOpeningCashButton || savingOpeningCash"
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
                        :disabled="disableSaveCountedCashButton || savingCountedCash"
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
                    class="flex items-center gap-2"
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
        </div>

        <div class="mt-6 max-w-7xl">
            <CashLedgerPanel
                v-if="isMoneyMovementPage && ledger"
                :movements="ledger.movements"
                :filters="ledger.filters"
                :ledger-balance="ledger.ledgerBalance"
                :rail-card-types="ledger.railCardTypes"
                :active-location-id="activeLocationId"
                :is-shift-closed="isShiftClosed"
                :running-cash-balance="runningCashBalance"
            />
            <div v-else class="space-y-4">
                    <div
                        class="mb-4 max-w-7xl rounded-lg border border-gray-200 bg-white px-4 py-4 shadow-sm"
                    >
                        <div class="text-base font-semibold text-gray-900">
                            Credit
                        </div>
                        <div class="mb-3 text-xs text-gray-500">
                            Paid credit sales (charge to account)
                        </div>
                        <div class="grid max-w-md grid-cols-2 gap-3">
                            <div
                                class="rounded-lg border border-gray-200 bg-gray-50 px-3 py-2"
                            >
                                <div class="text-xs uppercase text-gray-500">
                                    Today
                                </div>
                                <div class="text-lg font-semibold text-green-700">
                                    {{
                                        formattedTotal(
                                            Number(
                                                walletCreditTotals.today_total,
                                            ) || 0,
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
