<script setup>
import { computed, ref, watch } from "vue";
import axios from "axios";
import { message } from "ant-design-vue";
import { IconPrinter } from "@tabler/icons-vue";
import SaleReceipt from "@/Components/Receipt/SaleReceipt.vue";
import { useDomainRoutes } from "@/Composables/useDomainRoutes";
import { useHelpers } from "@/Composables/useHelpers";

const props = defineProps({
    saleId: { type: [Number, null], default: null },
    businessName: { type: String, default: "" },
});
const emit = defineEmits(["close"]);

const { getRoute } = useDomainRoutes();
const { formattedTotal } = useHelpers();

const loading = ref(false);
const sale = ref(null);

const open = computed(() => props.saleId !== null);

watch(
    () => props.saleId,
    async (id) => {
        sale.value = null;
        if (id === null) return;
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
    },
);

/** vue3-print-nb finds the node by id; bind v-print to a native element so the click always attaches. */
const printOptions = computed(() => ({
    id: "sale-receipt-print-area",
    popTitle: sale.value?.invoice_number ? `Receipt ${sale.value.invoice_number}` : "Receipt",
}));

const statusColor = (status) =>
    ({ paid: "green", partial: "orange", refunded: "red" })[status] || "default";

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
                        {{ sale.payment_method }}<span v-if="sale.payment_card_type"> · {{ sale.payment_card_type }}</span>
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
            <div class="flex justify-end">
                <span v-print="printOptions">
                    <a-button type="primary" :disabled="!sale" class="flex items-center gap-2">
                        <template #icon><IconPrinter :size="18" /></template>
                        Reprint receipt
                    </a-button>
                </span>
            </div>
        </template>
    </a-drawer>
</template>
