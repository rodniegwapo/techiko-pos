<script setup>
import { computed, ref, watch } from "vue";
import axios from "axios";
import { message } from "ant-design-vue";
import { IconPrinter, IconReceiptOff } from "@tabler/icons-vue";
import SaleReceipt from "@/Components/Receipt/SaleReceipt.vue";
import VoidProductModal from "@/Pages/Sales/components/VoidProductModal.vue";
import { useDomainRoutes } from "@/Composables/useDomainRoutes";
import { useHelpers } from "@/Composables/useHelpers";
import { usePermissionsV2 } from "@/Composables/usePermissionV2";

const props = defineProps({
    saleId: { type: [Number, null], default: null },
    businessName: { type: String, default: "" },
});
const emit = defineEmits(["close", "voided"]);

const { getRoute } = useDomainRoutes();
const { formattedTotal } = useHelpers();
const { hasPermission } = usePermissionsV2();

const loading = ref(false);
const sale = ref(null);

const open = computed(() => props.saleId !== null);

const loadSale = async (id) => {
    loading.value = true;
    try {
        const { data } = await axios.get(getRoute("sales-history.show", { sale: id }));
        sale.value = data;
    } catch (e) {
        message.error("Could not load this sale.");
        emit("close");
    } finally {
        loading.value = false;
    }
};

watch(
    () => props.saleId,
    (id) => {
        sale.value = null;
        if (id === null) return;
        loadSale(id);
    },
);

// Void the whole receipt: manager PIN and a reason, like voiding a cart item.
const canVoid = computed(() => sale.value?.can_void && hasPermission("sales-history.void"));
const voidVisible = ref(false);
const voidLoading = ref(false);
const voidErrors = ref({});

const openVoid = () => {
    voidErrors.value = {};
    voidVisible.value = true;
};

const submitVoid = async ({ pin_code, reason }) => {
    voidLoading.value = true;
    voidErrors.value = {};
    try {
        await axios.post(getRoute("sales-history.void", { sale: sale.value.id }), { pin_code, reason });
        voidVisible.value = false;
        message.success("Sale voided. Its stock was returned to the store.");
        await loadSale(sale.value.id);
        emit("voided");
    } catch (e) {
        const errors = e.response?.data?.errors || {};
        voidErrors.value = errors;
        // Errors not tied to the PIN or reason (e.g. a credit sale already paid on) go in a toast.
        if (!errors.pin_code && !errors.reason) {
            message.error(errors.sale?.[0] || e.response?.data?.message || "Could not void this sale.");
        }
    } finally {
        voidLoading.value = false;
    }
};

/** vue3-print-nb finds the node by id; bind v-print to a native element so the click always attaches. */
const printOptions = computed(() => ({
    id: "sale-receipt-print-area",
    popTitle: sale.value?.invoice_number ? `Receipt ${sale.value.invoice_number}` : "Receipt",
}));

const statusColor = (status) =>
    ({ paid: "green", partial: "orange", refunded: "red", voided: "red" })[status] || "default";

const itemColumns = [
    { title: "Item", key: "product_name", dataIndex: "product_name" },
    { title: "Qty", key: "quantity", dataIndex: "quantity", align: "right", width: 60 },
    { title: "Price", key: "unit_price", dataIndex: "unit_price", align: "right" },
    { title: "Disc.", key: "discount", dataIndex: "discount", align: "right" },
    { title: "Subtotal", key: "subtotal", dataIndex: "subtotal", align: "right" },
];
</script>

<template>
    <a-drawer
        :visible="open"
        :width="560"
        :title="sale ? `Sale ${sale.invoice_number || '#' + sale.id}` : 'Sale'"
        @close="emit('close')"
    >
        <a-spin :spinning="loading">
            <div v-if="sale" class="space-y-5" data-testid="sale-detail">
                <a-alert
                    v-if="sale.void"
                    type="error"
                    show-icon
                    message="This sale was voided"
                    data-testid="sale-voided"
                >
                    <template #description>
                        {{ sale.void.voided_at }} · by {{ sale.void.voided_by || "—" }}
                        <span v-if="sale.void.approved_by"> · approved by {{ sale.void.approved_by }}</span>
                        <div v-if="sale.void.reason">Reason: {{ sale.void.reason }}</div>
                        <div class="text-xs">Its stock was returned, and it no longer counts in sales totals or the cash drawer.</div>
                    </template>
                </a-alert>
                <a-descriptions :column="2" size="small" bordered>
                    <a-descriptions-item label="Date" :span="2">{{ sale.transaction_date_display }}</a-descriptions-item>
                    <a-descriptions-item label="Cashier">{{ sale.cashier_name || "—" }}</a-descriptions-item>
                    <a-descriptions-item label="Customer">{{ sale.customer_name }}</a-descriptions-item>
                    <a-descriptions-item label="Location">{{ sale.location_name || "—" }}</a-descriptions-item>
                    <a-descriptions-item label="Status">
                        <a-tag :color="statusColor(sale.payment_status)">{{ sale.payment_status }}</a-tag>
                        <a-tag v-if="sale.is_credit_sale" color="purple">credit</a-tag>
                    </a-descriptions-item>
                    <a-descriptions-item label="Payment" :span="2">
                        <template v-if="sale.payments?.length">
                            <a-tag color="blue">split</a-tag>
                            <div v-for="(p, i) in sale.payments" :key="i" class="text-sm">
                                {{ p.label }} · {{ formattedTotal(p.amount) }}<span v-if="p.tendered != null && p.tendered > p.amount" class="text-gray-500"> (tendered {{ formattedTotal(p.tendered) }})</span><span v-if="p.reference" class="text-gray-500"> · Ref {{ p.reference }}</span>
                            </div>
                        </template>
                        <template v-else>
                            {{ sale.payment_method }}<span v-if="sale.payment_card_type"> · {{ sale.payment_card_type }}</span><span v-if="sale.payment_reference"> · Ref {{ sale.payment_reference }}</span>
                        </template>
                    </a-descriptions-item>
                    <a-descriptions-item v-if="sale.notes" label="Notes" :span="2">{{ sale.notes }}</a-descriptions-item>
                </a-descriptions>

                <div>
                    <h3 class="mb-2 text-sm font-semibold text-gray-900">Items</h3>
                    <a-table
                        :columns="itemColumns"
                        :data-source="sale.items"
                        :pagination="false"
                        :row-key="(r) => r.id"
                        size="small"
                    >
                        <template #bodyCell="{ column, record }">
                            <template v-if="column.key === 'product_name'">
                                <span :class="{ 'text-gray-400 line-through': record.voided }">{{ record.product_name }}</span>
                                <div v-if="record.modifiers?.length || record.notes" class="text-xs text-gray-500">
                                    {{ (record.modifiers || []).map((m) => m.name).join(", ") }}<span v-if="record.notes"><span v-if="record.modifiers?.length"> · </span>{{ record.notes }}</span>
                                </div>
                                <a-tag v-if="record.voided" color="red" class="ml-2">Voided</a-tag>
                            </template>
                            <template v-else-if="['unit_price', 'discount', 'subtotal'].includes(column.key)">
                                <span :class="{ 'text-gray-400 line-through': record.voided }">
                                    {{ formattedTotal(record[column.key]) }}
                                </span>
                            </template>
                        </template>
                    </a-table>
                </div>

                <div class="space-y-1 rounded-lg border border-gray-200 p-3 text-sm">
                    <div class="flex justify-between"><span>Subtotal</span><span>{{ formattedTotal(sale.total_amount) }}</span></div>
                    <div v-for="(d, i) in sale.discounts" :key="i" class="flex justify-between text-gray-600">
                        <span>{{ d.name }} <span class="text-xs">({{ d.type }})</span></span>
                        <span>-{{ formattedTotal(d.amount) }}</span>
                    </div>
                    <div v-if="sale.loyalty_discount_amount > 0" class="flex justify-between text-gray-600">
                        <span>Loyalty ({{ sale.loyalty_points_redeemed }} pts)</span>
                        <span>-{{ formattedTotal(sale.loyalty_discount_amount) }}</span>
                    </div>
                    <div class="flex justify-between text-gray-600"><span>VAT</span><span>{{ formattedTotal(sale.tax_amount) }}</span></div>
                    <div class="flex justify-between border-t pt-1 font-semibold">
                        <span>Grand total</span><span>{{ formattedTotal(sale.grand_total) }}</span>
                    </div>
                </div>

                <div v-if="sale.profit" data-testid="sale-profit">
                    <h3 class="mb-2 text-sm font-semibold text-gray-900">Profit</h3>
                    <div class="space-y-1 rounded-lg border border-emerald-200 bg-emerald-50/40 p-3 text-sm">
                        <div class="flex justify-between">
                            <span>Grand total</span><span>{{ formattedTotal(sale.profit.grand_total) }}</span>
                        </div>
                        <div class="flex justify-between text-gray-600">
                            <span>Less VAT <span class="text-xs">(collected for the government)</span></span>
                            <span>-{{ formattedTotal(sale.profit.vat) }}</span>
                        </div>
                        <div class="flex justify-between border-t pt-1">
                            <span>Revenue, ex-VAT</span><span>{{ formattedTotal(sale.profit.revenue) }}</span>
                        </div>
                        <div class="flex justify-between text-gray-600">
                            <span>Less cost of items sold</span><span>-{{ formattedTotal(sale.profit.cogs) }}</span>
                        </div>
                        <div v-for="(line, i) in sale.profit.lines" :key="i" class="flex justify-between pl-3 text-xs text-gray-500">
                            <span>
                                {{ line.product_name }} · {{ line.quantity }} ×
                                {{ line.unit_cost === null ? "no cost set" : formattedTotal(line.unit_cost) }}
                            </span>
                            <span>{{ line.line_cost === null ? "—" : formattedTotal(line.line_cost) }}</span>
                        </div>
                        <div class="flex justify-between border-t pt-1 font-semibold text-emerald-700">
                            <span>
                                Profit
                                <span v-if="sale.profit.margin_percent !== null" class="text-xs font-normal">
                                    ({{ sale.profit.margin_percent }}% margin)
                                </span>
                            </span>
                            <span>{{ formattedTotal(sale.profit.profit) }}</span>
                        </div>
                    </div>
                    <p class="mb-0 mt-2 text-xs text-gray-500">
                        Profit is what the customer paid, minus VAT, minus what the items cost you. Expenses like rent and salaries are not included.
                        Each item uses the cost it had when it was sold, so later cost changes don't affect it.
                        Voided items are not counted.
                    </p>
                    <a-alert
                        v-if="sale.profit.items_missing_cost"
                        type="warning"
                        show-icon
                        class="mt-2"
                        :message="`${sale.profit.items_missing_cost} item(s) have no cost set, so they count as free and profit looks higher than it really is. Set a cost on the product to fix future sales.`"
                    />
                </div>

                <div v-if="sale.void_logs.length">
                    <h3 class="mb-2 text-sm font-semibold text-gray-900">Voids</h3>
                    <a-list size="small" bordered :data-source="sale.void_logs">
                        <template #renderItem="{ item }">
                            <a-list-item>
                                <div class="w-full text-sm">
                                    <div class="flex justify-between">
                                        <span class="font-medium">{{ item.product_name || "Item" }}</span>
                                        <span>{{ formattedTotal(item.amount) }}</span>
                                    </div>
                                    <div class="text-xs text-gray-500">
                                        {{ item.voided_at }} · by {{ item.voided_by || "—" }}
                                        <span v-if="item.approved_by"> · approved by {{ item.approved_by }}</span>
                                    </div>
                                    <div v-if="item.reason" class="text-xs text-gray-600">Reason: {{ item.reason }}</div>
                                </div>
                            </a-list-item>
                        </template>
                    </a-list>
                </div>

                <!-- Hidden print source for vue3-print-nb -->
                <div class="hidden" aria-hidden="true">
                    <div id="sale-receipt-print-area">
                        <SaleReceipt :sale="sale" :business-name="businessName" />
                    </div>
                </div>
            </div>
        </a-spin>

        <template #footer>
            <div class="flex justify-end gap-2">
                <a-button
                    v-if="canVoid"
                    danger
                    class="flex items-center gap-2"
                    data-testid="void-sale"
                    @click="openVoid"
                >
                    <template #icon><IconReceiptOff :size="18" /></template>
                    Void receipt
                </a-button>
                <span v-print="printOptions">
                    <a-button type="primary" :disabled="!sale" class="flex items-center gap-2">
                        <template #icon><IconPrinter :size="18" /></template>
                        Reprint receipt
                    </a-button>
                </span>
            </div>
        </template>

        <VoidProductModal
            v-if="sale"
            v-model:visible="voidVisible"
            title="Void Receipt"
            item-field-label="Invoice"
            :amount="formattedTotal(sale.grand_total)"
            :item-label="sale.invoice_number || `#${sale.id}`"
            :submit-loading="voidLoading"
            :errors="voidErrors"
            @submit="submitVoid"
        />
    </a-drawer>
</template>
