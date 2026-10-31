<script setup>
import { computed, watch } from "vue";

/**
 * Paid by bank transfer or e-wallet: which of the business's accounts the money left (optional).
 * An account linked to a payment channel takes the money off that channel's balance in the wallet;
 * an unlinked one is only a label for reports.
 */
const props = defineProps({
    form: { type: Object, required: true },
    accounts: { type: Array, default: () => [] },
    size: { type: String, default: undefined },
});

const TYPES_FOR_METHOD = { bank: ["bank", "other"], ewallet: ["ewallet"] };

const options = computed(() => {
    const types = TYPES_FOR_METHOD[props.form.payment_method] ?? [];
    return props.accounts
        .filter((a) => types.includes(a.type))
        .map((a) => ({ value: a.id, label: a.name, linked: !!a.payment_card_type_id }));
});

const picked = computed(() => options.value.find((o) => o.value === props.form.financial_account_id));

const help = computed(() => {
    if (props.form.errors.financial_account_id) return props.form.errors.financial_account_id;
    if (!picked.value) return undefined;
    return picked.value.linked
        ? "Taken off this account's payment channel in Cash & wallet."
        : "Not linked to a payment channel, so no wallet balance changes. Link it under Finance → Bank, loans & equipment.";
});

// Another method, or an account that doesn't fit it: nothing to keep.
watch(options, (list) => {
    if (props.form.financial_account_id && !list.some((o) => o.value === props.form.financial_account_id)) {
        props.form.financial_account_id = null;
    }
});
</script>

<template>
    <a-form-item
        v-if="options.length"
        label="Account"
        :validate-status="form.errors.financial_account_id ? 'error' : picked && !picked.linked ? 'warning' : ''"
        :help="help"
    >
        <a-select
            v-model:value="form.financial_account_id"
            :options="options"
            placeholder="Which account? (optional)"
            allow-clear
            :size="size"
            data-testid="paid-from-account"
        />
    </a-form-item>
</template>
