<script setup>
/**
 * Customer receipt for a completed sale, laid out for an 80mm thermal roll.
 * Expects the payload of `sales-history.show`. Voided lines are left off — the receipt
 * shows what the customer paid for.
 */
import { computed } from "vue";
import { useHelpers } from "@/Composables/useHelpers";

const props = defineProps({
    sale: { type: Object, required: true },
    businessName: { type: String, default: "" },
});

const { formattedTotal } = useHelpers();

const items = computed(() => (props.sale.items || []).filter((i) => !i.voided));

const vatLabel = computed(() => {
    const rate = props.sale.vat?.vat_rate_percent;
    if (!rate) return "VAT";
    return props.sale.vat?.vat_pricing_mode === "inclusive"
        ? `VAT ${rate}% (incl.)`
        : `VAT ${rate}%`;
});

const paymentLabel = computed(() => {
    const method = (props.sale.payment_method || "").toUpperCase();
    return props.sale.payment_card_type
        ? `${method} · ${props.sale.payment_card_type}`
        : method;
});
</script>

<template>
    <div class="sale-receipt">
        <div class="center bold big">{{ businessName }}</div>
        <div v-if="sale.location_name" class="center">{{ sale.location_name }}</div>
        <div class="center">OFFICIAL RECEIPT (REPRINT)</div>

        <div class="rule" />
        <div class="row"><span>Invoice #</span><span>{{ sale.invoice_number || `#${sale.id}` }}</span></div>
        <div class="row"><span>Date</span><span>{{ sale.transaction_date_display }}</span></div>
        <div class="row"><span>Cashier</span><span>{{ sale.cashier_name || "—" }}</span></div>
        <div class="row"><span>Customer</span><span>{{ sale.customer_name }}</span></div>
        <div class="rule" />

        <div v-for="item in items" :key="item.id" class="item">
            <div>{{ item.product_name }}</div>
            <div class="row">
                <span>{{ item.quantity }} x {{ formattedTotal(item.unit_price) }}</span>
                <span>{{ formattedTotal(item.subtotal) }}</span>
            </div>
            <div v-if="item.discount > 0" class="row muted">
                <span>  Item discount</span><span>-{{ formattedTotal(item.discount) }}</span>
            </div>
        </div>

        <div class="rule" />
        <div class="row"><span>Subtotal</span><span>{{ formattedTotal(sale.total_amount) }}</span></div>
        <div v-for="(d, i) in sale.discounts" :key="i" class="row">
            <span>{{ d.name }}</span><span>-{{ formattedTotal(d.amount) }}</span>
        </div>
        <div v-if="sale.loyalty_discount_amount > 0" class="row">
            <span>Loyalty ({{ sale.loyalty_points_redeemed }} pts)</span>
            <span>-{{ formattedTotal(sale.loyalty_discount_amount) }}</span>
        </div>
        <div v-if="Number(sale.tax_amount) > 0" class="row"><span>{{ vatLabel }}</span><span>{{ formattedTotal(sale.tax_amount) }}</span></div>
        <div class="row bold big"><span>TOTAL</span><span>{{ formattedTotal(sale.grand_total) }}</span></div>
        <div class="row"><span>Paid by</span><span>{{ paymentLabel }}</span></div>

        <div class="rule" />
        <div class="center">Thank you!</div>
    </div>
</template>

<style scoped>
.sale-receipt {
    width: 72mm;
    padding: 2mm;
    font-family: "Courier New", monospace;
    font-size: 12px;
    line-height: 1.35;
    color: #000;
    background: #fff;
}
.center { text-align: center; }
.bold { font-weight: 700; }
.big { font-size: 14px; }
.muted { color: #444; }
.rule { border-top: 1px dashed #000; margin: 4px 0; }
.row { display: flex; justify-content: space-between; gap: 8px; }
.item { margin-bottom: 2px; }
</style>
