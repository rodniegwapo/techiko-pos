<template>
    <div class="bg-white rounded-lg shadow-sm border">
        <div class="p-6 border-b bg-gray-50">
            <div class="flex items-center justify-between">
                <div class="flex items-center space-x-3">
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
                                d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"
                            />
                        </svg>
                    </div>
                    <div>
                        <h3 class="text-xl font-bold text-gray-900">
                            Order Items
                        </h3>
                        <p class="text-sm text-gray-600">
                            {{ orderItems.length }} item{{
                                orderItems.length !== 1 ? "s" : ""
                            }}
                            in your order
                        </p>
                    </div>
                </div>
                <div class="text-right">
                    <div class="text-sm text-gray-600">Items Total</div>
                    <div class="text-lg font-bold text-gray-900">
                        ₱{{ formatCurrency(subtotal) }}
                    </div>
                    <div
                        v-if="itemDiscountAmount > 0"
                        class="text-xs text-green-600"
                    >
                        -₱{{ formatCurrency(itemDiscountAmount) }} in item
                        discounts
                    </div>
                </div>
            </div>
        </div>

        <div
            v-if="orderItems.length === 0"
            class="p-8 text-center text-gray-500"
        >
            <ShoppingCartOutlined style="font-size: 48px" class="mb-4" />
            <p>No items added yet</p>
            <p class="text-sm">Items will appear here as they're scanned</p>
        </div>

        <div v-else class="divide-y">
            <div
                v-for="(item, index) in orderItems"
                :key="`${item.product_id}-${index}`"
                class="p-6 hover:bg-gray-50 transition-colors"
                :class="{
                    'border-l-4 border-green-400 bg-green-50/30':
                        item.discount && item.discount > 0,
                }"
            >
                <div class="flex items-start justify-between">
                    <div class="flex-1">
                        <div class="flex items-start space-x-4">
                            <div
                                class="w-14 h-14 bg-gradient-to-br from-blue-100 to-blue-200 rounded-lg flex items-center justify-center shadow-sm"
                            >
                                <BoxPlotOutlined class="text-blue-600 text-lg" />
                            </div>
                            <div class="flex-1">
                                <div class="flex items-center space-x-2 mb-1">
                                    <h4
                                        class="font-semibold text-gray-900 text-lg"
                                    >
                                        {{
                                            item.product_name ||
                                            "Unknown Product"
                                        }}
                                    </h4>
                                    <div
                                        v-if="
                                            item.discount && item.discount > 0
                                        "
                                        class="bg-green-100 text-green-800 text-xs font-medium px-2 py-1 rounded-full"
                                    >
                                        DISCOUNTED
                                    </div>
                                </div>
                                <p class="text-sm text-gray-500 mb-2">
                                    SKU: {{ item.product_sku || "N/A" }}
                                </p>

                                <div
                                    class="flex items-center space-x-6 mb-2"
                                >
                                    <div class="flex items-center space-x-2">
                                        <span class="text-sm text-gray-600"
                                            >Price:</span
                                        >
                                        <span class="font-medium text-gray-900">
                                            ₱{{
                                                formatCurrency(item.unit_price)
                                            }}
                                        </span>
                                    </div>
                                    <div class="flex items-center space-x-2">
                                        <span class="text-sm text-gray-600"
                                            >Qty:</span
                                        >
                                        <span class="font-medium text-gray-900">
                                            {{ item.quantity }}
                                        </span>
                                    </div>
                                </div>

                                <div
                                    v-if="
                                        item.discount && item.discount > 0
                                    "
                                    class="bg-green-50 border border-green-200 rounded-lg p-3 mt-2"
                                >
                                    <div class="flex items-center space-x-2 mb-1">
                                        <div
                                            class="w-2 h-2 bg-green-500 rounded-full"
                                        ></div>
                                        <span
                                            class="text-sm font-medium text-green-800"
                                            >Item Discount Applied</span
                                        >
                                    </div>
                                    <div class="text-sm text-green-700">
                                        Discount: -₱{{
                                            formatCurrency(item.discount)
                                        }}
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="text-right ml-4">
                        <div
                            v-if="
                                item.discount && item.discount > 0
                            "
                            class="text-sm text-gray-500 line-through mb-1"
                        >
                            ₱{{
                                formatCurrency(
                                    item.unit_price * item.quantity
                                )
                            }}
                        </div>

                        <div class="text-xl font-bold text-gray-900 mb-1">
                            ₱{{
                                formatCurrency(
                                    item.subtotal ||
                                        item.unit_price * item.quantity -
                                            (item.discount || 0)
                                )
                            }}
                        </div>

                        <div
                            v-if="
                                item.discount && item.discount > 0
                            "
                            class="text-sm font-medium text-green-600"
                        >
                            You save ₱{{ formatCurrency(item.discount) }}
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { ShoppingCartOutlined, BoxPlotOutlined } from "@ant-design/icons-vue";

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
});

const formatCurrency = (amount) => {
    return new Intl.NumberFormat("en-PH", {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    }).format(amount);
};
</script>
