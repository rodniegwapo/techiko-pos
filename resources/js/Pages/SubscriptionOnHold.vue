<script setup>
import { computed, onBeforeUnmount, reactive, ref } from "vue";
import { Head, router, usePage } from "@inertiajs/vue3";
import axios from "axios";
import dayjs from "dayjs";
import { IconCircleCheck, IconLock } from "@tabler/icons-vue";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";

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
const money = (amount) => `${props.currencySymbol}${Number(amount).toLocaleString("en-PH", { minimumFractionDigits: 2 })}`;

/*** === PAY NOW (choose plan & months → QR → wait for PayMongo) === ***/
const pay = reactive({
    open: false,
    step: "choose", // choose | qr | paid
    tierId: props.currentTierId ?? props.tiers[0]?.id ?? null,
    months: 1,
    starting: false,
    error: null,
    qr: null, // { payment_intent_id, qr_image_data_url, amount, months, tier_name, expires_at }
    nextDueOn: null,
});
const now = ref(Date.now());
let poll = null;
let tick = null;

const tier = computed(() => props.tiers.find((t) => t.id === pay.tierId));
const total = computed(() => (tier.value ? tier.value.amount * pay.months : 0));
const secondsLeft = computed(() =>
    pay.qr?.expires_at ? Math.max(0, Math.floor((new Date(pay.qr.expires_at).getTime() - now.value) / 1000)) : null,
);
const expired = computed(() => secondsLeft.value === 0);
const countdown = computed(() => {
    const s = secondsLeft.value ?? 0;
    return `${Math.floor(s / 60)}:${String(s % 60).padStart(2, "0")}`;
});

const stopWaiting = () => {
    clearInterval(poll);
    clearInterval(tick);
    poll = tick = null;
};

const openPay = () => {
    Object.assign(pay, { open: true, step: "choose", error: null, qr: null });
};

const showQr = async () => {
    pay.starting = true;
    pay.error = null;
    try {
        const { data } = await axios.post(route("domains.renew.start", { domain: slug.value }), {
            service_tier_id: pay.tierId,
            months: pay.months,
        });
        pay.qr = data;
        pay.step = "qr";
        startWaiting();
    } catch (e) {
        const errors = e?.response?.data?.errors ?? {};
        pay.error = Object.values(errors).flat()[0] || e?.response?.data?.message || "The QR code couldn't be made. Please try again.";
    } finally {
        pay.starting = false;
    }
};

/** Asks every few seconds whether PayMongo has the payment; also counts the code down. */
const startWaiting = () => {
    stopWaiting();
    now.value = Date.now();
    tick = setInterval(() => (now.value = Date.now()), 1000);
    poll = setInterval(async () => {
        if (!pay.open || pay.step !== "qr" || expired.value) return;
        try {
            const { data } = await axios.get(route("domains.renew.status", { domain: slug.value }), {
                params: { payment_intent_id: pay.qr.payment_intent_id },
            });
            if (data.paid) {
                stopWaiting();
                pay.nextDueOn = data.next_due_on;
                pay.step = "paid";
                setTimeout(() => router.visit(route("domains.dashboard", { domain: slug.value })), 2500);
            }
        } catch {
            // Try again on the next tick.
        }
    }, 4000);
};

const backToChoose = () => {
    stopWaiting();
    Object.assign(pay, { step: "choose", qr: null, error: null });
};

const closePay = () => {
    stopWaiting();
    pay.open = false;
};

onBeforeUnmount(stopWaiting);
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
                    <a-button v-if="showPayNow" type="primary" data-testid="pay-now" @click="openPay">Pay now</a-button>
                    <a :href="contactUrl">
                        <a-button :type="showPayNow ? 'default' : 'primary'">Contact us</a-button>
                    </a>
                    <a-button @click="router.post(route('logout'))">Log out</a-button>
                </div>
                <p v-if="!canPay" class="mt-3 text-xs text-gray-500">Ask your admin to renew the subscription.</p>
            </section>
        </div>

        <a-modal
            :visible="pay.open"
            :title="pay.step === 'paid' ? 'Payment received' : 'Renew subscription'"
            :footer="null"
            :mask-closable="false"
            width="440px"
            @cancel="closePay"
        >
            <!-- 1. Choose the plan and how many months -->
            <div v-if="pay.step === 'choose'" class="space-y-4" data-testid="pay-choose">
                <div class="space-y-2">
                    <button
                        v-for="t in tiers"
                        :key="t.id"
                        type="button"
                        class="flex w-full items-center justify-between rounded-lg border px-3 py-2 text-left text-sm transition-colors"
                        :class="pay.tierId === t.id ? 'border-blue-500 bg-blue-50' : 'border-gray-200 hover:border-gray-300'"
                        @click="pay.tierId = t.id"
                    >
                        <span class="font-medium text-gray-900">{{ t.name }}</span>
                        <span class="text-gray-600">{{ money(t.amount) }} / month</span>
                    </button>
                </div>
                <div class="flex items-center justify-between gap-2">
                    <span class="text-sm font-medium text-gray-700">Months</span>
                    <a-radio-group v-model:value="pay.months" button-style="solid" size="small">
                        <a-radio-button v-for="m in [1, 3, 6, 12]" :key="m" :value="m">{{ m }}</a-radio-button>
                    </a-radio-group>
                </div>
                <p class="rounded-md bg-gray-50 px-3 py-2 text-sm text-gray-800" data-testid="pay-total">
                    <template v-if="pay.months > 1">{{ money(tier?.amount ?? 0) }} × {{ pay.months }} = </template>
                    <strong>{{ money(total) }}</strong>
                </p>
                <a-alert v-if="pay.error" type="error" show-icon :message="pay.error" data-testid="pay-error" />
                <a-button type="primary" block :loading="pay.starting" :disabled="!tier" data-testid="show-qr" @click="showQr">
                    Show QR code
                </a-button>
            </div>

            <!-- 2. Scan and wait for PayMongo -->
            <div v-else-if="pay.step === 'qr'" class="space-y-3 text-center" data-testid="pay-qr">
                <p class="text-sm text-gray-600">
                    {{ pay.qr.tier_name }}<template v-if="pay.qr.months > 1"> · {{ pay.qr.months }} months</template>
                </p>
                <p class="text-2xl font-bold text-gray-900">{{ money(pay.qr.amount) }}</p>
                <template v-if="!expired">
                    <img
                        v-if="pay.qr.qr_image_data_url"
                        :src="pay.qr.qr_image_data_url"
                        alt="QR Ph code to pay"
                        class="mx-auto h-56 w-56 rounded border border-gray-200"
                    />
                    <p class="text-sm text-gray-600">Scan with your bank or e-wallet app (QR Ph).</p>
                    <p class="flex items-center justify-center gap-2 text-xs text-gray-500">
                        <a-spin size="small" /> Waiting for payment · code expires in {{ countdown }}
                    </p>
                </template>
                <template v-else>
                    <a-alert type="warning" show-icon message="This code expired." />
                    <a-button type="primary" :loading="pay.starting" @click="showQr">New code</a-button>
                </template>
                <a-button type="link" size="small" @click="backToChoose">Change plan or months</a-button>
            </div>

            <!-- 3. Paid: unlocked -->
            <div v-else class="space-y-2 py-4 text-center" data-testid="pay-paid">
                <IconCircleCheck :size="44" class="mx-auto text-green-600" />
                <p class="font-semibold text-gray-900">Payment received. Your account is unlocked.</p>
                <p v-if="pay.nextDueOn" class="text-sm text-gray-600">
                    Next payment is due {{ dayjs(pay.nextDueOn).format("MMMM D, YYYY") }}.
                </p>
                <p class="text-xs text-gray-500">Taking you to your dashboard…</p>
            </div>
        </a-modal>
    </AuthenticatedLayout>
</template>
