<script setup>
import { computed, watch } from "vue";
import { Head, useForm, usePage } from "@inertiajs/vue3";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import ContentHeader from "@/Components/ContentHeader.vue";
import ContentLayout from "@/Components/ContentLayout.vue";
import { useDomainRoutes } from "@/Composables/useDomainRoutes";
import { message } from "ant-design-vue";

const props = defineProps({
    tiers: { type: Array, default: () => [] },
    gcashQrUrl: { type: String, default: "/images/gcash-qr.svg" },
    currencySymbol: { type: String, default: "₱" },
    currentDomain: { type: Object, default: () => ({}) },
});

const page = usePage();
const { getRoute } = useDomainRoutes();

const form = useForm({
    service_tier_id: null,
    gcash_reference: "",
});

const hasTiers = computed(() => Array.isArray(props.tiers) && props.tiers.length > 0);

const selectedTier = computed(() =>
    props.tiers.find((t) => t.id === form.service_tier_id),
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

const stepCurrent = computed(() => {
    if (!form.service_tier_id) {
        return 0;
    }
    if (!String(form.gcash_reference || "").trim()) {
        return 1;
    }
    return 2;
});

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

function submit() {
    if (!hasTiers.value) {
        return;
    }
    form.post(getRoute("billing.gcash.store"), {
        preserveScroll: true,
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
                        GCash manual payment · amount must match your selected plan
                    </p>

                    <a-steps
                        size="small"
                        :current="stepCurrent"
                        class="[&_.ant-steps-item-title]:text-sm"
                    >
                        <a-step title="Choose plan" />
                        <a-step title="Pay with GCash" />
                        <a-step title="Submit reference" />
                    </a-steps>

                    <a-alert
                        v-if="!hasTiers"
                        type="warning"
                        message="No servicing plans available"
                        description="Ask your administrator to configure service tiers."
                        show-icon
                        class="mb-2"
                    />

                    <div
                        class="grid grid-cols-1 lg:grid-cols-2 lg:gap-10 items-start"
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
                                    </div>
                                </div>
                                <p
                                    v-if="form.errors.service_tier_id"
                                    class="text-red-500 text-sm mt-2"
                                >
                                    {{ form.errors.service_tier_id }}
                                </p>
                            </div>

                            <a-alert
                                v-if="selectedTier"
                                type="info"
                                show-icon
                                :message="`Send exactly ${currencySymbol}${Number(selectedTier.amount).toFixed(2)}`"
                                description="Use the GCash app to send this amount to the merchant QR before submitting your reference."
                            />

                            <!-- Mobile / tablet: pay step before reference -->
                            <div class="lg:hidden space-y-3">
                                <h2
                                    class="text-base font-semibold text-gray-800 text-center sm:text-left"
                                >
                                    Pay here
                                </h2>
                                <div
                                    class="rounded-xl border border-gray-200 bg-gray-50/80 shadow-sm p-6 flex flex-col items-center"
                                >
                                    <img
                                        :src="qrSrc"
                                        alt="GCash QR code"
                                        class="max-w-[260px] w-full h-auto rounded-lg bg-white p-3 border border-gray-100 shadow-inner"
                                    />
                                    <p
                                        class="mt-4 text-sm text-gray-600 text-center"
                                    >
                                        Scan with the GCash app
                                    </p>
                                    <p
                                        v-if="showPlaceholderQrNotice"
                                        class="mt-2 text-xs text-amber-800/90 text-center max-w-[280px]"
                                    >
                                        Dev: replace the placeholder QR with your
                                        real code under
                                        <code
                                            class="text-xs bg-amber-100/80 px-1 rounded"
                                            >public/images/</code
                                        >
                                    </p>
                                </div>
                            </div>

                            <a-form layout="vertical" class="mb-0">
                                <a-form-item
                                    label="GCash reference number"
                                    :validate-status="
                                        form.errors.gcash_reference
                                            ? 'error'
                                            : ''
                                    "
                                    :help="referenceHelp"
                                    required
                                >
                                    <a-input
                                        v-model:value="form.gcash_reference"
                                        placeholder="Paste from your GCash receipt"
                                        size="large"
                                        autocomplete="off"
                                        class="font-mono"
                                    />
                                </a-form-item>

                                <div
                                    class="flex flex-col sm:flex-row sm:justify-end gap-3 pt-1"
                                >
                                    <a-button
                                        type="primary"
                                        :loading="form.processing"
                                        :disabled="
                                            !form.service_tier_id || !hasTiers
                                        "
                                        class="w-full sm:w-auto bg-white border flex items-center justify-center border-green-500 text-green-500 !h-10"
                                        @click="submit"
                                    >
                                        Submit reference
                                    </a-button>
                                </div>
                            </a-form>

                            <a-alert
                                type="info"
                                show-icon
                                class="text-sm"
                                message="Verification"
                                description="Our team confirms each payment against the GCash ledger. You will be notified once the payment is approved."
                            />
                        </div>

                        <div
                            class="hidden lg:block lg:sticky lg:top-24 space-y-3"
                        >
                            <h2
                                class="text-base font-semibold text-gray-800 text-center"
                            >
                                Pay here
                            </h2>
                            <div
                                class="rounded-xl border border-gray-200 bg-gray-50/80 shadow-sm p-6 flex flex-col items-center"
                            >
                                <img
                                    :src="qrSrc"
                                    alt="GCash QR code"
                                    class="max-w-[260px] w-full h-auto rounded-lg bg-white p-3 border border-gray-100 shadow-inner"
                                />
                                <p
                                    class="mt-4 text-sm text-gray-600 text-center"
                                >
                                    Scan with the GCash app
                                </p>
                                <p
                                    v-if="showPlaceholderQrNotice"
                                    class="mt-2 text-xs text-amber-800/90 text-center max-w-[280px]"
                                >
                                    Dev: replace the placeholder QR with your
                                    real code under
                                    <code
                                        class="text-xs bg-amber-100/80 px-1 rounded"
                                        >public/images/</code
                                    >
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            </template>
        </ContentLayout>
    </AuthenticatedLayout>
</template>
