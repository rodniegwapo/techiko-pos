<script setup>
import { ref, computed, onMounted } from "vue";
import { usePage, router, Head, Link } from "@inertiajs/vue3";
import { PlusSquareOutlined, ShopOutlined } from "@ant-design/icons-vue";
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
import AttachProductToLocationModal from "./components/AttachProductToLocationModal.vue";
import LocationInfoAlert from "@/Components/LocationInfoAlert.vue";

const page = usePage();
// const { showModal } = useHelpers(); // Removed as we navigate to page now
const { spinning } = useGlobalVariables();
const { getRoute, hrefWithPreservedLocationId } = useDomainRoutes();

const search = ref("");
const sold_type = ref(null);
const price = ref(null);
const category = ref(null);
const cost = ref(null);

const locationIdQuery = () => {
    if (typeof window === "undefined") {
        return {};
    }
    const url = new URL(page.url, window.location.origin);
    const id = url.searchParams.get("location_id");
    return id ? { location_id: id } : {};
};

// Fetch items
const getItems = () => {
    router.reload({
        only: ["items"],
        preserveScroll: true,
        data: {
            ...locationIdQuery(),
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
const { pagination, handleTableChange } = useTable("items", tableFilters, {
    preserveQueryKeys: ["location_id"],
});

const subscription = computed(() => page.props.subscription ?? null);

const productsAtCapacity = computed(
    () => subscription.value?.products_at_capacity === true,
);

const attachModalOpen = ref(false);

const effectiveLocationId = computed(() => {
    const cur = page.props.currentLocation;
    if (cur?.id != null) {
        return cur.id;
    }
    const lid = locationIdQuery().location_id;
    return lid != null && lid !== "" ? lid : null;
});

const hasMultipleStores = computed(() => {
    const locs = page.props.availableLocations;
    const n = Array.isArray(locs) ? locs.length : 0;
    return n > 1;
});
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

                <Link
                    v-if="!productsAtCapacity"
                    :href="
                        hrefWithPreservedLocationId(getRoute('products.create'))
                    "
                >
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
                <a-tooltip
                    v-else
                    title="Free plan is limited to 10 products. Open Servicing Payment to subscribe."
                >
                    <span class="inline-block">
                        <a-button
                            type="primary"
                            disabled
                            class="bg-white border flex items-center border-gray-300 text-gray-400"
                        >
                            <template #icon>
                                <PlusSquareOutlined />
                            </template>
                            Create Product
                        </a-button>
                    </span>
                </a-tooltip>
                <a-button
                    v-if="
                        hasMultipleStores &&
                        effectiveLocationId != null &&
                        effectiveLocationId !== ''
                    "
                    type="default"
                    class="flex items-center border-blue-500 text-blue-600"
                    @click="attachModalOpen = true"
                >
                    <template #icon>
                        <ShopOutlined />
                    </template>
                    Add existing to store
                </a-button>
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
        <AttachProductToLocationModal
            v-model:visible="attachModalOpen"
            :location-id="effectiveLocationId"
            @attached="getItems"
        />
    </AuthenticatedLayout>
</template>
