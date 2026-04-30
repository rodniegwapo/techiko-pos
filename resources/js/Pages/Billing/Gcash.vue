<script setup>
import { computed, ref } from "vue";
import { Head, useForm } from "@inertiajs/vue3";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import ContentHeader from "@/Components/ContentHeader.vue";
import ContentLayout from "@/Components/ContentLayout.vue";
import { useDomainRoutes } from "@/Composables/useDomainRoutes";

const props = defineProps({
    tiers: { type: Array, default: () => [] },
    gcashQrUrl: { type: String, default: "/images/gcash-qr.svg" },
    currencySymbol: { type: String, default: "₱" },
    currentDomain: { type: Object, default: () => ({}) },
});

const { getRoute } = useDomainRoutes();

const form = useForm({
    service_tier_id: null,
    gcash_reference: "",
});

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

function submit() {
    form.post(getRoute("billing.gcash.store"), {
        preserveScroll: true,
    });
}
</script>

<template>
    <AuthenticatedLayout>
        <Head title="GCash servicing payment" />
        <ContentHeader class="mb-8" title="Servicing payment (GCash)" />
        <ContentLayout :title="`${currentDomain?.name || 'Organization'} — Manual GCash`">
            <template #table>
                <div class="max-w-xl mx-auto p-6 bg-white rounded-lg shadow space-y-6">
                    <p class="text-gray-600 text-sm">
                        Choose a plan, pay the exact amount via GCash using the QR below, then
                        enter the reference number from your receipt. A super admin will confirm
                        payment against the GCash ledger.
                    </p>

                    <a-form layout="vertical">
                        <a-form-item
                            label="Servicing plan"
                            :validate-status="form.errors.service_tier_id ? 'error' : ''"
                            :help="form.errors.service_tier_id"
                            required
                        >
                            <a-radio-group
                                v-model:value="form.service_tier_id"
                                class="flex flex-col gap-2"
                            >
                                <a-radio
                                    v-for="tier in tiers"
                                    :key="tier.id"
                                    :value="tier.id"
                                >
                                    <span class="font-medium">{{ tier.name }}</span>
                                    <span class="text-green-600 ml-2">
                                        {{ currencySymbol }}{{ Number(tier.amount).toFixed(2) }}
                                    </span>
                                </a-radio>
                            </a-radio-group>
                        </a-form-item>

                        <a-alert
                            v-if="selectedTier"
                            type="info"
                            class="mb-4"
                            :message="`Send exactly ${currencySymbol}${Number(selectedTier.amount).toFixed(2)} via GCash.`"
                        />

                        <a-form-item label="GCash QR">
                            <div class="flex justify-center p-4 bg-gray-50 rounded-lg">
                                <img
                                    :src="qrSrc"
                                    alt="GCash QR"
                                    class="max-w-[240px] h-auto"
                                />
                            </div>
                        </a-form-item>

                        <a-form-item
                            label="GCash reference number"
                            :validate-status="form.errors.gcash_reference ? 'error' : ''"
                            :help="form.errors.gcash_reference"
                            required
                        >
                            <a-input
                                v-model:value="form.gcash_reference"
                                placeholder="From your GCash receipt"
                                size="large"
                                autocomplete="off"
                            />
                        </a-form-item>

                        <div class="flex justify-end">
                            <a-button
                                type="primary"
                                :loading="form.processing"
                                :disabled="!form.service_tier_id"
                                class="bg-green-600"
                                @click="submit"
                            >
                                Submit reference
                            </a-button>
                        </div>
                    </a-form>
                </div>
            </template>
        </ContentLayout>
    </AuthenticatedLayout>
</template>
