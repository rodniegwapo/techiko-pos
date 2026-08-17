<script setup>
defineProps({
    form: { type: Object, required: true },
    currencySymbol: { type: String, default: "₱" },
    qrSrc: { type: String, required: true },
    showPlaceholderQrNotice: { type: Boolean, default: false },
    referenceHelp: { type: String, default: "" },
    selectedTier: { type: Object, default: null },
    hasTiers: { type: Boolean, default: false },
    submit: { type: Function, required: true },
});
</script>

<template>
    <div class="space-y-6">
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
                <p class="mt-4 text-sm text-gray-600 text-center">
                    Scan with the GCash app
                </p>
                <p
                    v-if="showPlaceholderQrNotice"
                    class="mt-2 text-xs text-amber-800/90 text-center max-w-[280px]"
                >
                    Dev: replace the placeholder QR with your real code under
                    <code class="text-xs bg-amber-100/80 px-1 rounded"
                        >public/images/</code
                    >
                </p>
            </div>
        </div>

        <a-form layout="vertical" class="mb-0">
            <a-form-item
                label="GCash reference number"
                :validate-status="form.errors.gcash_reference ? 'error' : ''"
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

            <div class="flex flex-col sm:flex-row sm:justify-end gap-3 pt-1">
                <a-button
                    type="primary"
                    :loading="form.processing"
                    :disabled="!form.service_tier_id || !hasTiers"
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
</template>
