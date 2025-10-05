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

const page = usePage();

const search = ref("");
const category = ref();
const spinning = ref(false);

// Get order ID from useOrders composable
const { orderId } = useOrders();

// Customer state management
const selectedCustomer = ref(null);

// Handle customer changes from CustomerOrder component
const handleCustomerChanged = async (customer) => {
    selectedCustomer.value = customer;
    console.log("Customer changed:", customer);

    // Call API to assign customer to sale
    if (orderId.value) {
        try {
            const response = await axios.post(
                `/api/sales/${orderId.value}/assign-customer`,
                {
                    customer_id: customer?.id || null,
                }
            );
        } catch (error) {
            console.error("Error assigning customer to sale:", error);
        }
    }
};

// Filters setup

// FilterDropdown configuration
const filtersConfig = [
    {
        key: "category",
        label: "Category",
        type: "select",
        options: page.props?.categories.map((item) => ({
            label: item.name,
            value: item.name,
        })),
    },
];

const products = ref([]);
const loading = ref(false);
onMounted(async () => {
    getProducts();
});

const getProducts = async () => {
    loading.value = true;
    const items = await axios.get(
        route("sales.products", {
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
                    page.props.categories.map((item) => ({
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
                <customer-order @customer-changed="handleCustomerChanged" />
            </template>
        </ContentLayoutV2>
        <template #content-footer>
            <total-amount-section :selected-customer="selectedCustomer" />
        </template>
    </AuthenticatedLayout>
</template>
