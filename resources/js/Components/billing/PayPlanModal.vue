<script setup>
import { computed, onBeforeUnmount, reactive, ref, watch } from "vue";
import { usePage } from "@inertiajs/vue3";
import axios from "axios";
import dayjs from "dayjs";
import { IconCircleCheck } from "@tabler/icons-vue";

/**
 * Pays for a plan with a PayMongo QR Ph code: choose the plan and months, scan, and wait until
 * PayMongo has the money (domains.renew.start / domains.renew.status). A paid code records the
 * payment, which switches the organization to that plan and moves its paid-through date.
 * Used to renew (Subscription on hold) and to upgrade (Billing).
 */
const props = defineProps({
    open: { type: Boolean, default: false },
    tiers: { type: Array, default: () => [] }, // { id, name, amount } per month
    initialTierId: { type: Number, default: null },
    title: { type: String, default: "Pay for your plan" },
    /** Shown once paid, above the next due date. */
    paidMessage: { type: String, default: "Payment received." },
    /** Shown under the paid message (e.g. where the page goes next). */
    paidHint: { type: String, default: "" },
    /** Explains what paying changes (shown under the total). */
    note: { type: String, default: "" },
    currencySymbol: { type: String, default: "₱" },
});

const emit = defineEmits(["update:open", "paid"]);

const page = usePage();
const slug = computed(() => page.props.currentDomain?.name_slug);
const money = (amount) => `${props.currencySymbol}${Number(amount).toLocaleString("en-PH", { minimumFractionDigits: 2 })}`;

const pay = reactive({
    step: "choose", // choose | qr | paid
    tierId: null,
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
const noteText = computed(() =>
    props.note.replace("{plan}", tier.value?.name ?? "The plan").replace("{months}", pay.months === 1 ? "1 month" : `${pay.months} months`),
);

const stopWaiting = () => {
    clearInterval(poll);
    clearInterval(tick);
    poll = tick = null;
};

// Each time it opens, start over on the plan it was opened for.
watch(
    () => props.open,
    (open) => {
        if (open) {
            Object.assign(pay, {
                step: "choose",
                tierId: props.initialTierId ?? props.tiers[0]?.id ?? null,
                months: 1,
                error: null,
                qr: null,
            });
        } else {
            stopWaiting();
        }
    },
    { immediate: true },
);

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
        if (!props.open || pay.step !== "qr" || expired.value) return;
        try {
            const { data } = await axios.get(route("domains.renew.status", { domain: slug.value }), {
                params: { payment_intent_id: pay.qr.payment_intent_id },
            });
            if (data.paid) {
                stopWaiting();
                pay.nextDueOn = data.next_due_on;
                pay.step = "paid";
                emit("paid", { next_due_on: data.next_due_on });
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

const close = () => emit("update:open", false);

onBeforeUnmount(stopWaiting);
</script>

<template>
    <a-modal
        :visible="open"
        :title="pay.step === 'paid' ? 'Payment received' : title"
        :footer="null"
        :mask-closable="false"
        width="440px"
        @cancel="close"
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
                    :data-testid="`pay-tier-${t.id}`"
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
            <p v-if="noteText" class="text-xs text-gray-500" data-testid="pay-note">{{ noteText }}</p>
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

        <!-- 3. Paid -->
        <div v-else class="space-y-2 py-4 text-center" data-testid="pay-paid">
            <IconCircleCheck :size="44" class="mx-auto text-green-600" />
            <p class="font-semibold text-gray-900">{{ paidMessage }}</p>
            <p v-if="pay.nextDueOn" class="text-sm text-gray-600">
                Next payment is due {{ dayjs(pay.nextDueOn).format("MMMM D, YYYY") }}.
            </p>
            <p v-if="paidHint" class="text-xs text-gray-500">{{ paidHint }}</p>
        </div>
    </a-modal>
</template>
