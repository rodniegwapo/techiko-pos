<template>
    <div class="bg-white rounded-lg shadow-sm border p-6">
        <div class="flex items-center justify-between">
            <div class="flex items-center space-x-4">
                <div
                    class="w-12 h-12 bg-green-100 rounded-full flex items-center justify-center"
                >
                    <svg
                        class="w-6 h-6 text-green-600"
                        fill="none"
                        stroke="currentColor"
                        viewBox="0 0 24 24"
                    >
                        <path
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            stroke-width="2"
                            d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"
                        />
                    </svg>
                </div>
                <div>
                    <h2 class="text-xl font-bold text-gray-900">
                        Order In Progress
                    </h2>
                    <p class="text-sm text-gray-600">
                        Last updated: {{ lastUpdated }}
                    </p>
                </div>
            </div>
            <div class="text-right">
                <div class="text-3xl font-bold text-green-600 mb-1">
                    ₱{{ formatCurrency(totalAmount) }}
                </div>
                <div class="text-sm text-gray-600">
                    {{ itemCount }} item{{
                        itemCount !== 1 ? "s" : ""
                    }}
                    • Ready for payment
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
defineProps({
    lastUpdated: {
        type: String,
        required: true,
    },
    totalAmount: {
        type: Number,
        default: 0,
    },
    itemCount: {
        type: Number,
        default: 0,
    },
});

const formatCurrency = (amount) => {
    return new Intl.NumberFormat("en-PH", {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    }).format(amount);
};
</script>
