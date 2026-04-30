<script setup>
import {
    computed,
    watch,
    onBeforeUnmount,
} from "vue";
import { Head, useForm, usePage, router } from "@inertiajs/vue3";
import axios from "axios";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import ContentHeader from "@/Components/ContentHeader.vue";
import ContentLayout from "@/Components/ContentLayout.vue";
import ManualGcashDesktopQrAside from "@/Pages/Billing/ManualGcashDesktopQrAside.vue";
import ManualGcashPayment from "@/Pages/Billing/ManualGcashPayment.vue";
import { useDomainRoutes } from "@/Composables/useDomainRoutes";
import { message } from "ant-design-vue";

const props = defineProps({
    tiers: { type: Array, default: () => [] },
    gcashQrUrl: { type: String, default: "/images/gcash-qr.svg" },
    currencySymbol: { type: String, default: "₱" },
    currentDomain: { type: Object, default: () => ({}) },
    subscription: {
        type: Object,
        default: null,
    },
    paymongoQr: {
        type: Object,
        default: null,
    },
    paymongoConfigured: {
        type: Boolean,
        default: false,
    },
    showManualGcash: {
        type: Boolean,
        default: false,
    },
});

const page = usePage();
const { getRoute } = useDomainRoutes();

const form = useForm({
    service_tier_id: null,
    gcash_reference: "",
});

const qrphForm = useForm({
    service_tier_id: null,
});

const hasTiers = computed(() => Array.isArray(props.tiers) && props.tiers.length > 0);

const selectedTier = computed(() =>
    props.tiers.find((t) => t.id === form.service_tier_id),
);

const selectedTierUsesBundleQrPh = computed(
    () => !!selectedTier.value?.uses_vite_bundle_qrph,
);

const qrSrc = computed(() => {
    const p = props.gcashQrUrl || "";
    if (p.startsWith("http")) {
        return p;
    }
    return p.startsWith("/") ? p : `/${p}`;
});

const showPlaceholderQrNotice = computed(
    () =>
        import.meta.env.DEV &&
        String(props.gcashQrUrl || "").includes("gcash-qr.svg"),
);

const paymongoExpiresLabel = computed(() => {
    const raw = props.paymongoQr?.expires_at;
    if (!raw) {
        return "Pay within a few minutes—QR Ph codes expire quickly.";
    }
    const d = new Date(raw);
    if (Number.isNaN(d.getTime())) {
        return "Pay within a few minutes—QR Ph codes expire quickly.";
    }
    return `Pay before ${d.toLocaleString(undefined, {
        dateStyle: "medium",
        timeStyle: "short",
    })}`;
});

const hasBundledQrPhOffered = computed(() =>
    props.tiers.some((t) => t.uses_vite_bundle_qrph),
);

const stepCurrentGcash = computed(() => {
    if (!form.service_tier_id) {
        return 0;
    }
    if (!String(form.gcash_reference || "").trim()) {
        return 1;
    }
    return 2;
});

const stepCurrentQrPh = computed(() => (form.service_tier_id ? 1 : 0));

watch(
    () => page.props.flash?.success,
    (msg) => {
        if (msg) {
            message.success(msg);
        }
    },
    { immediate: true },
);

function selectTier(id) {
    form.service_tier_id = id;
}

function tierCardClasses(tier) {
    const active = form.service_tier_id === tier.id;
    return [
        "rounded-xl border-2 p-4 cursor-pointer transition-all text-left outline-none",
        active
            ? "border-green-600 ring-2 ring-green-600/25 bg-green-50/60 shadow-sm"
            : "border-gray-200 hover:border-gray-300 hover:bg-gray-50/80",
    ];
}

function tierLimitsLabel(tier) {
    const parts = [];
    if (tier.max_products != null) {
        parts.push(`Max ${tier.max_products} products`);
    } else {
        parts.push("Unlimited products");
    }
    if (tier.max_users != null) {
        parts.push(`Max ${tier.max_users} domain users`);
    } else {
        parts.push("Unlimited users");
    }
    return parts.join(" · ");
}

function startPaymongoQrph() {
    if (!form.service_tier_id || !hasTiers.value) {
        message.warning("Select a plan first.");
        return;
    }
    if (selectedTierUsesBundleQrPh.value) {
        return;
    }
    qrphForm.service_tier_id = form.service_tier_id;
    qrphForm.post(getRoute("billing.paymongo.qrph.store"), {
        preserveScroll: true,
        preserveState: "errors",
        onSuccess: () => {
            qrphForm.clearErrors();
        },
    });
}

let paymongoPollTimer = null;

function clearPaymongoPoll() {
    if (paymongoPollTimer !== null) {
        clearInterval(paymongoPollTimer);
        paymongoPollTimer = null;
    }
}

watch(
    () => ({
        pid: props.paymongoQr?.payment_intent_id,
        bundle: selectedTierUsesBundleQrPh.value,
    }),
    ({ pid, bundle }) => {
        clearPaymongoPoll();
        if (!pid || bundle) {
            return;
        }
        paymongoPollTimer = setInterval(async () => {
            try {
                const { data } = await axios.get(
                    getRoute("billing.paymongo.status"),
                    {
                        params: { payment_intent_id: pid },
                        headers: { Accept: "application/json" },
                        withCredentials: true,
                    },
                );
                if (data.paid) {
                    clearPaymongoPoll();
                    message.success("Payment confirmed. Your plan is active.");
                    router.reload({
                        only: ["subscription", "paymongoQr", "flash"],
                    });
                }
            } catch {
                /* ignore transient errors */
            }
        }, 4000);
    },
    { immediate: true },
);

onBeforeUnmount(() => {
    clearPaymongoPoll();
});

function submit() {
    if (!hasTiers.value) {
        return;
    }
    form.post(getRoute("billing.gcash.store"), {
        preserveScroll: true,
        onSuccess: () => {
            form.reset("gcash_reference");
            form.clearErrors();
        },
    });
}

const referenceHelp = computed(() => {
    if (form.errors.gcash_reference) {
        return form.errors.gcash_reference;
    }
    return "Usually letters and digits—paste the value shown on your GCash receipt.";
});
</script>

<template>
    <AuthenticatedLayout>
        <Head title="Servicing payment" />
        <ContentHeader class="mb-8" title="Servicing payment" />
        <ContentLayout
            :title="currentDomain?.name || 'Organization'"
        >
            <template #table>
                <div class="max-w-5xl mx-auto px-2 sm:px-4 py-2 pb-8 space-y-6">
                    <p class="text-sm text-gray-500 -mt-1">
                        <template v-if="showManualGcash">
                            Automated QR Ph (PayMongo) or GCash manual payment · amounts
                            must match your selected plan
                        </template>
                        <template v-else>
                            PayMongo QR Ph servicing · select a plan, then generate
                            a QR Ph code to complete payment securely.
                        </template>
                    </p>

                    <a-steps
                        v-if="showManualGcash"
                        size="small"
                        :current="stepCurrentGcash"
                        class="[&_.ant-steps-item-title]:text-sm"
                    >
                        <a-step title="Choose plan" />
                        <a-step title="Pay with GCash" />
                        <a-step title="Submit reference" />
                    </a-steps>
                    <a-steps
                        v-else-if="paymongoConfigured || hasBundledQrPhOffered"
                        size="small"
                        :current="stepCurrentQrPh"
                        class="[&_.ant-steps-item-title]:text-sm"
                    >
                        <a-step title="Choose plan" />
                        <a-step title="Pay with QR Ph" />
                    </a-steps>

                    <a-alert
                        v-if="!hasTiers"
                        type="warning"
                        message="No servicing plans available"
                        description="Ask your administrator to configure service tiers."
                        show-icon
                        class="mb-2"
                    />

                    <a-alert
                        v-if="subscription"
                        type="info"
                        show-icon
                        class="mb-2"
                    >
                        <template #message>Your organization</template>
                        <template #description>
                            <span v-if="subscription.is_paid">
                                Active plan: <strong>{{ subscription.tier_name }}</strong>.
                            </span>
                            <span v-else>
                                Free tier: up to
                                <strong>{{ subscription.free_product_limit }}</strong>
                                products until you subscribe.
                            </span>
                            <span class="block mt-1 text-gray-600">
                                Products: {{ subscription.product_count }}
                                <template v-if="subscription.max_products != null">
                                    / {{ subscription.max_products }}
                                </template>
                                · Users:
                                {{ subscription.user_count }}
                                <template v-if="subscription.max_users != null">
                                    / {{ subscription.max_users }}
                                </template>
                            </span>
                        </template>
                    </a-alert>

                    <div
                        class="grid grid-cols-1 items-start gap-10"
                        :class="{ 'lg:grid-cols-2 lg:gap-10': showManualGcash }"
                    >
                        <div class="space-y-6 min-w-0">
                            <div>
                                <h2
                                    class="text-base font-semibold text-gray-800 mb-3"
                                >
                                    Select a plan
                                </h2>
                                <div
                                    class="grid grid-cols-1 sm:grid-cols-2 gap-3"
                                >
                                    <div
                                        v-for="tier in tiers"
                                        :key="tier.id"
                                        role="button"
                                        tabindex="0"
                                        :class="tierCardClasses(tier)"
                                        @click="selectTier(tier.id)"
                                        @keydown.enter.prevent="selectTier(tier.id)"
                                    >
                                        <div
                                            class="flex items-start justify-between gap-2"
                                        >
                                            <span
                                                class="font-medium text-gray-900 leading-snug"
                                                >{{ tier.name }}</span
                                            >
                                            <span
                                                v-if="form.service_tier_id === tier.id"
                                                class="text-green-600 text-lg leading-none"
                                                aria-hidden="true"
                                                >✓</span
                                            >
                                        </div>
                                        <p
                                            class="mt-2 text-2xl font-semibold tabular-nums text-green-700"
                                        >
                                            {{ currencySymbol
                                            }}{{
                                                Number(tier.amount).toFixed(2)
                                            }}
                                        </p>
                                        <p
                                            class="mt-2 text-xs text-gray-600 leading-snug"
                                        >
                                            {{ tierLimitsLabel(tier) }}
                                        </p>
                                    </div>
                                </div>
                                <p
                                    v-if="form.errors.service_tier_id"
                                    class="text-red-500 text-sm mt-2"
                                >
                                    {{ form.errors.service_tier_id }}
                                </p>
                                <p
                                    v-if="qrphForm.errors.service_tier_id"
                                    class="text-red-500 text-sm mt-2"
                                >
                                    {{ qrphForm.errors.service_tier_id }}
                                </p>
                            </div>

                            <div
                                v-if="
                                    hasTiers &&
                                    form.service_tier_id &&
                                    selectedTierUsesBundleQrPh
                                "
                                class="rounded-xl border border-emerald-200 bg-emerald-50/50 p-4 space-y-3"
                            >
                                <h2
                                    class="text-base font-semibold text-gray-800"
                                >
                                    Pay with QR Ph
                                </h2>
                                <p class="text-sm text-gray-600">
                                    Scan the code below with your bank or e-wallet
                                    app. Send exactly
                                    {{
                                        currencySymbol
                                    }}{{ Number(selectedTier.amount).toFixed(2) }} for
                                    <strong>{{ selectedTier.name }}</strong>.
                                </p>
                                <div
                                    class="rounded-lg border border-emerald-100 bg-white p-4 flex flex-col items-center"
                                >
                                    <img
                                        src="@assets/qrph/qrph_basic.jpg"
                                        alt="QR Ph Basic plan"
                                        class="max-w-[280px] w-full h-auto rounded-md"
                                    />
                                </div>
                            </div>
                            <div
                                v-else-if="paymongoConfigured"
                                class="rounded-xl border border-emerald-200 bg-emerald-50/50 p-4 space-y-3"
                            >
                                <h2
                                    class="text-base font-semibold text-gray-800"
                                >
                                    Pay instantly with QR Ph
                                </h2>
                                <p class="text-sm text-gray-600">
                                    PayMongo generates a one-time QR Ph code for
                                    your selected plan. Your plan activates
                                    automatically after payment—no waiting for
                                    manual approval.
                                </p>
                                <div class="flex flex-wrap gap-2">
                                    <a-button
                                        type="primary"
                                        class="!bg-emerald-600 !border-emerald-600"
                                        :loading="qrphForm.processing"
                                        :disabled="
                                            !form.service_tier_id ||
                                            !hasTiers ||
                                            selectedTierUsesBundleQrPh
                                        "
                                        @click="startPaymongoQrph"
                                    >
                                        {{
                                            paymongoQr
                                                ? "Refresh QR Ph code"
                                                : "Generate QR Ph code"
                                        }}
                                    </a-button>
                                </div>
                                <div
                                    v-if="paymongoQr?.qr_image_data_url"
                                    class="rounded-lg border border-emerald-100 bg-white p-4 flex flex-col items-center"
                                >
                                    <img
                                        :src="paymongoQr.qr_image_data_url"
                                        alt="QR Ph payment code"
                                        class="max-w-[280px] w-full h-auto rounded-md"
                                    />
                                    <p
                                        class="mt-3 text-xs text-gray-600 text-center max-w-sm"
                                    >
                                        {{ paymongoExpiresLabel }}
                                    </p>
                                    <p
                                        v-if="paymongoQr.tier_name"
                                        class="mt-2 text-xs text-gray-500"
                                    >
                                        Plan:
                                        <strong>{{ paymongoQr.tier_name }}</strong>
                                        · Status:
                                        <span class="font-mono">{{
                                            paymongoQr.payment_intent_status
                                        }}</span>
                                    </p>
                                </div>
                            </div>
                            <a-alert
                                v-else-if="hasTiers"
                                type="warning"
                                show-icon
                                class="text-sm"
                                message="QR Ph checkout unavailable"
                                :description="
                                    showManualGcash
                                        ? 'PayMongo is not configured on this server. Use manual GCash below or contact support.'
                                        : 'PayMongo is not configured on this server. Contact your administrator.'
                                "
                            />

                            <ManualGcashPayment
                                v-if="showManualGcash"
                                :form="form"
                                :currency-symbol="currencySymbol"
                                :qr-src="qrSrc"
                                :show-placeholder-qr-notice="showPlaceholderQrNotice"
                                :reference-help="referenceHelp"
                                :selected-tier="selectedTier"
                                :has-tiers="hasTiers"
                                :submit="submit"
                            />
                        </div>

                        <ManualGcashDesktopQrAside
                            v-if="showManualGcash"
                            :qr-src="qrSrc"
                            :show-placeholder-qr-notice="showPlaceholderQrNotice"
                        />
                    </div>
                </div>
            </template>
        </ContentLayout>
    </AuthenticatedLayout>
</template>
