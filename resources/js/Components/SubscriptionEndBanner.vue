<script setup>
import { computed } from "vue";
import { usePage } from "@inertiajs/vue3";
import dayjs from "dayjs";
import { usePermissionsV2 } from "@/Composables/usePermissionV2";

/**
 * Reminds the people who handle the plan (those who can open Billing) that the
 * subscription ends within 7 days or has ended. Nothing locks by itself; a super admin does that.
 */
const page = usePage();
const { hasPermission } = usePermissionsV2();

const status = computed(() => page.props.subscriptionStatus);

const show = computed(
    () =>
        !!status.value &&
        !status.value.locked &&
        status.value.ends_in_days !== null &&
        status.value.ends_in_days <= 7 &&
        hasPermission("billing.servicing.index"),
);

const endDate = computed(() => (status.value?.ends_at ? dayjs(status.value.ends_at).format("MMM D, YYYY") : ""));

const message = computed(() => {
    const days = status.value?.ends_in_days;
    if (days < 0) return `Your subscription ended on ${endDate.value}. Contact us to renew.`;
    if (days === 0) return `Your subscription ends today (${endDate.value}). Contact us to renew.`;
    return `Your subscription ends on ${endDate.value} (in ${days} ${days === 1 ? "day" : "days"}). Contact us to renew.`;
});
</script>

<template>
    <a-alert
        v-if="show"
        :message="message"
        :type="status.ends_in_days < 0 ? 'error' : 'warning'"
        show-icon
        closable
        class="mb-3"
        data-testid="subscription-end-banner"
    />
</template>
