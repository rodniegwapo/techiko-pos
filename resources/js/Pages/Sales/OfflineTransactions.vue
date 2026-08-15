<script setup>
import { ref, computed, onMounted, onBeforeUnmount, watch } from "vue";
import { Head, usePage, router } from "@inertiajs/vue3";
import axios from "axios";
import { v4 as uuidv4 } from "uuid";
import { PlusSquareOutlined } from "@ant-design/icons-vue";
import { message, Modal } from "ant-design-vue";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import ContentHeader from "@/Components/ContentHeader.vue";
import ContentLayout from "@/Components/ContentLayout.vue";
import RefreshButton from "@/Components/buttons/Refresh.vue";
import { useGlobalVariables } from "@/Composables/useGlobalVariable";
import { useDomainRoutes } from "@/Composables/useDomainRoutes";
import { useNetworkInfo } from "@/Composables/useNetworkInfo";
import { usePermissionsV2 } from "@/Composables/usePermissionV2";
import { useSalesLayoutMode } from "@/Composables/useSalesLayoutMode";
import { useMediaQuery } from "@vueuse/core";
import {
    addPendingSale,
    listNeedsAttentionForDomain,
    markAccepted,
    markSynced,
    markFailed,
    markSyncing,
    markRejected,
} from "@/offline/db.js";

const page = usePage();
const { getRoute } = useDomainRoutes();
const { spinning } = useGlobalVariables();
const { hasPermission } = usePermissionsV2();
const { domainSlug, isCoffeeshopLayout } = useSalesLayoutMode();

function redirectAwayIfCoffeeshop() {
    if (!isCoffeeshopLayout.value) return;
    router.visit(getRoute("sales.index"), { replace: true });
}

watch(isCoffeeshopLayout, redirectAwayIfCoffeeshop, { immediate: true });

const isMdUp = useMediaQuery("(min-width: 768px)");

const {
    connectionType,
    isNetworkInfoSupported,
    connectionLabel,
    effectiveTypeLabel,
} = useNetworkInfo();

const captureModalWidth = computed(() =>
    isMdUp.value ? 720 : "calc(100vw - 24px)",
);
const captureModalRootStyle = computed(() =>
    isMdUp.value ? {} : { maxWidth: "100vw", top: "12px", paddingBottom: 0 },
);

const locations = computed(() => page.props.locations ?? []);
const activeLocationId = computed(() => page.props.activeLocationId);

const isOnline = ref(
    typeof navigator !== "undefined" ? navigator.onLine : true,
);

const cashierUserId = computed(() => page.props.auth?.user?.data?.id);

const selectedLocationId = ref(null);
watch(
    [activeLocationId, locations],
    () => {
        if (
            selectedLocationId.value == null &&
            activeLocationId.value != null
        ) {
            selectedLocationId.value = activeLocationId.value;
        }
        if (selectedLocationId.value == null && locations.value?.length === 1) {
            selectedLocationId.value = locations.value[0].id;
        }
    },
    { immediate: true },
);

const lineItems = ref([{ product_id: "", quantity: 1, unit_price: "" }]);
const paymentMethod = ref("cash");
const selectedPaymentCardTypeId = ref(null);
const cardTypes = ref([]);
const loadingCardTypes = ref(false);
const notes = ref("");
const customerId = ref("");

const queue = ref([]);
const loadingQueue = ref(false);
const captureModalVisible = ref(false);

async function fetchCardTypes() {
    if (!domainSlug.value || !hasPermission("payment-card-types.list")) {
        cardTypes.value = [];
        return;
    }
    if (typeof navigator !== "undefined" && !navigator.onLine) {
        return;
    }
    loadingCardTypes.value = true;
    try {
        const { data } = await axios.get(getRoute("payment-card-types.list"));
        cardTypes.value = data?.data ?? [];
    } catch {
        cardTypes.value = [];
    } finally {
        loadingCardTypes.value = false;
    }
}

watch(paymentMethod, (v) => {
    if (v !== "card") {
        selectedPaymentCardTypeId.value = null;
    }
});

watch(captureModalVisible, (open) => {
    if (open) {
        fetchCardTypes();
    }
});

const locationNameById = computed(() => {
    const m = {};
    for (const loc of locations.value) {
        m[loc.id] = loc.name;
    }
    return m;
});

async function loadQueue() {
    if (!domainSlug.value) return;
    loadingQueue.value = true;
    try {
        queue.value = await listNeedsAttentionForDomain(domainSlug.value);
    } finally {
        loadingQueue.value = false;
    }
}

function addLineRow() {
    lineItems.value.push({ product_id: "", quantity: 1, unit_price: "" });
}

function removeLineRow(i) {
    lineItems.value.splice(i, 1);
    if (!lineItems.value.length) {
        lineItems.value.push({ product_id: "", quantity: 1, unit_price: "" });
    }
}

const captureDisabled = computed(() => {
    if (!domainSlug.value || !selectedLocationId.value) return true;
    const rows = lineItems.value.filter(
        (r) =>
            r.product_id &&
            Number(r.quantity) > 0 &&
            r.unit_price !== "" &&
            Number(r.unit_price) >= 0,
    );
    if (rows.length < 1) return true;
    if (paymentMethod.value === "card") {
        if (!hasPermission("payment-card-types.list")) return true;
        if (!selectedPaymentCardTypeId.value) return true;
    }
    return false;
});

function closeCaptureModal() {
    captureModalVisible.value = false;
    selectedPaymentCardTypeId.value = null;
    paymentMethod.value = "cash";
}

async function saveOfflineSale() {
    if (!domainSlug.value) {
        message.error("Missing organization context.");
        return;
    }
    if (!cashierUserId.value) {
        message.error("Not logged in.");
        return;
    }

    if (paymentMethod.value === "card") {
        if (!selectedPaymentCardTypeId.value) {
            message.error("Select a card payment type.");
            return;
        }
    }

    const items = lineItems.value
        .filter(
            (r) =>
                r.product_id &&
                Number(r.quantity) > 0 &&
                r.unit_price !== "" &&
                Number(r.unit_price) >= 0,
        )
        .map((r) => ({
            product_id: Number(r.product_id),
            quantity: Number(r.quantity),
            unit_price: Number(r.unit_price),
        }));

    const selectedType = cardTypes.value.find(
        (t) => t.id === selectedPaymentCardTypeId.value,
    );

    const clientMutationId = uuidv4();
    const payload = {
        items,
        payment_method: paymentMethod.value,
        payment_card_type_id:
            paymentMethod.value === "card" && selectedPaymentCardTypeId.value
                ? Number(selectedPaymentCardTypeId.value)
                : null,
        payment_card_type_name:
            paymentMethod.value === "card" && selectedType?.name
                ? selectedType.name
                : null,
        location_id: selectedLocationId.value,
        cashier_user_id: cashierUserId.value,
        notes: notes.value || null,
        customer_id: customerId.value ? Number(customerId.value) : null,
        recorded_at: new Date().toISOString(),
    };

    await addPendingSale({
        client_mutation_id: clientMutationId,
        status: "pending_review",
        domain_slug: domainSlug.value,
        location_id: selectedLocationId.value,
        payload,
    });

    message.success("Saved locally for review.");
    lineItems.value = [{ product_id: "", quantity: 1, unit_price: "" }];
    notes.value = "";
    customerId.value = "";
    selectedPaymentCardTypeId.value = null;
    paymentMethod.value = "cash";
    captureModalVisible.value = false;
    await loadQueue();
}

function formatMoney(n) {
    const x = Number(n);
    if (Number.isNaN(x)) return "—";
    return x.toFixed(2);
}

function lineTotals(items) {
    if (!items?.length) return 0;
    return items.reduce(
        (s, it) => s + Number(it.unit_price) * Number(it.quantity),
        0,
    );
}

function paymentDisplay(record) {
    const method = record.payload?.payment_method || "—";
    const name = record.payload?.payment_card_type_name;
    if (method === "card" && name) {
        return `card · ${name}`;
    }
    if (method === "card" && record.payload?.payment_card_type_id) {
        return `card · #${record.payload.payment_card_type_id}`;
    }
    return method;
}

function lineSummary(record) {
    const items = record.payload?.items || [];
    if (!items.length) return "—";
    const first = items[0];
    const rest = items.length - 1;
    const head = `#${first.product_id} ×${first.quantity}`;
    return rest > 0 ? `${head} (+${rest})` : head;
}

const columns = [
    {
        title: "Created",
        dataIndex: "created_at",
        key: "created_at",
        width: 180,
    },
    { title: "Location", key: "location", width: 140 },
    { title: "Lines", key: "lines", ellipsis: true },
    { title: "Total", key: "total", align: "right", width: 100 },
    { title: "Payment", key: "payment", width: 160 },
    { title: "Status", key: "status", width: 120 },
    { title: "Actions", key: "actions", width: 200, fixed: "right" },
];

function syncUrl() {
    const url = getRoute("sales.offline-sync");
    return url && url !== "#" ? url : null;
}

/**
 * POST batch to server; updates Dexie from results. Rows should already be markSyncing.
 */
async function postSalesBatch(batch) {
    const url = syncUrl();
    if (!url) {
        for (const row of batch) {
            await markFailed(
                row.client_mutation_id,
                "Could not resolve sync route.",
            );
        }
        return;
    }

    try {
        const { data } = await axios.post(url, { sales: batch });

        if (!data.success) {
            const msg = data.message || "Sync failed.";
            for (const row of batch) {
                await markFailed(row.client_mutation_id, msg);
            }
            return;
        }

        for (const res of data.results || []) {
            if (res.success) {
                await markSynced(res.client_mutation_id, res.sale_id);
            } else {
                await markFailed(
                    res.client_mutation_id,
                    res.message ||
                        (res.errors && JSON.stringify(res.errors)) ||
                        "Unknown error",
                );
            }
        }
    } catch (e) {
        const msg =
            e.response?.data?.message || e.message || "Sync request failed.";
        for (const row of batch) {
            await markFailed(row.client_mutation_id, msg);
        }
    }
}

async function trySyncRows(rows) {
    if (!rows.length || !navigator.onLine) return;

    const batch = rows.map((r) => ({
        client_mutation_id: r.client_mutation_id,
        payload: r.payload,
    }));

    for (const row of rows) {
        await markSyncing(row.client_mutation_id);
    }
    await loadQueue();

    await postSalesBatch(batch);
    await loadQueue();
}

/** All rows waiting to upload (accepted only). @returns {boolean} true if a sync was attempted */
async function flushAcceptedOnReconnect() {
    if (!domainSlug.value || !navigator.onLine) return false;
    const rows = await listNeedsAttentionForDomain(domainSlug.value);
    const accepted = rows.filter((r) => r.status === "accepted");
    if (!accepted.length) return false;
    await trySyncRows(accepted);
    return true;
}

async function updateOnline() {
    const wasOffline = !isOnline.value;
    isOnline.value = navigator.onLine;
    if (isOnline.value && wasOffline) {
        await fetchCardTypes();
        const didSync = await flushAcceptedOnReconnect();
        if (didSync) {
            message.success("Back online — syncing accepted sales.");
        } else {
            message.info("Back online.");
        }
    }
}

onMounted(async () => {
    window.addEventListener("online", updateOnline);
    window.addEventListener("offline", updateOnline);
    await loadQueue();
    if (navigator.onLine) {
        await flushAcceptedOnReconnect();
        await fetchCardTypes();
    }
});

onBeforeUnmount(() => {
    window.removeEventListener("online", updateOnline);
    window.removeEventListener("offline", updateOnline);
});

async function refreshTable() {
    spinning.value = true;
    try {
        await loadQueue();
    } finally {
        spinning.value = false;
    }
}

async function onAccept(record) {
    if (record.status === "syncing") return;

    const id = record.client_mutation_id;
    await markAccepted(id);
    await loadQueue();

    if (!navigator.onLine) {
        message.info("Accepted — will sync when you are online.");
        return;
    }

    const updated = queue.value.find((r) => r.client_mutation_id === id);
    if (updated && updated.status === "accepted") {
        await trySyncRows([updated]);
        const after = queue.value.find((r) => r.client_mutation_id === id);
        if (!after) {
            message.success("Synced to server.");
        } else if (after.status === "failed") {
            message.error(after.error_message || "Sync failed.");
        }
    }
}

function onReject(record) {
    if (record.status === "syncing") return;

    Modal.confirm({
        title: "Reject this offline sale?",
        content:
            "It will be marked rejected locally and will not upload. You can still inspect it in IndexedDB if needed.",
        okText: "Reject",
        okType: "danger",
        onOk: async () => {
            await markRejected(record.client_mutation_id);
            await loadQueue();
            message.success("Rejected.");
        },
    });
}

function rowActionsDisabled(record) {
    return record.status === "syncing";
}

function tableRowClassName(_record, index) {
    return index % 2 === 1 ? "bg-gray-50 group" : "group";
}
</script>

<template>
    <Head title="Offline transactions" />

    <AuthenticatedLayout>
        <ContentHeader class="mb-4 md:mb-8" title="Offline transactions">
            <template #description>
                Record sales locally when there is no network, then accept or
                reject each row. Accepted rows sync automatically when online.
            </template>
        </ContentHeader>

        <ContentLayout title="Pending offline sales">
            <template #filters>
                <div
                    class="flex w-full flex-wrap items-center justify-between gap-3"
                >
                    <div class="flex flex-wrap items-center gap-2">
                        <div
                            v-if="!isOnline"
                            class="rounded border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900"
                        >
                            <div>Offline — saves stay in this browser only.</div>
                            <div
                                v-if="isNetworkInfoSupported && connectionLabel"
                                class="mt-1 text-xs opacity-90"
                            >
                                Network: {{ connectionLabel
                                }}<template
                                    v-if="
                                        effectiveTypeLabel &&
                                        connectionType &&
                                        connectionType !== 'none'
                                    "
                                >
                                    ({{ effectiveTypeLabel }})
                                </template>
                            </div>
                        </div>
                        <div
                            v-else
                            class="rounded border border-green-200 bg-green-50 px-3 py-2 text-sm text-green-900"
                        >
                            <div>
                                Online — accepted rows sync automatically.
                            </div>
                            <div
                                v-if="isNetworkInfoSupported && connectionLabel"
                                class="mt-1 text-xs opacity-90"
                            >
                                Network: {{ connectionLabel
                                }}<template
                                    v-if="
                                        effectiveTypeLabel &&
                                        connectionType &&
                                        connectionType !== 'none'
                                    "
                                >
                                    ({{ effectiveTypeLabel }})
                                </template>
                            </div>
                        </div>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <a-button
                            type="primary"
                            class="flex items-center border border-green-500 bg-white text-green-500"
                            @click="captureModalVisible = true"
                        >
                            <template #icon>
                                <PlusSquareOutlined />
                            </template>
                            Add offline sale
                        </a-button>
                        <RefreshButton
                            :loading="spinning"
                            @click="refreshTable"
                        />
                    </div>
                </div>
            </template>

            <template #table>
                <a-table
                    v-if="isMdUp"
                    class="ant-table-striped"
                    :columns="columns"
                    :data-source="queue"
                    :loading="loadingQueue"
                    :pagination="false"
                    :row-class-name="tableRowClassName"
                    row-key="client_mutation_id"
                    :scroll="{ x: 900 }"
                >
                    <template #bodyCell="{ column, record }">
                        <template v-if="column.key === 'location'">
                            {{
                                locationNameById[record.location_id] ||
                                `#${record.location_id}`
                            }}
                        </template>
                        <template v-else-if="column.key === 'lines'">
                            <span :title="lineSummary(record)">{{
                                lineSummary(record)
                            }}</span>
                        </template>
                        <template v-else-if="column.key === 'total'">
                            {{ formatMoney(lineTotals(record.payload?.items)) }}
                        </template>
                        <template v-else-if="column.key === 'payment'">
                            {{ paymentDisplay(record) }}
                        </template>
                        <template v-else-if="column.key === 'status'">
                            <div>
                                <span class="capitalize">{{
                                    record.status.replaceAll("_", " ")
                                }}</span>
                                <div
                                    v-if="
                                        record.status === 'failed' &&
                                        record.error_message
                                    "
                                    class="mt-1 max-w-xs truncate text-xs text-red-600"
                                    :title="record.error_message"
                                >
                                    {{ record.error_message }}
                                </div>
                            </div>
                        </template>
                        <template v-else-if="column.key === 'actions'">
                            <a-space>
                                <a-button
                                    type="primary"
                                    size="small"
                                    :disabled="rowActionsDisabled(record)"
                                    @click="onAccept(record)"
                                >
                                    Accept
                                </a-button>
                                <a-button
                                    danger
                                    size="small"
                                    :disabled="rowActionsDisabled(record)"
                                    @click="onReject(record)"
                                >
                                    Reject
                                </a-button>
                            </a-space>
                        </template>
                    </template>
                    <template #emptyText>
                        <span class="text-gray-500"
                            >No pending offline sales for this
                            organization.</span
                        >
                    </template>
                </a-table>

                <div v-else class="px-2 py-2 sm:px-4">
                    <a-spin :spinning="loadingQueue">
                        <div
                            v-if="!queue.length && !loadingQueue"
                            class="py-12 text-center text-sm text-gray-500"
                        >
                            No pending offline sales for this organization.
                        </div>
                        <div v-else class="flex flex-col gap-3">
                            <a-card
                                v-for="(record, idx) in queue"
                                :key="record.client_mutation_id"
                                size="small"
                                class="shadow-sm"
                                :class="{
                                    'bg-gray-50/80': idx % 2 === 1,
                                }"
                            >
                                <div
                                    class="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1.5 text-sm"
                                >
                                    <span class="text-gray-500">Created</span>
                                    <span class="min-w-0 break-all text-right">{{
                                        record.created_at
                                    }}</span>
                                    <span class="text-gray-500">Location</span>
                                    <span class="min-w-0 break-words text-right">{{
                                        locationNameById[record.location_id] ||
                                        `#${record.location_id}`
                                    }}</span>
                                    <span class="text-gray-500">Lines</span>
                                    <span
                                        class="min-w-0 break-words text-right"
                                        :title="lineSummary(record)"
                                        >{{ lineSummary(record) }}</span
                                    >
                                    <span class="text-gray-500">Total</span>
                                    <span class="text-right font-medium">{{
                                        formatMoney(
                                            lineTotals(record.payload?.items),
                                        )
                                    }}</span>
                                    <span class="text-gray-500">Payment</span>
                                    <span
                                        class="min-w-0 break-words text-right"
                                        >{{ paymentDisplay(record) }}</span
                                    >
                                    <span class="text-gray-500">Status</span>
                                    <div class="text-right">
                                        <span class="capitalize">{{
                                            record.status.replaceAll("_", " ")
                                        }}</span>
                                        <div
                                            v-if="
                                                record.status === 'failed' &&
                                                record.error_message
                                            "
                                            class="mt-1 text-xs leading-snug text-red-600"
                                        >
                                            {{ record.error_message }}
                                        </div>
                                    </div>
                                </div>
                                <a-space
                                    direction="vertical"
                                    size="small"
                                    class="mt-4 w-full"
                                >
                                    <a-button
                                        type="primary"
                                        block
                                        :disabled="rowActionsDisabled(record)"
                                        @click="onAccept(record)"
                                    >
                                        Accept
                                    </a-button>
                                    <a-button
                                        danger
                                        block
                                        :disabled="rowActionsDisabled(record)"
                                        @click="onReject(record)"
                                    >
                                        Reject
                                    </a-button>
                                </a-space>
                            </a-card>
                        </div>
                    </a-spin>
                </div>
            </template>
        </ContentLayout>

        <a-modal
            v-model:visible="captureModalVisible"
            title="Add offline sale"
            :width="captureModalWidth"
            :style="captureModalRootStyle"
            centered
            @cancel="closeCaptureModal"
        >
            <div class="grid gap-4 md:grid-cols-2">
                <div>
                    <label class="mb-1 block text-sm text-gray-600"
                        >Location</label
                    >
                    <a-select
                        v-model:value="selectedLocationId"
                        class="w-full"
                        placeholder="Select location"
                    >
                        <a-select-option
                            v-for="loc in locations"
                            :key="loc.id"
                            :value="loc.id"
                        >
                            {{ loc.name }}
                            <span v-if="loc.is_default" class="text-gray-400"
                                >(default)</span
                            >
                        </a-select-option>
                    </a-select>
                </div>
                <div>
                    <label class="mb-1 block text-sm text-gray-600"
                        >Payment</label
                    >
                    <a-select v-model:value="paymentMethod" class="w-full">
                        <a-select-option value="cash">Cash</a-select-option>
                        <a-select-option
                            value="card"
                            :disabled="
                                !hasPermission('payment-card-types.list') ||
                                (!loadingCardTypes && cardTypes.length === 0)
                            "
                        >
                            Card
                        </a-select-option>
                        <a-select-option value="e-wallet"
                            >E-wallet</a-select-option
                        >
                    </a-select>
                    <p
                        v-if="
                            paymentMethod === 'card' &&
                            !loadingCardTypes &&
                            cardTypes.length === 0 &&
                            hasPermission('payment-card-types.list')
                        "
                        class="mt-1 text-xs text-amber-700"
                    >
                        No card types configured. Add types in Payment wallet
                        while online.
                    </p>
                </div>
                <div
                    v-if="paymentMethod === 'card'"
                    class="md:col-span-2"
                >
                    <label class="mb-1 block text-sm text-gray-600"
                        >Card payment type</label
                    >
                    <a-select
                        v-model:value="selectedPaymentCardTypeId"
                        class="w-full"
                        placeholder="Select type"
                        :loading="loadingCardTypes"
                        allow-clear
                    >
                        <a-select-option
                            v-for="t in cardTypes"
                            :key="t.id"
                            :value="t.id"
                        >
                            {{ t.name }}
                        </a-select-option>
                    </a-select>
                </div>
                <div class="md:col-span-2">
                    <label class="mb-1 block text-sm text-gray-600"
                        >Customer ID (optional)</label
                    >
                    <a-input
                        v-model:value="customerId"
                        placeholder="Numeric customer id"
                    />
                </div>
                <div class="md:col-span-2">
                    <label class="mb-1 block text-sm text-gray-600"
                        >Notes (optional)</label
                    >
                    <a-input v-model:value="notes" />
                </div>
            </div>
            <div class="mt-4">
                <div class="mb-2 text-sm font-medium text-gray-700">
                    Line items
                </div>
                <div class="space-y-2">
                    <div
                        v-for="(row, idx) in lineItems"
                        :key="idx"
                        class="flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-end"
                    >
                        <a-input
                            v-model:value="row.product_id"
                            class="w-full min-w-0 sm:w-32"
                            placeholder="Product ID"
                        />
                        <a-input-number
                            v-model:value="row.quantity"
                            :min="1"
                            class="w-full min-w-0 sm:w-28"
                        />
                        <a-input
                            v-model:value="row.unit_price"
                            class="w-full min-w-0 sm:w-32"
                            placeholder="Unit price"
                        />
                        <a-button
                            danger
                            type="link"
                            class="shrink-0 self-end sm:self-auto"
                            @click="removeLineRow(idx)"
                            >Remove</a-button
                        >
                    </div>
                </div>
                <a-button class="mt-2" type="dashed" @click="addLineRow"
                    >Add line</a-button
                >
            </div>
            <template #footer>
                <div
                    class="flex w-full flex-col gap-2 sm:flex-row sm:justify-end"
                >
                    <a-button block class="sm:!inline-block sm:w-auto" @click="closeCaptureModal">
                        Cancel
                    </a-button>
                    <a-button
                        type="primary"
                        block
                        class="sm:!inline-block sm:w-auto"
                        :disabled="captureDisabled"
                        @click="saveOfflineSale"
                    >
                        Save locally
                    </a-button>
                </div>
            </template>
        </a-modal>
    </AuthenticatedLayout>
</template>
