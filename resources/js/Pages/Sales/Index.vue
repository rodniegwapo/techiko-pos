<script setup>
import { ref, computed, onMounted, provide } from "vue";
import axios from "axios";

import ContentHeader from "@/Components/ContentHeader.vue";
import ContentLayoutV2 from "@/Components/ContentLayoutV2.vue";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import RefreshButton from "@/Components/buttons/Refresh.vue";
import FilterDropdown from "@/Components/filters/FilterDropdown.vue";
import ActiveFilters from "@/Components/filters/ActiveFilters.vue";
import ProductTable from "./components/ProductTable.vue";
import CustomerOrder from "./components/CustomerOrder.vue";
import TotalAmountSection from "./components/TotalAmountSection.vue";

import {
    CloseOutlined,
    PlusSquareOutlined,
    MinusSquareOutlined,
} from "@ant-design/icons-vue";

import { usePage, router, Head } from "@inertiajs/vue3";
import { useFilters, toLabel } from "@/Composables/useFilters";
import { watchDebounced } from "@vueuse/core";
import { useDomainRoutes } from "@/Composables/useDomainRoutes";

const page = usePage();
const { getRoute } = useDomainRoutes();

const search = ref("");
const category = ref();
const spinning = ref(false);

// Direct database integration - no composable needed
const orderId = ref(null);
const orders = ref([]);
const currentSale = ref(null);
const orderDiscountAmount = ref(0);
const orderDiscountId = ref('');
const isLoadingCart = ref(false);
const discountOptions = ref({
    product_discount_options: [],
    promotional_discount_options: [],
    mandatory_discount_options: []
});

// Direct API functions
const loadCurrentPendingSale = async () => {
    isLoadingCart.value = true;
    
    try {
        const userId = page.props.auth.user.data.id;
        const route = getRoute("users.sales.current-pending", { user: userId });
        console.log('Loading user pending sale, Route:', route);
        const response = await axios.get(route);
        console.log('User pending sale API response:', response.data);
        
        if (response.data.sale) {
            const { sale, items, discounts, totals, discount_options } = response.data;
            
            // Set the orderId from the found sale
            orderId.value = sale.id;
            
            // Store the full sale object for the modal
            currentSale.value = sale;
            console.log('Stored current sale:', currentSale.value);
            
            // Store discount options for modals
            if (discount_options) {
                discountOptions.value = discount_options;
                console.log('Loaded discount options:', discount_options);
                console.log('Product discount options:', discount_options.product_discount_options);
                console.log('Promotional discount options:', discount_options.promotional_discount_options);
                console.log('Mandatory discount options:', discount_options.mandatory_discount_options);
            }
            
            // Transform database response to match frontend expectations
            orders.value = transformCartItems(items);
            orderDiscountAmount.value = parseFloat(totals?.discount_amount) || 0;
            orderDiscountId.value = discounts?.map(d => d.discount_id).join(',') || '';
            
            console.log('User pending sale loaded:', { sale, items, totals });
            console.log('Updated orders array from loadCurrentPendingSale:', orders.value);
            
            // Log product-level discounts for debugging
            const productsWithDiscounts = orders.value.filter(order => 
                order.discount_id || order.discount_amount > 0
            );
            if (productsWithDiscounts.length > 0) {
                console.log('Products with discounts loaded:', productsWithDiscounts);
            } else {
                console.log('No product-level discounts found');
            }
            
            // Log order-level discounts for debugging
            if (orderDiscountAmount.value > 0) {
                console.log('Order-level discount loaded:', {
                    amount: orderDiscountAmount.value,
                    discount_ids: orderDiscountId.value
                });
            } else {
                console.log('No order-level discounts found');
            }
        } else {
            console.log('No pending sale found for user');
            orderId.value = null;
            orders.value = [];
            orderDiscountAmount.value = 0;
            orderDiscountId.value = '';
        }
    } catch (error) {
        console.error('Failed to load current pending sale:', error);
        orderId.value = null;
        orders.value = [];
        orderDiscountAmount.value = 0;
        orderDiscountId.value = '';
    } finally {
        isLoadingCart.value = false;
    }
};

const createDraft = async () => {
    console.log('createDraft called - now handled by user-specific routes');
    // This function is no longer needed as user-specific routes handle sale creation automatically
    return orderId.value;
};

// Utility function to transform cart items
const transformCartItems = (items) => {
    if (!items || !Array.isArray(items)) {
        console.warn('transformCartItems received invalid items:', items);
        return [];
    }
    
    return items.map(item => {
        console.log('Transforming item:', item);
        console.log('Product data:', item.product);
        console.log('Product name:', item.product?.name);
        console.log('Product ID:', item.product_id);
        
        const productName = item.product?.name || 'Unknown Product';
        console.log('Final product name:', productName);
        
        return {
            id: item.product_id,
            name: productName,
            price: item.unit_price,
            quantity: item.quantity,
            subtotal: item.unit_price * item.quantity,
            discount_id: item.discount_id,
            discount_type: item.discount_type,
            discount: item.discount,
            discount_amount: item.discount_amount,
            discounts: item.discounts || [], // Include the discounts relationship
        };
    });
};

// Customer state management
const selectedCustomer = ref(null);

// Handle customer changes from CustomerOrder component
const handleCustomerChanged = async (customer) => {
    selectedCustomer.value = customer;
    console.log("Customer changed:", customer);

    // Only load pending sale when customer is selected
    if (customer) {
        try {
            console.log('Customer selected, loading pending sale...');
            await loadCurrentPendingSale();
            
            // If no pending sale found, create a new draft
            if (!orderId.value) {
                console.log('No pending sale found, creating new draft...');
                await createDraft();
            }
            
            console.log('Assigning customer to sale:', { orderId: orderId.value, customer: customer?.id });
            
            if (!orderId.value) {
                console.error('OrderId is null or undefined after createDraft!');
                throw new Error('No order ID available');
            }
            
            const route = getRoute("sales.sales.assignCustomer", { sale: orderId.value });
            console.log('Generated route:', route);
            await axios.post(route, {
                customer_id: customer?.id || null,
            });
        } catch (error) {
            console.error("Error handling customer selection:", error);
        }
    } else {
        console.log('Customer deselected - keeping current order state');
    }
};

// Filters setup

// FilterDropdown configuration
const filtersConfig = [
    {
        key: "category",
        label: "Category",
        type: "select",
        options: (page.props?.categories ?? []).map((item) => ({
            label: item.name,
            value: item.name,
        })),
    },
];

const products = ref([]);
const loading = ref(false);
onMounted(async () => {
    console.log('Sales page loaded - checking for existing pending sale');
    
    // Load products first
    getProducts();
    
    // Auto-load current user's pending sale on page reload
    try {
        console.log('Auto-loading current user pending sale...');
        await loadCurrentPendingSale();
        
        if (orderId.value) {
            console.log('Found existing pending sale:', orderId.value);
            console.log('Cart state automatically loaded with items and discounts');
        } else {
            console.log('No pending sale found - waiting for customer selection');
        }
    } catch (error) {
        console.error('Failed to auto-load pending sale:', error);
        // Continue normally - user can still create new orders
    }
});

const getProducts = async () => {
    loading.value = true;
    const items = await axios.get(
        getRoute("sales.products", {
            category: category.value,
            search: search.value,
        })
    );
    products.value = items.data.data;
    loading.value = false;
};
const { filters, activeFilters, handleClearSelectedFilter } = useFilters({
    getItems: getProducts,
    configs: [
        {
            key: "category",
            ref: category,
            getLabel: toLabel(
                computed(() =>
                    (page.props?.categories ?? []).map((item) => ({
                        label: item.name,
                        value: item.name,
                    }))
                )
            ),
        },
    ],
});

watchDebounced(search, getProducts, { debounce: 300 });
</script>

<template>
    <AuthenticatedLayout>
        <Head title="Sales" />
        <ContentHeader title="Sales" />
        <ContentLayoutV2 title="Create Transaction">
            <template #filters>
                <a-input-search
                    v-model:value="search"
                    placeholder="Search Product"
                    class="min-w-[100px] max-w-[300px]"
                />
                <RefreshButton :loading="loading" @click="getProducts" />
                <FilterDropdown v-model="filters" :filters="filtersConfig" />
            </template>
            <template #activeFilters>
                <ActiveFilters
                    :filters="activeFilters"
                    @remove-filter="handleClearSelectedFilter"
                    @clear-all="
                        () =>
                            Object.keys(filters).forEach(
                                (k) => (filters[k] = null)
                            )
                    "
                    :always-show="true"
                />
            </template>

            <template #table>
                <ProductTable 
                    :products="products" 
                    :loading="loading"
                    :orders="orders"
                    :orderId="orderId"
                    @cart-updated="loadCurrentPendingSale"
                />
            </template>
            <template #right-side-content>
                <customer-order 
                    @customer-changed="handleCustomerChanged" 
                    @discount-applied="loadCurrentPendingSale"
                    @cart-updated="loadCurrentPendingSale"
                    :loading="isLoadingCart"
                    :orders="orders"
                    :orderId="orderId"
                    :orderDiscountAmount="orderDiscountAmount"
                    :orderDiscountId="orderDiscountId"
                    :discountOptions="discountOptions"
                />
            </template>
        </ContentLayoutV2>
        <template #content-footer>
            <total-amount-section 
                :selected-customer="selectedCustomer"
                :orders="orders"
                :currentSale="currentSale"
                :orderDiscountAmount="orderDiscountAmount"
                :orderDiscountId="orderDiscountId"
                :orderId="orderId"
                :discountOptions="discountOptions"
                @discount-applied="loadCurrentPendingSale"
                @cart-updated="loadCurrentPendingSale"
            />
        </template>
    </AuthenticatedLayout>
</template>
