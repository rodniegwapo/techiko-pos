<script setup>
import { computed, ref } from "vue";
import { Head, router, usePage } from "@inertiajs/vue3";
import dayjs from "dayjs";
import { IconLock } from "@tabler/icons-vue";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import PayPlanModal from "@/Components/billing/PayPlanModal.vue";
import { openTeamChat } from "@/Composables/useTeamChat";

/**
 * What a locked organization's users see: a super admin put its subscription on hold for not paying.
 * Admins and managers can pay here with a PayMongo QR Ph code; once it's paid the organization unlocks.
 */
const props = defineProps({
    organization: { type: String, required: true },
    endedOn: { type: String, default: null },
    contactUrl: { type: String, required: true },
    canPay: { type: Boolean, default: false },
    /** PayMongo is set up (online payment can be offered). */
    paymentsReady: { type: Boolean, default: false },
    tiers: { type: Array, default: () => [] }, // { id, name, amount } per month
    currentTierId: { type: Number, default: null },
    currencySymbol: { type: String, default: "₱" },
});

const page = usePage();
const slug = computed(() => page.props.currentDomain?.name_slug);
const ended = computed(() => (props.endedOn ? dayjs(props.endedOn).format("MMMM D, YYYY") : null));
const showPayNow = computed(() => props.canPay && props.paymentsReady && props.tiers.length > 0);

const payOpen = ref(false);
const onPaid = () => setTimeout(() => router.visit(route("domains.dashboard", { domain: slug.value })), 2500);
</script>

<template>
    <AuthenticatedLayout>
        <Head title="Subscription on hold" />
        <div class="mx-auto mt-10 max-w-lg px-4">
            <section class="rounded-lg border border-red-100 bg-white px-6 py-8 text-center shadow-sm" data-testid="subscription-on-hold">
                <div class="mx-auto mb-4 flex h-12 w-12 items-center justify-center rounded-full bg-red-50 text-red-600">
                    <IconLock :size="26" />
                </div>
                <h1 class="text-xl font-bold text-gray-900">Your subscription is on hold</h1>
                <p class="mt-2 text-sm text-gray-600">
                    {{ organization }}'s pages are paused until the subscription is renewed.
                    <template v-if="ended">It ended on {{ ended }}.</template>
                    Your data is safe and nothing has been deleted.
                </p>
                <p class="mt-1 text-sm text-gray-600">Sales rung up offline will still upload.</p>
                <div class="mt-6 flex flex-wrap justify-center gap-2">
                    <a-button v-if="showPayNow" type="primary" data-testid="pay-now" @click="payOpen = true">Pay now</a-button>
                    <a-button :type="showPayNow ? 'default' : 'primary'" data-testid="contact-team" @click="openTeamChat('Our subscription is on hold. Please help us renew.')">
                        Contact us
                    </a-button>
                    <a-button @click="router.post(route('logout'))">Log out</a-button>
                </div>
                <p v-if="!canPay" class="mt-3 text-xs text-gray-500">Ask your admin to renew the subscription.</p>
            </section>
        </div>

        <PayPlanModal
            v-model:open="payOpen"
            :tiers="tiers"
            :initial-tier-id="currentTierId"
            title="Renew subscription"
            paid-message="Payment received. Your account is unlocked."
            paid-hint="Taking you to your dashboard…"
            :currency-symbol="currencySymbol"
            @paid="onPaid"
        />
    </AuthenticatedLayout>
</template>
