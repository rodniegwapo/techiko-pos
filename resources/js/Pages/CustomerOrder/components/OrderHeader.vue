<template>
    <div class="bg-gradient-to-r text-gray-700 border-b z-10 bg-gray-50 shadow">
        <div class="max-w-7xl mx-auto px-4 py-6">
            <div class="flex items-center justify-between">
                <div class="flex items-center space-x-4">
                    <div
                        class="w-12 h-12 bg-green-50 border border-green-400 rounded-lg flex items-center justify-center"
                    >
                        <ShoppingCartOutlined class="text-green-700 text-xl" />
                    </div>
                    <div>
                        <h1 class="text-2xl font-bold">Your Order</h1>
                        <p class="text-gray-700">
                            {{ getCustomerTypeText() }} • {{ itemCount }} item{{
                                itemCount !== 1 ? "s" : ""
                            }}
                        </p>
                    </div>
                </div>
                <div class="text-right">
                    <div class="text-3xl font-bold mb-1">
                        ₱{{ formatCurrency(totalAmount) }}
                    </div>
                </div>
            </div>
            
            <!-- Customer Information Section -->
            <div class="mt-6 pt-6 border-t border-gray-200">
                <div class="flex items-center justify-between">
                    <div class="flex items-center space-x-3">
                        <div class="w-8 h-8 bg-gray-100 rounded-lg flex items-center justify-center">
                            <svg class="w-4 h-4 text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                            </svg>
                        </div>
                        <div>
                            <h3 class="text-lg font-semibold text-gray-900">Customer Information</h3>
                            <p class="text-gray-600 text-sm">
                                {{ getCustomerDescription() }}
                            </p>
                        </div>
                    </div>
                    
                    <!-- Customer Type Badge -->
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
                
                <!-- Customer Details (if available) -->
                <div v-if="customer" class="mt-4 grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div>
                        <p class="text-gray-500 text-xs mb-1">Customer Name</p>
                        <p class="text-gray-900 font-medium">{{ customer.name }}</p>
                    </div>
                    <div v-if="customer.phone">
                        <p class="text-gray-500 text-xs mb-1">Phone Number</p>
                        <p class="text-gray-900 font-medium">{{ customer.phone }}</p>
                    </div>
                    <div v-if="customer.loyalty_points">
                        <p class="text-gray-500 text-xs mb-1">Loyalty Points</p>
                        <p class="text-gray-900 font-medium">{{ customer.loyalty_points.toLocaleString() }} points</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { computed, watch } from 'vue';
import { ShoppingCartOutlined } from "@ant-design/icons-vue";

const props = defineProps({
    orderId: {
        type: [String, Number],
        default: null,
    },
    itemCount: {
        type: Number,
        default: 0,
    },
    totalAmount: {
        type: Number,
        default: 0,
    },
    customer: {
        type: Object,
        default: null,
    },
});

// Determine if customer is a loyalty customer
const isLoyaltyCustomer = computed(() => {
    if (!props.customer) {
        console.log('No customer data available - Walk-in customer');
        return false;
    }
    
    console.log('Customer data:', props.customer);
    
    // Check if customer has loyalty-related properties
    const hasLoyaltyPoints = props.customer.loyalty_points && props.customer.loyalty_points > 0;
    const hasTierInfo = props.customer.tier_info && props.customer.tier_info.name;
    const hasLoyaltyId = props.customer.loyalty_id;
    const hasMembershipNumber = props.customer.membership_number;
    
    const isLoyalty = !!(hasLoyaltyPoints || hasTierInfo || hasLoyaltyId || hasMembershipNumber);
    
    console.log('Loyalty check:', {
        hasLoyaltyPoints,
        hasTierInfo,
        hasLoyaltyId,
        hasMembershipNumber,
        isLoyalty
    });
    
    return isLoyalty;
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
    if (!props.customer) {
        console.log('getCustomerTypeText: No customer - Walk-in Customer');
        return 'Walk-in Customer';
    }
    
    const customerType = isLoyaltyCustomer.value ? 'Loyalty Customer' : 'Registered Customer';
    console.log('getCustomerTypeText:', customerType, 'for customer:', props.customer);
    return customerType;
};

const getCustomerDescription = () => {
    if (!props.customer) {
        return 'Walk-in customer';
    }
    
    if (isLoyaltyCustomer.value) {
        return 'Loyalty member details';
    }
    
    return 'Registered customer';
};

const formatCurrency = (amount) => {
    return new Intl.NumberFormat("en-PH", {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    }).format(amount);
};

// Watch for customer changes
watch(() => props.customer, (newCustomer, oldCustomer) => {
    console.log('Customer prop changed:', {
        old: oldCustomer,
        new: newCustomer,
        isLoyalty: isLoyaltyCustomer.value
    });
}, { deep: true, immediate: true });
</script>
