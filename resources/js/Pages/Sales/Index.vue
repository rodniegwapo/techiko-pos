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
import { useOrders } from "@/Composables/useOrderV2";
import { useDomainRoutes } from "@/Composables/useDomainRoutes";

const page = usePage();
const { getRoute } = useDomainRoutes();

const search = ref("");
const category = ref();
const spinning = ref(false);

// Get order functions from useOrders composable
const { orderId, createDraft, loadCartState, loadCurrentPendingSale, isLoadingCart } = useOrders();

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
    // Don't load any pending sale automatically
    console.log('Sales page loaded - waiting for customer selection');
    
    getProducts();
    // Remove automatic loading - only load when customer is selected
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
                <ProductTable :products="products" :loading="loading" />
            </template>
            <template #right-side-content>
                <customer-order 
                    @customer-changed="handleCustomerChanged" 
                    :loading="isLoadingCart"
                />
            </template>
        </ContentLayoutV2>
        <template #content-footer>
            <total-amount-section :selected-customer="selectedCustomer" />
        </template>
    </AuthenticatedLayout>
</template>
