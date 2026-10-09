<script setup>
import { paymentMethodLabel } from "@/Pages/Expenses/paymentMethods";

/**
 * How money was paid (or received), in the same terms as Expenses. Through the cash register
 * means a store's drawer, so that store's cash ledger shows it too.
 */
defineProps({
    form: { type: Object, required: true },
    direction: { type: String, default: "out" },
    methods: { type: Array, default: () => [] },
    locations: { type: Array, default: () => [] },
});
</script>

<template>
    <a-form-item
        :label="direction === 'out' ? 'Paid by' : 'Received by'"
        :validate-status="form.errors.payment_method ? 'error' : ''"
        :help="form.errors.payment_method"
        required
    >
        <a-select v-model:value="form.payment_method" data-testid="payment-method"
            :options="methods.map((m) => ({ value: m, label: paymentMethodLabel(m) }))" />
        <p v-if="form.payment_method === 'cash_register'" class="mt-1 text-xs text-gray-500">
            Also recorded in that store's cash ledger, so the expected drawer cash stays right.
        </p>
    </a-form-item>

    <a-form-item
        v-if="form.payment_method === 'cash_register'"
        label="Store"
        :validate-status="form.errors.location_id ? 'error' : ''"
        :help="form.errors.location_id"
        required
    >
        <a-select v-model:value="form.location_id" placeholder="Which store's cash register?"
            :options="locations.map((l) => ({ value: l.id, label: l.name }))" />
    </a-form-item>
</template>
