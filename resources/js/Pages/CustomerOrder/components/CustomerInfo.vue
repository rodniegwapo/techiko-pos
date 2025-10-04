<template>
    <div class="bg-white rounded-lg shadow-sm border p-6">
        <div class="flex items-center space-x-3 mb-6">
            <div
                class="w-8 h-8 bg-purple-100 rounded-lg flex items-center justify-center"
            >
                <svg
                    class="w-5 h-5 text-purple-600"
                    fill="none"
                    stroke="currentColor"
                    viewBox="0 0 24 24"
                >
                    <path
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        stroke-width="2"
                        d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"
                    />
                </svg>
            </div>
            <div>
                <h3 class="text-xl font-bold text-gray-900">
                    Customer Information
                </h3>
                <p class="text-sm text-gray-600">
                    {{ customer ? (isLoyaltyCustomer ? 'Loyalty member details' : 'Registered customer') : 'Walk-in customer' }}
                </p>
            </div>
        </div>

        <!-- Customer Type Badge -->
        <div class="mb-6">
            <div 
                class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium"
                :class="getCustomerTypeClass()"
            >
                <div 
                    class="w-2 h-2 rounded-full mr-2"
                    :class="getCustomerTypeDotClass()"
                ></div>
                {{ getCustomerTypeText() }}
            </div>
        </div>

        <!-- Customer Details -->
        <div v-if="customer" class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <div class="space-y-4">
                <div>
                    <p class="text-sm text-gray-600 mb-1">Customer Name</p>
                    <p class="font-semibold text-gray-900 text-lg">
                        {{ customer.name }}
                    </p>
                </div>
                <div v-if="customer.phone">
                    <p class="text-sm text-gray-600 mb-1">Phone Number</p>
                    <p class="font-medium text-gray-900">
                        {{ customer.phone }}
                    </p>
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

        <!-- Walk-in Customer Message -->
        <div v-else class="text-center py-8">
            <div class="w-16 h-16 bg-gray-100 rounded-full flex items-center justify-center mx-auto mb-4">
                <svg class="w-8 h-8 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                </svg>
            </div>
            <h4 class="text-lg font-semibold text-gray-900 mb-2">Walk-in Customer</h4>
            <p class="text-gray-600 text-sm">
                This order is for a walk-in customer. No customer information is available.
            </p>
        </div>
    </div>
</template>

<script setup>
import { computed } from 'vue';

const props = defineProps({
    customer: {
        type: Object,
        default: null,
    },
});

// Determine if customer is a loyalty customer
const isLoyaltyCustomer = computed(() => {
    if (!props.customer) return false;
    
    // Check if customer has loyalty-related properties
    return !!(
        props.customer.loyalty_points || 
        props.customer.tier_info || 
        props.customer.loyalty_id ||
        props.customer.membership_number
    );
});

// Helper methods for customer type display
const getCustomerTypeClass = () => {
    if (!props.customer) return 'bg-gray-100 text-gray-800';
    return isLoyaltyCustomer.value 
        ? 'bg-purple-100 text-purple-800' 
        : 'bg-blue-100 text-blue-800';
};

const getCustomerTypeDotClass = () => {
    if (!props.customer) return 'bg-gray-500';
    return isLoyaltyCustomer.value ? 'bg-purple-500' : 'bg-blue-500';
};

const getCustomerTypeText = () => {
    if (!props.customer) return 'Walk-in Customer';
    return isLoyaltyCustomer.value ? 'Loyalty Customer' : 'Registered Customer';
};
</script>
