<script setup>
import { computed, reactive, ref, watch } from "vue";
import { Head, router } from "@inertiajs/vue3";
import { message } from "ant-design-vue";
import axios from "axios";
import { watchDebounced, useMediaQuery } from "@vueuse/core";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import ContentHeader from "@/Components/ContentHeader.vue";
import ContentLayout from "@/Components/ContentLayout.vue";
import { useDomainRoutes } from "@/Composables/useDomainRoutes";
import { useHelpers } from "@/Composables/useHelpers";
import { usePermissionsV2 } from "@/Composables/usePermissionV2";
import PaymentMethodFields from "@/Pages/Finance/components/PaymentMethodFields.vue";

/**
 * Purchasing: orders to suppliers for a store or warehouse. "Receive" records a delivery: the
 * stock comes in and the order's supplier bill grows by what arrived. "Pay now" pays the supplier
 * there and then, e.g. from a store's cash drawer; whatever isn't paid stays on the bill.
 */
const props = defineProps({
    orders: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
    suppliers: { type: Array, default: () => [] },
    locations: { type: Array, default: () => [] },
    defaultLocationId: { type: Number, default: null },
    canPayNow: { type: Boolean, default: false },
    paymentMethods: { type: Array, default: () => [] },
    accounts: { type: Array, default: () => [] },
    /** Stores only: warehouses have no cash drawer. */
    drawerLocations: { type: Array, default: () => [] },
    activeStoreId: { type: Number, default: null },
});

const { getRoute } = useDomainRoutes();
const { formattedTotal, formatDate } = useHelpers();
const { hasPermission } = usePermissionsV2();
const isMdUp = useMediaQuery("(min-width: 768px)");

const STATUS = {
    draft: { label: "Draft", color: "default" },
    ordered: { label: "Sent", color: "blue" },
    partial: { label: "Partly received", color: "orange" },
    received: { label: "Received", color: "green" },
    cancelled: { label: "Cancelled", color: "red" },
};

const search = ref(props.filters.search ?? "");
const status = ref(props.filters.status ?? null);
const reload = () =>
    router.get(
        getRoute("purchase-orders.index"),
        { search: search.value || undefined, status: status.value || undefined },
        { preserveState: true, preserveScroll: true, replace: true },
    );
watchDebounced(search, reload, { debounce: 300 });

const orderTotal = (order) =>
    (order.lines ?? []).reduce((sum, l) => sum + Number(l.quantity_ordered) * Number(l.unit_cost), 0);
const receivedTotal = (order) =>
    (order.lines ?? []).reduce((sum, l) => sum + Number(l.quantity_received) * Number(l.unit_cost), 0);

// ---- Create / edit ----
const formOpen = ref(false);
const editing = ref(null);
const saving = ref(false);
const form = reactive({ supplier: null, location_id: null, expected_date: null, notes: "", lines: [] });
// "Pay from": how the order will be paid. Nothing is paid yet; Receive → Pay now starts from it.
const payFrom = reactive({ payment_method: null, location_id: null, financial_account_id: null, errors: {} });

// By name: picking one fills the box; a name not in the list becomes a new supplier on save.
const supplierOptions = computed(() => props.suppliers.map((s) => ({ value: s.name })));
const locationOptions = computed(() =>
    props.locations.map((l) => ({ value: l.id, label: l.type === "warehouse" ? `${l.name} (warehouse)` : l.name })),
);

function openForm(order = null) {
    editing.value = order;
    form.supplier = order?.supplier?.name ?? null;
    form.location_id = order ? order.location_id : props.defaultLocationId;
    form.expected_date = order?.expected_date ?? null;
    form.notes = order?.notes ?? "";
    form.lines = order
        ? order.lines.map((l) => ({ product_id: l.product_id, name: l.product?.name, quantity_ordered: Number(l.quantity_ordered), unit_cost: Number(l.unit_cost) }))
        : [];
    Object.assign(payFrom, {
        payment_method: order?.pay_method ?? null,
        location_id: order?.pay_location_id ?? null,
        financial_account_id: order?.pay_account_id ?? null,
        errors: {},
    });
    formOpen.value = true;
}

// A cash drawer to start from: the store the stock goes to, else the store being worked in.
watch(
    () => [payFrom.payment_method, form.location_id],
    () => {
        if (payFrom.payment_method === "cash_register" && !payFrom.location_id) {
            payFrom.location_id = defaultDrawer({ location_id: form.location_id });
        }
    },
);

// Product picker
const productQuery = ref("");
const productResults = ref([]);
watchDebounced(
    productQuery,
    async (q) => {
        if (!q || q.length < 2) {
            productResults.value = [];
            return;
        }
        const { data } = await axios.get(getRoute("purchase-orders.index"), {
            params: { product_search: q },
            headers: { Accept: "application/json" },
        });
        productResults.value = data.data ?? [];
    },
    { debounce: 300 },
);
function addProduct(product) {
    if (form.lines.some((l) => l.product_id === product.id)) {
        message.warning(`${product.name} is already on the order.`);
        return;
    }
    form.lines.push({ product_id: product.id, name: product.name, quantity_ordered: 1, unit_cost: Number(product.cost ?? 0) });
    productQuery.value = "";
    productResults.value = [];
}

const formTotal = computed(() => form.lines.reduce((s, l) => s + Number(l.quantity_ordered || 0) * Number(l.unit_cost || 0), 0));

function save(send) {
    const supplierName = (form.supplier ?? "").trim();
    if (!supplierName) return message.warning("Choose or type the supplier.");
    if (!form.location_id) return message.warning("Choose where the stock goes.");
    if (!form.lines.length) return message.warning("Add at least one product.");

    const existing = props.suppliers.find((s) => s.name.toLowerCase() === supplierName.toLowerCase());
    const payload = {
        ...(existing ? { supplier_id: existing.id } : { supplier_name: supplierName }),
        location_id: form.location_id,
        expected_date: form.expected_date || null,
        notes: form.notes || null,
        send,
        lines: form.lines.map(({ product_id, quantity_ordered, unit_cost }) => ({ product_id, quantity_ordered, unit_cost })),
        pay_method: payFrom.payment_method || null,
        pay_location_id: payFrom.payment_method === "cash_register" ? payFrom.location_id : null,
        pay_account_id: payFrom.financial_account_id || null,
    };
    saving.value = true;
    payFrom.errors = {};
    const options = {
        preserveScroll: true,
        onSuccess: () => (formOpen.value = false),
        onError: (errors) => {
            payFrom.errors = {
                payment_method: errors.pay_method,
                location_id: errors.pay_location_id,
                financial_account_id: errors.pay_account_id,
            };
            message.error(Object.values(errors)[0] || "Could not save the order.");
        },
        onFinish: () => (saving.value = false),
    };
    editing.value
        ? router.put(getRoute("purchase-orders.update", { purchaseOrder: editing.value.id }), payload, options)
        : router.post(getRoute("purchase-orders.store"), payload, options);
}

// ---- Receive ----
const receiving = ref(null);
const receiveQty = reactive({});
const receivingSaving = ref(false);

// Pay now: the amount follows this delivery's value until it is typed over.
const payNow = ref(false);
const payAmountTouched = ref(false);
const payForm = reactive({ amount: null, payment_method: "cash_register", location_id: null, financial_account_id: null, errors: {} });
const deliveryValue = computed(() =>
    (receiving.value?.lines ?? []).reduce((sum, l) => sum + Number(receiveQty[l.id] || 0) * Number(l.unit_cost), 0),
);
const billRemaining = computed(() => {
    const bill = receiving.value?.bill;
    return bill ? Math.max(0, Number(bill.amount) - Number(bill.paid_amount)) : 0;
});
const payAmountShown = computed({
    get: () => (payAmountTouched.value ? payForm.amount : Math.round(deliveryValue.value * 100) / 100),
    set: (v) => {
        payAmountTouched.value = true;
        payForm.amount = v;
    },
});

/** The order's store if it has a drawer, else the store being worked in. */
function defaultDrawer(order) {
    const ids = props.drawerLocations.map((l) => l.id);
    if (ids.includes(order.location_id)) return order.location_id;
    if (ids.includes(props.activeStoreId)) return props.activeStoreId;
    return ids.length === 1 ? ids[0] : null;
}

function openReceive(order) {
    receiving.value = order;
    for (const l of order.lines) {
        receiveQty[l.id] = Math.max(0, Number(l.quantity_ordered) - Number(l.quantity_received));
    }
    // An order with a "Pay from" opens with Pay now on and that way of paying already chosen.
    payNow.value = props.canPayNow && !!order.pay_method;
    payAmountTouched.value = false;
    Object.assign(payForm, {
        amount: null,
        payment_method: order.pay_method ?? "cash_register",
        location_id: order.pay_method === "cash_register" && order.pay_location_id ? order.pay_location_id : defaultDrawer(order),
        financial_account_id: order.pay_account_id ?? null,
        errors: {},
    });
}
function saveReceive() {
    const order = receiving.value;
    payForm.errors = {};
    const payload = { lines: order.lines.map((l) => ({ id: l.id, quantity: Number(receiveQty[l.id] || 0) })) };
    if (payNow.value) {
        payload.pay_now = {
            amount: Number(payAmountShown.value || 0),
            payment_method: payForm.payment_method,
            location_id: payForm.payment_method === "cash_register" ? payForm.location_id : null,
            financial_account_id: payForm.financial_account_id,
        };
    }
    receivingSaving.value = true;
    router.post(getRoute("purchase-orders.receive", { purchaseOrder: order.id }), payload, {
        preserveScroll: true,
        onSuccess: () => (receiving.value = null),
        onError: (errors) => {
            // pay_now.amount → the Pay now fields; a closed shift (payment_date) shows on the drawer.
            for (const [key, text] of Object.entries(errors)) {
                if (!key.startsWith("pay_now.")) continue;
                const field = key.slice("pay_now.".length);
                payForm.errors[field === "payment_date" ? "location_id" : field] = text;
            }
            message.error(Object.values(errors)[0] || "Could not record the delivery.");
        },
        onFinish: () => (receivingSaving.value = false),
    });
}

const act = (route, order) =>
    router.post(getRoute(route, { purchaseOrder: order.id }), {}, {
        preserveScroll: true,
        onError: (errors) => message.error(Object.values(errors)[0] || "Something went wrong."),
    });

const columns = [
    { title: "Order", key: "po" },
    { title: "Supplier", key: "supplier" },
    { title: "For", key: "location" },
    { title: "Status", key: "status" },
    { title: "Total", key: "total", align: "right" },
    { title: "", key: "actions", align: "right" },
];
</script>

<template>
    <Head title="Purchasing" />
    <AuthenticatedLayout>
        <ContentHeader class="mb-4" title="Purchasing" />

        <ContentLayout title="Purchase orders">
            <template #filters>
                <div class="flex w-full flex-wrap items-center justify-end gap-2">
                    <a-input-search v-model:value="search" placeholder="Order number or supplier" class="w-full md:max-w-[260px]" />
                    <a-select
                        v-model:value="status"
                        allow-clear
                        placeholder="All statuses"
                        class="w-full md:w-44"
                        :options="Object.entries(STATUS).map(([value, s]) => ({ value, label: s.label }))"
                        @change="reload"
                    />
                    <a-button v-if="hasPermission('purchase-orders.store')" type="primary" data-testid="new-po" @click="openForm()">
                        New purchase order
                    </a-button>
                </div>
            </template>
            <template #table>
                <a-table
                    :columns="columns"
                    :data-source="orders.data"
                    row-key="id"
                    :pagination="false"
                    :scroll="isMdUp ? undefined : { x: 760 }"
                    :locale="{ emptyText: 'No purchase orders yet.' }"
                >
                    <template #bodyCell="{ column, record }">
                        <template v-if="column.key === 'po'">
                            <div class="font-mono font-semibold">{{ record.po_number }}</div>
                            <div class="text-xs text-gray-500">
                                {{ record.lines.length }} item(s)<template v-if="record.expected_date"> · expected {{ formatDate(record.expected_date) }}</template>
                            </div>
                        </template>
                        <template v-else-if="column.key === 'supplier'">{{ record.supplier?.name }}</template>
                        <template v-else-if="column.key === 'location'">{{ record.location?.name }}</template>
                        <template v-else-if="column.key === 'status'">
                            <a-tag :color="STATUS[record.status]?.color">{{ STATUS[record.status]?.label ?? record.status }}</a-tag>
                        </template>
                        <template v-else-if="column.key === 'total'">
                            <div>{{ formattedTotal(orderTotal(record)) }}</div>
                            <div v-if="record.bill" class="text-xs text-gray-500">
                                Received {{ formattedTotal(receivedTotal(record)) }} · billed {{ formattedTotal(Number(record.bill.amount)) }}
                            </div>
                        </template>
                        <template v-else-if="column.key === 'actions'">
                            <div class="flex flex-wrap justify-end gap-1">
                                <a-button v-if="record.status === 'draft' && hasPermission('purchase-orders.update')" size="small" @click="openForm(record)">Edit</a-button>
                                <a-button v-if="record.status === 'draft' && hasPermission('purchase-orders.order')" size="small" @click="act('purchase-orders.order', record)">Send</a-button>
                                <a-button
                                    v-if="['ordered', 'partial'].includes(record.status) && hasPermission('purchase-orders.receive')"
                                    size="small"
                                    type="primary"
                                    data-testid="receive-po"
                                    @click="openReceive(record)"
                                >
                                    Receive
                                </a-button>
                                <a-popconfirm
                                    v-if="['draft', 'ordered', 'partial'].includes(record.status) && hasPermission('purchase-orders.cancel')"
                                    title="Cancel this order? Anything already received stays."
                                    ok-text="Cancel order"
                                    @confirm="act('purchase-orders.cancel', record)"
                                >
                                    <a-button size="small" danger>Cancel</a-button>
                                </a-popconfirm>
                            </div>
                        </template>
                    </template>
                </a-table>
                <div v-if="orders.last_page > 1" class="mt-4 flex justify-center">
                    <a-pagination
                        :current="orders.current_page"
                        :page-size="orders.per_page"
                        :total="orders.total"
                        :show-size-changer="false"
                        @change="(p) => router.get(getRoute('purchase-orders.index'), { ...filters, page: p }, { preserveState: true })"
                    />
                </div>
            </template>
        </ContentLayout>

        <!-- Create / edit -->
        <a-modal
            :visible="formOpen"
            :title="editing ? `Edit ${editing.po_number}` : 'New purchase order'"
            :width="isMdUp ? 760 : 'calc(100vw - 24px)'"
            destroy-on-close
            @cancel="formOpen = false"
        >
            <div class="flex flex-col gap-3">
                <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
                    <div>
                        <label class="mb-1 block text-xs font-medium text-gray-700">Supplier</label>
                        <a-auto-complete
                            v-model:value="form.supplier"
                            class="w-full"
                            placeholder="Choose, or type a new supplier"
                            :options="supplierOptions"
                            :filter-option="(input, option) => option.value.toLowerCase().includes(input.toLowerCase())"
                            data-testid="po-supplier"
                        />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-gray-700">Deliver to</label>
                        <a-select v-model:value="form.location_id" class="w-full" :options="locationOptions" />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-gray-700">Expected on (optional)</label>
                        <input v-model="form.expected_date" type="date" class="w-full rounded border border-gray-300 px-2 py-1.5 text-sm" />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-gray-700">Notes (optional)</label>
                        <a-input v-model:value="form.notes" />
                    </div>
                </div>

                <a-form v-if="canPayNow" layout="vertical" class="grid grid-cols-1 gap-x-3 md:grid-cols-2" data-testid="po-pay-from">
                    <PaymentMethodFields
                        :form="payFrom"
                        :methods="paymentMethods"
                        :locations="drawerLocations"
                        :accounts="accounts"
                        optional
                        label="Pay from (optional)"
                    />
                    <p class="col-span-full -mt-3 mb-0 text-xs text-gray-500">
                        Nothing is paid yet. When the stock arrives, Receive starts Pay now from this.
                    </p>
                </a-form>

                <div class="relative">
                    <label class="mb-1 block text-xs font-medium text-gray-700">Add products</label>
                    <a-input v-model:value="productQuery" placeholder="Search product name, SKU or barcode" data-testid="po-product-search" />
                    <div v-if="productResults.length" class="absolute z-10 mt-1 max-h-56 w-full overflow-auto rounded border border-gray-200 bg-white shadow">
                        <button
                            v-for="p in productResults"
                            :key="p.id"
                            type="button"
                            class="flex w-full justify-between px-3 py-2 text-left text-sm hover:bg-gray-50"
                            @click="addProduct(p)"
                        >
                            <span>{{ p.name }} <span class="text-xs text-gray-400">{{ p.SKU }}</span></span>
                            <span class="text-xs text-gray-500">cost {{ formattedTotal(Number(p.cost ?? 0)) }}</span>
                        </button>
                    </div>
                </div>

                <div v-if="form.lines.length" class="rounded border border-gray-200">
                    <div v-for="(line, i) in form.lines" :key="line.product_id" class="grid grid-cols-12 items-center gap-2 border-t border-gray-100 px-3 py-2 first:border-t-0">
                        <div class="col-span-12 truncate font-medium md:col-span-5">{{ line.name }}</div>
                        <div class="col-span-5 md:col-span-3">
                            <a-input-number v-model:value="line.quantity_ordered" :min="0.01" class="w-full" addon-before="Qty" />
                        </div>
                        <div class="col-span-5 md:col-span-3">
                            <a-input-number v-model:value="line.unit_cost" :min="0" :precision="2" class="w-full" addon-before="Cost" />
                        </div>
                        <div class="col-span-2 text-right md:col-span-1">
                            <a-button type="text" danger size="small" @click="form.lines.splice(i, 1)">✕</a-button>
                        </div>
                    </div>
                    <div class="flex justify-end border-t border-gray-100 px-3 py-2 text-sm font-semibold">
                        Total {{ formattedTotal(formTotal) }}
                    </div>
                </div>
            </div>
            <template #footer>
                <a-button @click="formOpen = false">Close</a-button>
                <a-button :loading="saving" @click="save(false)">Save draft</a-button>
                <a-button type="primary" :loading="saving" data-testid="send-po" @click="save(true)">Send order</a-button>
            </template>
        </a-modal>

        <!-- Receive -->
        <a-modal
            :visible="!!receiving"
            :title="receiving ? `Receive ${receiving.po_number}` : ''"
            :ok-text="payNow ? 'Receive and pay' : 'Receive'"
            :confirm-loading="receivingSaving"
            destroy-on-close
            @ok="saveReceive"
            @cancel="receiving = null"
        >
            <div v-if="receiving" class="flex flex-col gap-2">
                <p class="mb-1 text-xs text-gray-500">
                    Enter what arrived. The stock goes into {{ receiving.location?.name }} and the supplier bill is updated.
                </p>
                <div v-for="line in receiving.lines" :key="line.id" class="flex items-center justify-between gap-3 text-sm">
                    <div class="min-w-0">
                        <div class="truncate font-medium">{{ line.product?.name }}</div>
                        <div class="text-xs text-gray-500">
                            {{ Number(line.quantity_received) }} of {{ Number(line.quantity_ordered) }} received
                        </div>
                    </div>
                    <a-input-number
                        v-model:value="receiveQty[line.id]"
                        :min="0"
                        :max="Number(line.quantity_ordered) - Number(line.quantity_received)"
                        class="w-28"
                    />
                </div>

                <div v-if="canPayNow" class="mt-3 border-t border-gray-100 pt-3">
                    <div class="flex items-center justify-between gap-3">
                        <div>
                            <div class="text-sm font-medium">Pay now</div>
                            <div class="text-xs text-gray-500">This delivery is worth {{ formattedTotal(deliveryValue) }}.</div>
                        </div>
                        <a-switch v-model:checked="payNow" data-testid="receive-pay-now" />
                    </div>
                    <a-form v-if="payNow" layout="vertical" class="mt-3">
                        <a-form-item
                            label="Amount paid"
                            :validate-status="payForm.errors.amount ? 'error' : ''"
                            :help="payForm.errors.amount"
                            required
                        >
                            <a-input-number
                                v-model:value="payAmountShown"
                                :min="0.01"
                                :max="Math.round((deliveryValue + billRemaining) * 100) / 100"
                                :precision="2"
                                class="w-full"
                                addon-before="₱"
                                data-testid="receive-pay-amount"
                            />
                        </a-form-item>
                        <PaymentMethodFields :form="payForm" :methods="paymentMethods" :locations="drawerLocations" :accounts="accounts" />
                        <p class="text-xs text-gray-500">The rest stays on the supplier bill as unpaid.</p>
                    </a-form>
                </div>
            </div>
        </a-modal>
    </AuthenticatedLayout>
</template>
