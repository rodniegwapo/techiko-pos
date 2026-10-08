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
import { usePermissionsV2 } from "@/Composables/usePermissionV2";

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
const price_min = ref(null);
const price_max = ref(null);
const stock_status = ref(null);
const track_stock = ref(null);
const sort = ref(null);

const STOCK_STATUS_OPTIONS = [
    { label: "In stock", value: "in" },
    { label: "Low stock", value: "low" },
    { label: "Out of stock", value: "out" },
];
const TRACK_STOCK_OPTIONS = [
    { label: "Tracked", value: "yes" },
    { label: "Not tracked", value: "no" },
];
const SORT_OPTIONS = [
    { label: "Newest first", value: "newest" },
    { label: "Name (A–Z)", value: "name" },
    { label: "Price (low to high)", value: "price_asc" },
    { label: "Price (high to low)", value: "price_desc" },
    { label: "Quantity (low to high)", value: "qty_asc" },
];

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
        only: ["items", "stockCounts"],
        preserveScroll: true,
        data: {
            ...locationIdQuery(),
            search: search.value || undefined,
            sold_type: sold_type.value || undefined,
            price: price.value || undefined,
            category: category.value || undefined,
            cost: cost.value || undefined,
            price_min: price_min.value || undefined,
            price_max: price_max.value || undefined,
            stock_status: stock_status.value || undefined,
            track_stock: track_stock.value || undefined,
            sort: sort.value || undefined,
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
        { key: "price_min", ref: price_min, label: "Min price" },
        { key: "price_max", ref: price_max, label: "Max price" },
        {
            key: "stock_status",
            ref: stock_status,
            label: "Stock",
            getLabel: toLabel(computed(() => STOCK_STATUS_OPTIONS)),
        },
        {
            key: "track_stock",
            ref: track_stock,
            label: "Track stock",
            getLabel: toLabel(computed(() => TRACK_STOCK_OPTIONS)),
        },
        {
            key: "sort",
            ref: sort,
            label: "Sort",
            getLabel: toLabel(computed(() => SORT_OPTIONS)),
        },
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
    { key: "stock_status", label: "Stock", type: "select", options: STOCK_STATUS_OPTIONS },
    { key: "track_stock", label: "Track stock", type: "select", options: TRACK_STOCK_OPTIONS },
    { key: "price_min", label: "Min price", type: "number" },
    { key: "price_max", label: "Max price", type: "number" },
    { key: "cost", label: "Cost", type: "number" },
    { key: "price", label: "Price", type: "number" },
    { key: "sort", label: "Sort by", type: "select", options: SORT_OPTIONS },
];

// group all filters in one object
const tableFilters = {
    search,
    sold_type,
    price,
    category,
    cost,
    price_min,
    price_max,
    stock_status,
    track_stock,
    sort,
};

/** Low / out of stock counts for the store; clicking one filters the list to it. */
const stockCounts = computed(() => page.props.stockCounts ?? { low: 0, out: 0 });
const showStockStatus = (status) => {
    filters.value = { ...filters.value, stock_status: status };
};
const { pagination, handleTableChange } = useTable("items", tableFilters, {
    preserveQueryKeys: ["location_id"],
});

const { hasPermission } = usePermissionsV2();
const canCreate = computed(() => hasPermission("products.create"));
const canAttach = computed(() => hasPermission("products.attach-location"));

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
        <ContentHeader class="mb-4 md:mb-8" title="Products" />
        <ContentLayout
            title="Products"
            filter-class="flex flex-wrap items-center justify-end gap-2 w-full min-w-0"
        >
            <!-- Filters -->
            <template #filters>
                <RefreshButton :loading="spinning" @click="getItems" />
                <a-input-search
                    v-model:value="search"
                    placeholder="Search products"
                    class="w-full min-w-0 md:max-w-[300px]"
                />

                <Link
                    v-if="hasPermission('products.modifier-groups.index')"
                    class="block w-full md:w-auto"
                    :href="getRoute('products.modifier-groups.index')"
                >
                    <a-button class="w-full md:w-auto">Modifiers</a-button>
                </Link>

                <template v-if="canCreate">
                <Link
                    v-if="!productsAtCapacity"
                    class="block w-full md:w-auto"
                    :href="
                        hrefWithPreservedLocationId(getRoute('products.create'))
                    "
                >
                    <a-button
                        type="primary"
                        class="flex w-full items-center justify-center border border-green-500 bg-white text-green-500 md:inline-flex md:w-auto"
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
                    <span class="block w-full md:inline-block md:w-auto">
                        <a-button
                            type="primary"
                            disabled
                            class="flex w-full items-center justify-center border border-gray-300 bg-white text-gray-400 md:inline-flex md:w-auto"
                        >
                            <template #icon>
                                <PlusSquareOutlined />
                            </template>
                            Create Product
                        </a-button>
                    </span>
                </a-tooltip>
                </template>
                <a-button
                    v-if="
                        canAttach &&
                        hasMultipleStores &&
                        effectiveLocationId != null &&
                        effectiveLocationId !== ''
                    "
                    type="default"
                    class="flex w-full items-center justify-center border-blue-500 text-blue-600 md:inline-flex md:w-auto"
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
                <div
                    v-if="stockCounts.low > 0 || stockCounts.out > 0"
                    class="mt-2 flex flex-wrap gap-2"
                >
                    <a-tag
                        v-if="stockCounts.low > 0"
                        color="orange"
                        class="cursor-pointer"
                        role="button"
                        @click="showStockStatus('low')"
                    >
                        {{ stockCounts.low }} low stock
                    </a-tag>
                    <a-tag
                        v-if="stockCounts.out > 0"
                        color="red"
                        class="cursor-pointer"
                        role="button"
                        @click="showStockStatus('out')"
                    >
                        {{ stockCounts.out }} out of stock
                    </a-tag>
                </div>
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
