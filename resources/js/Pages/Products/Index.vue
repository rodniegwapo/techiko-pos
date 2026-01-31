<script setup>
import { ref, computed, onMounted } from "vue";
import { usePage, router, Head, Link } from "@inertiajs/vue3";
import { PlusSquareOutlined } from "@ant-design/icons-vue";
import { watchDebounced } from "@vueuse/core";
import { useFilters, toLabel } from "@/Composables/useFilters";
import { useHelpers } from "@/Composables/useHelpers";
import { useGlobalVariables } from "@/Composables/useGlobalVariable";
import { useTable } from "@/Composables/useTable";
import { useDomainRoutes } from "@/Composables/useDomainRoutes";

import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import ContentHeader from "@/Components/ContentHeader.vue";
import ContentLayout from "@/Components/ContentLayout.vue";
import RefreshButton from "@/Components/buttons/Refresh.vue";
import FilterDropdown from "@/Components/filters/FilterDropdown.vue";
import ActiveFilters from "@/Components/filters/ActiveFilters.vue";
import ProductTable from "./components/ProductTable.vue";
import LocationInfoAlert from "@/Components/LocationInfoAlert.vue";

const page = usePage();
// const { showModal } = useHelpers(); // Removed as we navigate to page now
const { spinning } = useGlobalVariables();
const { getRoute } = useDomainRoutes();

const search = ref("");
const sold_type = ref(null);
const price = ref(null);
const category = ref(null);
const cost = ref(null);

// Fetch items
const getItems = () => {
    router.reload({
        only: ["items"],
        preserveScroll: true,
        data: {
            search: search.value || undefined,
            sold_type: sold_type.value || undefined,
            price: price.value || undefined,
            category: category.value || undefined,
            cost: cost.value || undefined,
            // page: pagination.value.current_page || 1,
        },
        onStart: () => (spinning.value = true),
        onFinish: () => (spinning.value = false),
    });
};

// Watch search with debounce
watchDebounced(search, getItems, { debounce: 300 });

// Filters setup
const { filters, activeFilters, handleClearSelectedFilter } = useFilters({
    getItems,
    configs: [
        {
            label: "Category",
            key: "category",
            ref: category,
            getLabel: toLabel(
                computed(() =>
                    (page.props?.categories ?? []).map((item) => ({
                        label: item.name,
                        value: item.name,
                    })),
                ),
            ),
        },
        {
            label: "Sold type",
            key: "sold_type",
            ref: sold_type,
            getLabel: toLabel(
                computed(() =>
                    (page.props?.sold_by_types ?? []).map((item) => ({
                        label: item.name,
                        value: item.name,
                    })),
                ),
            ),
        },
        { key: "cost", ref: cost, label: "Cost" },
        { key: "price", ref: price, label: "Price" },
    ],
});

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
    {
        key: "sold_type",
        label: "Sold Type",
        type: "select",
        options: (page.props?.sold_by_types ?? []).map((item) => ({
            label: item.name,
            value: item.name,
        })),
    },
    { key: "cost", label: "Cost", type: "number" },
    { key: "price", label: "Price", type: "number" },
];

// group all filters in one object
const tableFilters = { search, sold_type, price, category, cost };
const { pagination, handleTableChange } = useTable("items", tableFilters);
</script>

<template>
    <AuthenticatedLayout>
        <Head title="Products" />
        <ContentHeader class="mb-8" title="Products" />
        <ContentLayout title="Products">
            <!-- Filters -->
            <template #filters>
                <RefreshButton :loading="spinning" @click="getItems" />
                <a-input-search
                    v-model:value="search"
                    placeholder="Search products"
                    class="min-w-[100px] max-w-[300px]"
                />

                <Link :href="getRoute('products.create')">
                    <a-button
                        type="primary"
                        class="bg-white border flex items-center border-green-500 text-green-500"
                    >
                        <template #icon>
                            <PlusSquareOutlined />
                        </template>
                        Create Product
                    </a-button>
                </Link>
                <FilterDropdown v-model="filters" :filters="filtersConfig" />
            </template>

            <!-- Active Filters -->
            <template #activeFilters>
                <ActiveFilters
                    :filters="activeFilters"
                    @remove-filter="handleClearSelectedFilter"
                    @clear-all="
                        () =>
                            Object.keys(filters).forEach(
                                (k) => (filters[k] = null),
                            )
                    "
                />
            </template>

            <template #activeStore>
                <LocationInfoAlert />
            </template>

            <!-- Table -->
            <template #table>
                <ProductTable
                    @handle-table-change="handleTableChange"
                    :pagination="pagination"
                    :is-global-view="page.props.isGlobalView"
                />
            </template>
        </ContentLayout>
    </AuthenticatedLayout>
</template>
