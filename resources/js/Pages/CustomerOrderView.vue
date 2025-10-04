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

// Computed properties - Use actual sale data from backend
const subtotal = computed(() => {
    // Use the sale's total_amount which is calculated by the backend
    return order.value?.total_amount || order.value?.totals?.subtotal || 0;
});

const discountAmount = computed(() => {
    // Use the sale's discount_amount which includes both item and order discounts
    return order.value?.discount_amount || order.value?.totals?.discount_amount || 0;
});

const taxAmount = computed(() => {
    // Use the sale's tax_amount (currently 0 since tax is disabled)
    return order.value?.tax_amount || order.value?.totals?.tax_amount || 0;
});

const totalAmount = computed(() => {
    // Use the sale's grand_total which is the final calculated amount
    return order.value?.grand_total || order.value?.totals?.grand_total || 0;
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
        orderItems.value = data.order.sale_items || data.order.items || [];
        customer.value = data.order.customer;
        lastUpdated.value = new Date().toLocaleTimeString();
        loading.value = false;
    }
};

const fetchInitialOrder = async () => {
    try {
        // Try to get the most recent pending order
        const response = await fetch("/api/orders/recent-pending");
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
        <!-- Header -->
        <div class="bg-gradient-to-r from-blue-600 to-blue-700 shadow-lg">
            <div class="max-w-4xl mx-auto px-4 py-6">
                <div class="flex items-center justify-between">
                    <div>
                        <h1 class="text-3xl font-bold text-white mb-2">
                            Your Order
                        </h1>
                        <div class="flex items-center space-x-4">
                            <p class="text-blue-100">
                                Order #{{ orderId || "Waiting for order..." }}
                            </p>
                            <div v-if="orderItems.length > 0" class="flex items-center space-x-2">
                                <div class="w-2 h-2 bg-blue-200 rounded-full"></div>
                                <span class="text-blue-100 text-sm">
                                    {{ orderItems.length }} item{{ orderItems.length !== 1 ? 's' : '' }}
                                </span>
                            </div>
                        </div>
                    </div>
                    <div class="flex items-center space-x-3">
                        <div class="bg-white/20 backdrop-blur-sm rounded-lg px-4 py-2">
                            <div class="flex items-center space-x-2">
                                <div class="w-3 h-3 bg-green-400 rounded-full animate-pulse"></div>
                                <span class="text-white text-sm font-medium">Live Updates</span>
                            </div>
                        </div>
                        <div v-if="totalAmount > 0" class="bg-white/20 backdrop-blur-sm rounded-lg px-4 py-2">
                            <div class="text-right">
                                <div class="text-white text-sm">Total</div>
                                <div class="text-white text-xl font-bold">
                                    ₱{{ formatCurrency(totalAmount) }}
                                </div>
                            </div>
                        </div>
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
                        <div class="flex items-center space-x-4">
                            <div class="w-12 h-12 bg-green-100 rounded-full flex items-center justify-center">
                                <svg class="w-6 h-6 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
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
                                {{ orderItems.length }} item{{ orderItems.length !== 1 ? 's' : '' }} • Ready for payment
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Order Items -->
                <div class="bg-white rounded-lg shadow-sm border">
                    <div class="p-6 border-b bg-gray-50">
                        <div class="flex items-center space-x-3">
                            <div class="w-8 h-8 bg-blue-100 rounded-lg flex items-center justify-center">
                                <svg class="w-5 h-5 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path>
                                </svg>
                            </div>
                            <div>
                                <h3 class="text-xl font-bold text-gray-900">
                                    Order Items
                                </h3>
                                <p class="text-sm text-gray-600">
                                    {{ orderItems.length }} item{{ orderItems.length !== 1 ? 's' : '' }} in your order
                                </p>
                            </div>
                        </div>
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
                            <div class="flex items-start justify-between">
                                <div class="flex-1">
                                    <div class="flex items-start space-x-4">
                                        <div
                                            class="w-14 h-14 bg-gradient-to-br from-blue-100 to-blue-200 rounded-lg flex items-center justify-center shadow-sm"
                                        >
                                            <BoxPlotOutlined
                                                class="text-blue-600 text-lg"
                                            />
                                        </div>
                                        <div class="flex-1">
                                            <h4
                                                class="font-semibold text-gray-900 text-lg mb-1"
                                            >
                                                {{
                                                    item.product_name ||
                                                    "Unknown Product"
                                                }}
                                            </h4>
                                            <p class="text-sm text-gray-500 mb-2">
                                                SKU: {{ item.product_sku || "N/A" }}
                                            </p>
                                            
                                            <!-- Price and Quantity Info -->
                                            <div class="flex items-center space-x-6 mb-2">
                                                <div class="flex items-center space-x-2">
                                                    <span class="text-sm text-gray-600">Price:</span>
                                                    <span class="font-medium text-gray-900">
                                                        ₱{{ formatCurrency(item.unit_price) }}
                                                    </span>
                                                </div>
                                                <div class="flex items-center space-x-2">
                                                    <span class="text-sm text-gray-600">Qty:</span>
                                                    <span class="font-medium text-gray-900">
                                                        {{ item.quantity }}
                                                    </span>
                                                </div>
                                            </div>

                                            <!-- Discount Information -->
                                            <div v-if="item.discount && item.discount > 0" 
                                                 class="bg-green-50 border border-green-200 rounded-lg p-3 mt-2">
                                                <div class="flex items-center space-x-2 mb-1">
                                                    <div class="w-2 h-2 bg-green-500 rounded-full"></div>
                                                    <span class="text-sm font-medium text-green-800">Item Discount Applied</span>
                                                </div>
                                                <div class="text-sm text-green-700">
                                                    Discount: -₱{{ formatCurrency(item.discount) }}
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Price Summary -->
                                <div class="text-right ml-4">
                                    <!-- Original Total (if discount applied) -->
                                    <div v-if="item.discount && item.discount > 0" 
                                         class="text-sm text-gray-500 line-through mb-1">
                                        ₱{{ formatCurrency(item.unit_price * item.quantity) }}
                                    </div>
                                    
                                    <!-- Final Total -->
                                    <div class="text-xl font-bold text-gray-900 mb-1">
                                        ₱{{ formatCurrency(item.subtotal || item.total_price || (item.unit_price * item.quantity)) }}
                                    </div>
                                    
                                    <!-- Savings (if discount applied) -->
                                    <div v-if="item.discount && item.discount > 0" 
                                         class="text-sm font-medium text-green-600">
                                        You save ₱{{ formatCurrency(item.discount) }}
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
                    <div class="flex items-center space-x-2 mb-6">
                        <div class="w-8 h-8 bg-blue-100 rounded-lg flex items-center justify-center">
                            <svg class="w-5 h-5 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z"></path>
                            </svg>
                        </div>
                        <h3 class="text-xl font-bold text-gray-900">Order Summary</h3>
                    </div>

                    <div class="space-y-4">
                        <!-- Subtotal -->
                        <div class="flex justify-between items-center py-2">
                            <div class="flex items-center space-x-2">
                                <span class="text-gray-600">Items Subtotal</span>
                                <span class="text-xs text-gray-400">({{ orderItems.length }} items)</span>
                            </div>
                            <span class="font-semibold text-gray-900 text-lg">
                                ₱{{ formatCurrency(subtotal) }}
                            </span>
                        </div>

                        <!-- Discounts Section -->
                        <div v-if="discountAmount > 0" class="bg-green-50 border border-green-200 rounded-lg p-4">
                            <div class="flex items-center space-x-2 mb-2">
                                <div class="w-5 h-5 bg-green-500 rounded-full flex items-center justify-center">
                                    <svg class="w-3 h-3 text-white" fill="currentColor" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"></path>
                                    </svg>
                                </div>
                                <span class="font-medium text-green-800">Discounts Applied</span>
                            </div>
                            <div class="flex justify-between items-center">
                                <span class="text-green-700">Total Discounts</span>
                                <span class="font-bold text-green-800 text-lg">
                                    -₱{{ formatCurrency(discountAmount) }}
                                </span>
                            </div>
                            <div class="text-xs text-green-600 mt-1">
                                You're saving {{ ((discountAmount / subtotal) * 100).toFixed(1) }}% on this order
                            </div>
                        </div>

                        <!-- Tax Section -->
                        <div v-if="taxAmount > 0" class="flex justify-between items-center py-2 border-t border-gray-200">
                            <div class="flex items-center space-x-2">
                                <span class="text-gray-600">Tax</span>
                                <span class="text-xs text-gray-400">(VAT)</span>
                            </div>
                            <span class="font-semibold text-gray-900">
                                ₱{{ formatCurrency(taxAmount) }}
                            </span>
                        </div>

                        <!-- Total Section -->
                        <div class="border-t-2 border-gray-300 pt-4">
                            <div class="flex justify-between items-center">
                                <span class="text-xl font-bold text-gray-900">Total Amount</span>
                                <span class="text-2xl font-bold text-green-600">
                                    ₱{{ formatCurrency(totalAmount) }}
                                </span>
                            </div>
                            <div class="text-sm text-gray-500 mt-1 text-right">
                                {{ orderItems.length }} item{{ orderItems.length !== 1 ? 's' : '' }} in your order
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Customer Info (if available) -->
                <div
                    v-if="customer"
                    class="bg-white rounded-lg shadow-sm border p-6"
                >
                    <div class="flex items-center space-x-3 mb-6">
                        <div class="w-8 h-8 bg-purple-100 rounded-lg flex items-center justify-center">
                            <svg class="w-5 h-5 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                            </svg>
                        </div>
                        <div>
                            <h3 class="text-xl font-bold text-gray-900">
                                Customer Information
                            </h3>
                            <p class="text-sm text-gray-600">
                                Loyalty member details
                            </p>
                        </div>
                    </div>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div class="space-y-4">
                            <div>
                                <p class="text-sm text-gray-600 mb-1">Customer Name</p>
                                <p class="font-semibold text-gray-900 text-lg">{{ customer.name }}</p>
                            </div>
                            <div v-if="customer.phone">
                                <p class="text-sm text-gray-600 mb-1">Phone Number</p>
                                <p class="font-medium text-gray-900">{{ customer.phone }}</p>
                            </div>
                        </div>
                        
                        <div class="space-y-4">
                            <div v-if="customer.tier_info">
                                <p class="text-sm text-gray-600 mb-1">Loyalty Tier</p>
                                <div class="flex items-center space-x-3">
                                    <div
                                        class="w-4 h-4 rounded-full shadow-sm"
                                        :style="{
                                            backgroundColor: customer.tier_info.color,
                                        }"
                                    ></div>
                                    <span class="font-semibold text-gray-900 text-lg">{{
                                        customer.tier_info.name
                                    }}</span>
                                </div>
                            </div>
                            <div v-if="customer.loyalty_points">
                                <p class="text-sm text-gray-600 mb-1">Loyalty Points</p>
                                <div class="flex items-center space-x-2">
                                    <span class="font-bold text-purple-600 text-xl">
                                        {{ customer.loyalty_points.toLocaleString() }}
                                    </span>
                                    <span class="text-sm text-gray-600">points</span>
                                </div>
                            </div>
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
