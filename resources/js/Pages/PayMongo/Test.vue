<script setup>
import { ref, computed } from "vue";
import { Head } from "@inertiajs/vue3";
import axios from "axios";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import ContentHeader from "@/Components/ContentHeader.vue";
import ContentLayout from "@/Components/ContentLayout.vue";
import { notification } from "ant-design-vue";

const props = defineProps({
    publishableKey: { type: String, default: "" },
    publishableKeyMasked: { type: String, default: null },
});

const amountCentavos = ref(10000);
const loading = ref(false);
const lastResponse = ref(null);

const amountHelp = computed(
    () =>
        `Minimum 2000 centavos (₱20). Current: ₱${(
            (Number(amountCentavos.value) || 0) / 100
        ).toFixed(2)}`,
);

async function createPaymentIntent() {
    loading.value = true;
    lastResponse.value = null;
    try {
        const { data } = await axios.post(
            route("paymongo.payment-intents.store"),
            { amount: Number(amountCentavos.value) },
        );
        lastResponse.value = data;
        if (data.success) {
            notification.success({
                message: "Payment Intent created",
                description: data.payment_intent_id || "OK",
            });
        } else {
            notification.warning({
                message: "Unexpected response",
            });
        }
    } catch (err) {
        const body = err.response?.data;
        lastResponse.value = body ?? { message: err.message };
        notification.error({
            message: "Request failed",
            description:
                body?.message ||
                err.response?.statusText ||
                err.message ||
                "Error",
        });
    } finally {
        loading.value = false;
    }
}

const formattedJson = computed(() =>
    lastResponse.value
        ? JSON.stringify(lastResponse.value, null, 2)
        : "",
);
</script>

<template>
    <AuthenticatedLayout>
        <Head title="PayMongo test" />
        <ContentHeader class="mb-6" title="PayMongo test (super user)" />
        <ContentLayout title="Create Payment Intent">
            <template #table>
                <div class="px-6 py-4 max-w-3xl space-y-6">
                    <p class="text-sm text-gray-600">
                        Uses your
                        <strong>test</strong> keys from
                        <code class="text-xs">.env</code>. This only creates a
                        Payment Intent; completing a card payment requires
                        PayMongo.js or the attach flow — see
                        <a
                            class="text-blue-600 hover:underline"
                            href="https://developers.paymongo.com/docs/payment-intent-api"
                            target="_blank"
                            rel="noopener noreferrer"
                            >Payment Intent API</a
                        >.
                    </p>

                    <div class="space-y-1">
                        <div class="text-sm text-gray-700">
                            Publishable key (masked):
                            <code class="text-xs ml-1">{{
                                publishableKeyMasked || "—"
                            }}</code>
                        </div>
                    </div>

                    <div class="flex flex-wrap items-end gap-4">
                        <div class="flex flex-col gap-1">
                            <label class="text-xs text-gray-600"
                                >Amount (centavos)</label
                            >
                            <a-input-number
                                v-model:value="amountCentavos"
                                :min="2000"
                                :max="999999999"
                                class="w-44"
                            />
                            <span class="text-xs text-gray-500">{{
                                amountHelp
                            }}</span>
                        </div>
                        <a-button
                            type="primary"
                            :loading="loading"
                            @click="createPaymentIntent"
                        >
                            Create test Payment Intent
                        </a-button>
                    </div>

                    <div v-if="lastResponse">
                        <div class="text-sm font-medium text-gray-700 mb-2">
                            Response
                        </div>
                        <pre
                            class="text-xs bg-gray-900 text-gray-100 p-4 rounded-lg overflow-auto max-h-[480px]"
                            >{{ formattedJson }}</pre
                        >
                    </div>
                </div>
            </template>
        </ContentLayout>
    </AuthenticatedLayout>
</template>
