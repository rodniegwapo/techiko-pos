<script setup>
import { computed } from "vue";
import { paymentMethodLabel } from "@/Pages/Expenses/paymentMethods";
import PaidFromAccountSelect from "./PaidFromAccountSelect.vue";

/**
 * How money was paid (or received), in the same terms as Expenses. Through the cash register
 * means a store's cash drawer, so that store's Cash in & out shows it too.
 */
const props = defineProps({
    form: { type: Object, required: true },
    direction: { type: String, default: "out" },
    methods: { type: Array, default: () => [] },
    locations: { type: Array, default: () => [] },
    /** Bank and e-wallet accounts to pick from; leave empty where the account isn't recorded. */
    accounts: { type: Array, default: () => [] },
    /** Only stores have a cash drawer; warehouses are left out. */
    storesOnly: { type: Boolean, default: true },
    /** Choosing is optional (e.g. a purchase order's "Pay from"); a label in place of "Paid by". */
    optional: { type: Boolean, default: false },
    label: { type: String, default: null },
});

const drawerOptions = computed(() =>
    props.locations
        .filter((l) => !props.storesOnly || l.type !== "warehouse")
        .map((l) => ({ value: l.id, label: l.name })),
);
</script>

<template>
    <a-form-item
        :label="label ?? (direction === 'out' ? 'Paid by' : 'Received by')"
        :validate-status="form.errors.payment_method ? 'error' : ''"
        :help="form.errors.payment_method"
        :required="!optional"
    >
        <a-select v-model:value="form.payment_method" data-testid="payment-method"
            :allow-clear="optional" :placeholder="optional ? 'Decide when it arrives' : undefined"
            :options="methods.map((m) => ({ value: m, label: paymentMethodLabel(m) }))" />
        <p v-if="form.payment_method === 'cash_register' && !optional" class="mt-1 text-xs text-gray-500">
            Also recorded in that store's Cash in &amp; out, so the expected drawer cash stays right.
        </p>
    </a-form-item>

    <a-form-item
        v-if="form.payment_method === 'cash_register'"
        label="Cash drawer"
        :validate-status="form.errors.location_id ? 'error' : ''"
        :help="form.errors.location_id"
        required
    >
        <a-select v-model:value="form.location_id" placeholder="Which store's cash drawer?"
            data-testid="cash-drawer" :options="drawerOptions" />
    </a-form-item>

    <PaidFromAccountSelect v-if="accounts.length" :form="form" :accounts="accounts" />
</template>
