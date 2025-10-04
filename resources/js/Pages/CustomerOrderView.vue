<script setup>
import { ref, computed, onMounted, onBeforeUnmount } from "vue";
import { usePage } from "@inertiajs/vue3";
import { ShoppingCartOutlined, BoxPlotOutlined } from "@ant-design/icons-vue";

// Reactive data
const loading = ref(true);
const order = ref(null);
const orderItems = ref([]);
const customer = ref(null);
const lastUpdated = ref(new Date().toLocaleTimeString());
const orderId = ref(null);

// Computed properties
const subtotal = computed(() => {
    return orderItems.value.reduce((sum, item) => {
        return sum + item.unit_price * item.quantity;
    }, 0);
});

const discountAmount = computed(() => {
    return orderItems.value.reduce((sum, item) => {
        return sum + (item.discount_amount || 0);
    }, 0);
});

const taxAmount = computed(() => {
    return (subtotal.value - discountAmount.value) * 0.12; // 12% tax
});

const totalAmount = computed(() => {
    return subtotal.value - discountAmount.value + taxAmount.value;
});

// Methods
const formatCurrency = (amount) => {
    return new Intl.NumberFormat("en-PH", {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    }).format(amount || 0);
};

const handleOrderUpdate = (data) => {
    console.log("Order update received:", data);

    if (data.order) {
        order.value = data.order;
        orderId.value = data.order.id;
        orderItems.value = data.order.sale_items || [];
        customer.value = data.order.customer;
        lastUpdated.value = new Date().toLocaleTimeString();
        loading.value = false;
    }
};

const fetchInitialOrder = async () => {
    try {
        // Try to get the most recent pending order
        const response = await fetch('/api/orders/recent-pending');
        if (response.ok) {
            const data = await response.json();
            if (data.order) {
                order.value = data.order;
                orderId.value = data.order.id;
                orderItems.value = data.order.items || [];
                customer.value = data.order.customer;
                lastUpdated.value = new Date().toLocaleTimeString();
            }
        }
    } catch (error) {
        console.error("Error fetching initial order:", error);
    } finally {
        loading.value = false;
    }
};

// Lifecycle
onMounted(() => {
    // Fetch initial order data on page load/refresh
    fetchInitialOrder();

    // Listen to Pusher/Echo for order updates
    if (!window.Echo) {
        console.error("Echo is not initialized!");
        return;
    }

    window.Echo.channel("order").listen(".OrderUpdated", (e) => {
        console.log("Order updated:", e.order);
        handleOrderUpdate(e);
    });
});

onBeforeUnmount(() => {
    if (window.Echo) {
        window.Echo.leave("order");
    }
});
</script>

<template>
    <div class="min-h-screen bg-gray-50">

      {{order}}
        <!-- Header -->
        <div class="bg-white shadow-sm border-b">
            <div class="max-w-4xl mx-auto px-4 py-4">
                <div class="flex items-center justify-between">
                    <div>
                        <h1 class="text-2xl font-bold text-gray-900">
                            Your Order
                        </h1>
                        <p class="text-sm text-gray-600">
                            Order #{{ orderId || "Waiting for order..." }}
                        </p>
                    </div>
                    <div class="flex items-center space-x-2">
                        <div
                            class="w-3 h-3 bg-green-500 rounded-full animate-pulse"
                        ></div>
                        <span class="text-sm text-gray-600">Live Updates</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Order Content -->
        <div class="max-w-4xl mx-auto px-4 py-6">
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
            <div v-else class="space-y-6">
                <!-- Order Status -->
                <div class="bg-white rounded-lg shadow-sm border p-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <h2 class="text-lg font-semibold text-gray-900">
                                Order Status
                            </h2>
                            <p class="text-sm text-gray-600">
                                Last updated: {{ lastUpdated }}
                            </p>
                        </div>
                        <div class="text-right">
                            <div class="text-2xl font-bold text-green-600">
                                ₱{{ formatCurrency(totalAmount) }}
                            </div>
                            <div class="text-sm text-gray-600">
                                {{ orderItems.length }} items
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Order Items -->
                <div class="bg-white rounded-lg shadow-sm border">
                    <div class="p-6 border-b">
                        <h3 class="text-lg font-semibold text-gray-900">
                            Order Items
                        </h3>
                    </div>

                    <div
                        v-if="orderItems.length === 0"
                        class="p-8 text-center text-gray-500"
                    >
                        <ShoppingCartOutlined
                            style="font-size: 48px"
                            class="mb-4"
                        />
                        <p>No items added yet</p>
                        <p class="text-sm">
                            Items will appear here as they're scanned
                        </p>
                    </div>

                    <div v-else class="divide-y">
                        <div
                            v-for="(item, index) in orderItems"
                            :key="`${item.product_id}-${index}`"
                            class="p-6 hover:bg-gray-50 transition-colors"
                        >
                            <div class="flex items-center justify-between">
                                <div class="flex-1">
                                    <div class="flex items-center space-x-4">
                                        <div
                                            class="w-12 h-12 bg-gray-200 rounded-lg flex items-center justify-center"
                                        >
                                            <BoxPlotOutlined
                                                class="text-gray-500"
                                            />
                                        </div>
                                        <div class="flex-1">
                                            <h4
                                                class="font-medium text-gray-900"
                                            >
                                                {{
                                                    item.product?.name ||
                                                    "Unknown Product"
                                                }}
                                            </h4>
                                            <p class="text-sm text-gray-600">
                                                SKU:
                                                {{ item.product?.SKU || "N/A" }}
                                            </p>
                                            <div
                                                class="flex items-center space-x-4 mt-1"
                                            >
                                                <span
                                                    class="text-sm text-gray-600"
                                                    >₱{{
                                                        formatCurrency(
                                                            item.unit_price
                                                        )
                                                    }}
                                                    each</span
                                                >
                                                <span
                                                    class="text-sm text-gray-600"
                                                    >× {{ item.quantity }}</span
                                                >
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="text-right">
                                    <div
                                        class="text-lg font-semibold text-gray-900"
                                    >
                                        ₱{{
                                            formatCurrency(
                                                item.unit_price * item.quantity
                                            )
                                        }}
                                    </div>
                                    <div
                                        v-if="item.discount_amount > 0"
                                        class="text-sm text-green-600"
                                    >
                                        -₱{{
                                            formatCurrency(item.discount_amount)
                                        }}
                                        discount
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Order Summary -->
                <div
                    v-if="orderItems.length > 0"
                    class="bg-white rounded-lg shadow-sm border p-6"
                >
                    <h3 class="text-lg font-semibold text-gray-900 mb-4">
                        Order Summary
                    </h3>

                    <div class="space-y-3">
                        <div class="flex justify-between">
                            <span class="text-gray-600">Subtotal</span>
                            <span class="font-medium"
                                >₱{{ formatCurrency(subtotal) }}</span
                            >
                        </div>

                        <div
                            v-if="discountAmount > 0"
                            class="flex justify-between text-green-600"
                        >
                            <span>Discount</span>
                            <span>-₱{{ formatCurrency(discountAmount) }}</span>
                        </div>

                        <div class="flex justify-between">
                            <span class="text-gray-600">Tax</span>
                            <span class="font-medium"
                                >₱{{ formatCurrency(taxAmount) }}</span
                            >
                        </div>

                        <div class="border-t pt-3">
                            <div class="flex justify-between text-lg font-bold">
                                <span>Total</span>
                                <span class="text-green-600"
                                    >₱{{ formatCurrency(totalAmount) }}</span
                                >
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Customer Info (if available) -->
                <div
                    v-if="customer"
                    class="bg-white rounded-lg shadow-sm border p-6"
                >
                    <h3 class="text-lg font-semibold text-gray-900 mb-4">
                        Customer Information
                    </h3>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <p class="text-sm text-gray-600">Name</p>
                            <p class="font-medium">{{ customer.name }}</p>
                        </div>
                        <div v-if="customer.phone">
                            <p class="text-sm text-gray-600">Phone</p>
                            <p class="font-medium">{{ customer.phone }}</p>
                        </div>
                        <div v-if="customer.tier_info">
                            <p class="text-sm text-gray-600">Loyalty Tier</p>
                            <div class="flex items-center space-x-2">
                                <div
                                    class="w-3 h-3 rounded-full"
                                    :style="{
                                        backgroundColor:
                                            customer.tier_info.color,
                                    }"
                                ></div>
                                <span class="font-medium">{{
                                    customer.tier_info.name
                                }}</span>
                            </div>
                        </div>
                        <div v-if="customer.loyalty_points">
                            <p class="text-sm text-gray-600">Loyalty Points</p>
                            <p class="font-medium">
                                {{ customer.loyalty_points.toLocaleString() }}
                                pts
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Footer -->
        <div class="bg-white border-t mt-12">
            <div class="max-w-4xl mx-auto px-4 py-4">
                <div class="text-center text-sm text-gray-600">
                    <p>
                        This page updates automatically as your order is being
                        processed
                    </p>
                    <p class="mt-1">Powered by Techiko POS</p>
                </div>
            </div>
        </div>
    </div>
</template>

<style scoped>
.animate-pulse {
    animation: pulse 2s cubic-bezier(0.4, 0, 0.6, 1) infinite;
}

@keyframes pulse {
    0%,
    100% {
        opacity: 1;
    }
    50% {
        opacity: 0.5;
    }
}
</style>