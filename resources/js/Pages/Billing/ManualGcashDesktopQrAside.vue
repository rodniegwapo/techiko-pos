<script setup>
defineProps({
    qrSrc: { type: String, required: true },
    showPlaceholderQrNotice: { type: Boolean, default: false },
});
</script>

<template>
    <div class="hidden lg:block lg:sticky lg:top-24 space-y-3">
        <h2 class="text-base font-semibold text-gray-800 text-center">
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
</template>
