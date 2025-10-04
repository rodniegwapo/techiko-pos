<template>
    <div
        v-if="orderItems.length > 0"
        class="bg-white rounded-lg shadow-sm border p-6 sticky top-6"
    >
        <div class="flex items-center space-x-2 mb-6">
            <div
                class="w-8 h-8 bg-blue-100 rounded-lg flex items-center justify-center"
            >
                <svg
                    class="w-5 h-5 text-blue-600"
                    fill="none"
                    stroke="currentColor"
                    viewBox="0 0 24 24"
                >
                    <path
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        stroke-width="2"
                        d="M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z"
                    />
                </svg>
            </div>
            <h3 class="text-xl font-bold text-gray-900">Order Summary</h3>
        </div>

        <div class="space-y-4">
            <div class="flex justify-between items-center py-2">
                <div class="flex items-center space-x-2">
                    <span class="text-gray-600">Items Subtotal</span>
                    <span class="text-xs text-gray-400">
                        ({{ orderItems.length }} items)
                    </span>
                </div>
                <span class="font-semibold text-gray-900 text-lg">
                    ₱{{ formatCurrency(subtotal) }}
                </span>
            </div>

            <div
                v-if="discountAmount > 0"
                class="bg-green-50 border border-green-200 rounded-lg p-4"
            >
                <div class="flex items-center space-x-2 mb-3">
                    <div
                        class="w-5 h-5 bg-green-500 rounded-full flex items-center justify-center"
                    >
                        <svg
                            class="w-3 h-3 text-white"
                            fill="currentColor"
                            viewBox="0 0 20 20"
                        >
                            <path
                                fill-rule="evenodd"
                                d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z"
                                clip-rule="evenodd"
                            />
                        </svg>
                    </div>
                    <span class="font-medium text-green-800">
                        Discounts Applied
                    </span>
                </div>

                <div v-if="itemDiscountAmount > 0" class="mb-2">
                    <div class="flex justify-between items-center">
                        <span class="text-sm text-green-700">
                            Item Discounts
                        </span>
                        <span class="font-semibold text-green-800">
                            -₱{{ formatCurrency(itemDiscountAmount) }}
                        </span>
                    </div>
                </div>

                <div v-if="orderDiscountAmount > 0" class="mb-2">
                    <div class="flex justify-between items-center">
                        <span class="text-sm text-green-700">
                            Order Discounts
                        </span>
                        <span class="font-semibold text-green-800">
                            -₱{{ formatCurrency(orderDiscountAmount) }}
                        </span>
                    </div>
                </div>

                <div class="border-t border-green-300 pt-2">
                    <div class="flex justify-between items-center">
                        <span class="font-medium text-green-800">
                            Total Savings
                        </span>
                        <span class="font-bold text-green-800 text-lg">
                            -₱{{ formatCurrency(discountAmount) }}
                        </span>
                    </div>
                </div>
            </div>

            <div class="flex justify-between items-center py-2">
                <span class="text-gray-600">Tax (VAT)</span>
                <span class="font-semibold text-gray-900 text-lg">
                    ₱{{ formatCurrency(taxAmount) }}
                </span>
            </div>

            <div class="border-t pt-4 mt-4">
                <div class="flex justify-between items-center">
                    <span class="font-bold text-gray-900 text-xl">
                        Total Amount
                    </span>
                    <span class="font-bold text-green-700 text-2xl">
                        ₱{{ formatCurrency(totalAmount) }}
                    </span>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
defineProps({
    orderItems: {
        type: Array,
        default: () => [],
    },
    subtotal: {
        type: Number,
        default: 0,
    },
    itemDiscountAmount: {
        type: Number,
        default: 0,
    },
    orderDiscountAmount: {
        type: Number,
        default: 0,
    },
    discountAmount: {
        type: Number,
        default: 0,
    },
    taxAmount: {
        type: Number,
        default: 0,
    },
    totalAmount: {
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
