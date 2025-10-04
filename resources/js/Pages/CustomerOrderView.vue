<script setup>
import { watch } from "vue";
import { Head } from "@inertiajs/vue3";
import { Spin } from "ant-design-vue";
import axios from "axios";
import { ShoppingCartOutlined } from "@ant-design/icons-vue";

// Components
import OrderHeader from "./CustomerOrder/components/OrderHeader.vue";
import OrderStatus from "./CustomerOrder/components/OrderStatus.vue";
import OrderItems from "./CustomerOrder/components/OrderItems.vue";
import OrderSummary from "./CustomerOrder/components/OrderSummary.vue";
import OrderFooter from "./CustomerOrder/components/OrderFooter.vue";

// Composables
import { useCustomerOrder } from "./CustomerOrder/composables/useCustomerOrder.js";

// Use the customer order composable
const {
    order,
    orderItems,
    customer,
    lastUpdated,
    orderId,
    loading,
    paymentSuccessMessage,
    subtotal,
    itemDiscountAmount,
    orderDiscountAmount,
    discountAmount,
    taxAmount,
    totalAmount,
    formatCurrency,
} = useCustomerOrder();

// Debug customer data
console.log("CustomerOrderView - Customer data:", customer.value);

// Watch for customer changes
watch(
    customer,
    (newCustomer, oldCustomer) => {
        console.log("CustomerOrderView - Customer changed:", {
            old: oldCustomer,
            new: newCustomer,
        });
    },
    { deep: true, immediate: true }
);

// Test function to manually trigger customer event
const testCustomerEvent = async () => {
    if (!orderId.value) {
        console.error("No order ID available for testing");
        return;
    }

    try {
        console.log("Testing CustomerUpdated event for order:", orderId.value);
        const response = await axios.post(
            `/api/sales/${orderId.value}/test-customer-event`
        );
        console.log("Test event response:", response.data);
    } catch (error) {
        console.error("Error testing customer event:", error);
    }
};

// Test function to manually trigger order event
const testOrderEvent = async () => {
    if (!orderId.value) {
        console.error("No order ID available for testing");
        return;
    }

    try {
        console.log("Testing OrderUpdated event for order:", orderId.value);
        const response = await axios.post(
            `/api/sales/${orderId.value}/test-order-event`
        );
        console.log("Test order event response:", response.data);
    } catch (error) {
        console.error("Error testing order event:", error);
    }
};
</script>

<template>
    <div class="min-h-screen bg-gray-50">
        <Head title="Customer Order View" />

        <!-- Header -->
        <OrderHeader
            :order-id="orderId"
            :item-count="orderItems.length"
            :total-amount="totalAmount"
            :customer="customer"
        />

        <!-- Payment Success Message -->
        <div v-if="paymentSuccessMessage" class="max-w-7xl mx-auto px-4 py-4">
            <div
                class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded-lg flex items-center"
            >
                <div class="flex-shrink-0">
                    <svg
                        class="h-5 w-5 text-green-400"
                        viewBox="0 0 20 20"
                        fill="currentColor"
                    >
                        <path
                            fill-rule="evenodd"
                            d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z"
                            clip-rule="evenodd"
                        />
                    </svg>
                </div>
                <div class="ml-3">
                    <p class="text-sm font-medium">
                        {{ paymentSuccessMessage }}
                    </p>
                </div>
            </div>
        </div>

        <!-- Order Content -->
        <div class="max-w-7xl mx-auto px-4 py-6">
            <!-- Loading State -->
            <div v-if="loading" class="text-center py-12">
                <a-spin size="large" />
                <p class="mt-4 text-gray-600">Loading order data...</p>
            </div>

            <!-- No Order Yet -->
            <div v-else-if="!order" class="text-center py-12">
                <div class="text-gray-400 mb-4">
                    <ShoppingCartOutlined style="font-size: 64px" />
                </div>
                <h3 class="text-xl font-semibold text-gray-900 mb-2">
                    No Active Order
                </h3>
                <p class="text-gray-600">
                    This page will automatically display your order when items
                    are added.
                </p>
            </div>

            <!-- Order Display -->
            <div v-else class="grid grid-cols-1 lg:grid-cols-3 gap-6 relative">
                <!-- Left Column -->
                <div class="lg:col-span-2 space-y-6">
                    <!-- Order Status -->
                    <!-- <OrderStatus
                        :last-updated="lastUpdated"
                        :total-amount="totalAmount"
                        :item-count="orderItems.length"
                    /> -->

                    <!-- Order Items -->
                    <OrderItems
                        :order-items="orderItems"
                        :subtotal="subtotal"
                        :item-discount-amount="itemDiscountAmount"
                    />
                </div>

                <!-- Right Column -->
                <div class="lg:col-span-1">
                    <OrderSummary
                        :order-items="orderItems"
                        :subtotal="subtotal"
                        :item-discount-amount="itemDiscountAmount"
                        :order-discount-amount="orderDiscountAmount"
                        :discount-amount="discountAmount"
                        :tax-amount="taxAmount"
                        :total-amount="totalAmount"
                    />
                </div>
            </div>
        </div>

        <!-- Footer -->
        <OrderFooter :order-id="orderId" :last-updated="lastUpdated" />
    </div>
</template>
