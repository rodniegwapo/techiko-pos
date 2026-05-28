<script setup>
import { ref, computed, onMounted, watch } from "vue";
import { usePage, router, Head } from "@inertiajs/vue3";
import {
    DollarOutlined,
    BoxPlotOutlined,
    DownloadOutlined,
    PrinterOutlined,
} from "@ant-design/icons-vue";
import { IconWorld } from "@tabler/icons-vue";
import { useGlobalVariables } from "@/Composables/useGlobalVariable";
import { useFilters, toLabel } from "@/Composables/useFilters";
import { watchDebounced, useMediaQuery } from "@vueuse/core";

import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import ContentHeader from "@/Components/ContentHeader.vue";
import ContentLayout from "@/Components/ContentLayout.vue";
import RefreshButton from "@/Components/buttons/Refresh.vue";
import FilterDropdown from "@/Components/filters/FilterDropdown.vue";
import ActiveFilters from "@/Components/filters/ActiveFilters.vue";
import LocationInfoAlert from "@/Components/LocationInfoAlert.vue";

const page = usePage();
const { spinning } = useGlobalVariables();
const isMdUp = useMediaQuery("(min-width: 768px)");

const selectedLocation = ref(null);
const selectedDomain = ref(null);

// Props from backend
const props = defineProps({
    location: Object,
    summary: Object,
    items: Array,
    locations: Array,
    filters: Object,
    domains: Array,
});

// Initialize filters from backend
onMounted(() => {
    if (props.filters) {
        selectedLocation.value =
            props.filters.location_id || props.location?.id || null;
        selectedDomain.value = props.filters.domain || null;
    }
});

// Computed values
const totalValue = computed(() => props.summary?.total_value || 0);
const totalQuantity = computed(() => props.summary?.total_quantity || 0);
const totalProducts = computed(() => props.summary?.total_products || 0);

// Fetch items
const getItems = () => {
    router.reload({
        only: ["location", "summary", "items"],
        preserveScroll: true,
        data: {
            location_id: selectedLocation.value || undefined,
            domain: selectedDomain.value || undefined,
        },
        onStart: () => (spinning.value = true),
        onFinish: () => (spinning.value = false),
    });
};

// Watch domain changes to update locations
watch(selectedDomain, (newDomain) => {
    if (newDomain) {
        // Clear location selection when domain changes
        selectedLocation.value = null;
        // Fetch items to update the report
        getItems();
    }
});

// Watch search with debounce

// Filter options - Location options should be filtered by selected domain
const locationOptions = computed(() => {
    if (!page.props.isGlobalView || !selectedDomain.value) {
        return (
            props.locations?.map((loc) => ({
                label: loc.name,
                value: loc.id,
            })) || []
        );
    }

    // Filter locations by selected domain
    return (
        props.locations
            ?.filter((loc) => loc.domain === selectedDomain.value)
            ?.map((loc) => ({
                label: loc.name,
                value: loc.id,
            })) || []
    );
});

const domainOptions = computed(() =>
    (props.domains || []).map((domain) => ({
        label: domain.name,
        value: domain.name_slug,
    }))
);

// Table columns with conditional domain column
const tableColumns = computed(() => {
    const baseColumns = [
        {
            title: "Product Name",
            dataIndex: "product_name",
            key: "product_name",
            align: "left",
            sorter: (a, b) => a.product_name.localeCompare(b.product_name),
        },
        {
            title: "SKU",
            dataIndex: "sku",
            key: "sku",
        },
        {
            title: "Quantity",
            dataIndex: "quantity_on_hand",
            key: "quantity_on_hand",
            width: 100,
            align: "left",
            sorter: (a, b) => a.quantity_on_hand - b.quantity_on_hand,
        },
        {
            title: "Average Cost",
            dataIndex: "average_cost",
            key: "average_cost",
            align: "left",
            customRender: ({ text }) => `₱${parseFloat(text).toFixed(2)}`,
            sorter: (a, b) => a.average_cost - b.average_cost,
        },
        {
            title: "Total Value",
            dataIndex: "total_value",
            key: "total_value",
            align: "left",
            customRender: ({ text }) =>
                `₱${parseFloat(text).toLocaleString("en-US", {
                    minimumFractionDigits: 2,
                })}`,
            sorter: (a, b) => a.total_value - b.total_value,
        },
        {
            title: "Last Movement",
            dataIndex: "last_movement_at",
            key: "last_movement_at",
            customRender: ({ text }) =>
                text ? new Date(text).toLocaleDateString() : "N/A",
        },
    ];

    // Add domain column for super users only in global view
    if (page.props.auth?.user?.data?.is_super_user && page.props.isGlobalView) {
        baseColumns.splice(2, 0, {
            title: "Domain",
            dataIndex: "domain",
            key: "domain",
            align: "left",
            sorter: (a, b) => a.domain.localeCompare(b.domain),
        });
    }

    return baseColumns;
});

const showSuperUserDomain = computed(
    () =>
        page.props.auth?.user?.data?.is_super_user && page.props.isGlobalView,
);

const formatCurrency = (value) =>
    `₱${parseFloat(value || 0).toLocaleString("en-US", {
        minimumFractionDigits: 2,
    })}`;

const formatLastMovement = (date) =>
    date ? new Date(date).toLocaleDateString() : "N/A";

// Filter management - Domain first, then Location
const { filters, activeFilters, handleClearSelectedFilter } = useFilters({
    getItems,
    configs: [
        ...(page.props.isGlobalView
            ? [
                  {
                      label: "Domain",
                      key: "domain",
                      ref: selectedDomain,
                      getLabel: toLabel(computed(() => domainOptions.value)),
                  },
              ]
            : []),
        {
            label: "Location",
            key: "location_id",
            ref: selectedLocation,
            getLabel: toLabel(computed(() => locationOptions.value)),
        },
    ],
});

// FilterDropdown configuration - Domain first
const filtersConfig = [
    ...(page.props.isGlobalView
        ? [
              {
                  key: "domain",
                  label: "Domain",
                  type: "select",
                  options: domainOptions.value,
              },
          ]
        : []),
    {
        key: "location_id",
        label: "Location",
        type: "select",
        options: locationOptions.value,
    },
];

// Methods
const changeLocation = (locationId) => {
    selectedLocation.value = locationId;
    getItems();
};

const exportValuation = () => {
    // TODO: Implement export functionality
    console.log("Export valuation report");
};

const printValuation = () => {
    // TODO: Implement print functionality
    window.print();
};
</script>

<template>
    <Head title="Inventory Valuation Report" />

    <AuthenticatedLayout>
        <ContentHeader
            class="mb-4 md:mb-8"
            title="Inventory Valuation Report"
        />

        <ContentLayout
            title="Valuation Report"
            filter-class="flex flex-wrap items-center justify-end gap-2 w-full min-w-0"
        >
            <template #filters>
                <RefreshButton :loading="spinning" @click="getItems" />

                <a-button
                    class="w-full md:w-auto"
                    @click="exportValuation"
                    type="primary"
                >
                    <template #icon>
                        <DownloadOutlined />
                    </template>
                    Export
                </a-button>
                <a-button
                    class="w-full md:w-auto"
                    @click="printValuation"
                    type="primary"
                >
                    <template #icon>
                        <PrinterOutlined />
                    </template>
                    Print
                </a-button>
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
                />
            </template>

            <template #activeStore>
                <LocationInfoAlert />
            </template>

            <template #table>
                <!-- Summary Cards -->
                <div class="grid grid-cols-1 md:grid-cols-3 gap-6 p-6">
                    <!-- Total Value -->
                    <div class="bg-gray-50 rounded-lg p-6 border">
                        <div class="flex items-center">
                            <div class="p-3 rounded-full bg-green-100 mr-4">
                                <DollarOutlined
                                    class="text-2xl text-green-600"
                                />
                            </div>
                            <div>
                                <p class="text-sm text-gray-600">
                                    Total Inventory Value
                                </p>
                                <p class="text-2xl font-bold text-green-600">
                                    ₱{{
                                        totalValue.toLocaleString("en-US", {
                                            minimumFractionDigits: 2,
                                        })
                                    }}
                                </p>
                            </div>
                        </div>
                    </div>

                    <!-- Total Quantity -->
                    <div class="bg-gray-50 rounded-lg p-6 border">
                        <div class="flex items-center">
                            <div class="p-3 rounded-full bg-blue-100 mr-4">
                                <BoxPlotOutlined
                                    class="text-2xl text-blue-600"
                                />
                            </div>
                            <div>
                                <p class="text-sm text-gray-600">
                                    Total Quantity
                                </p>
                                <p class="text-2xl font-bold text-blue-600">
                                    {{ totalQuantity.toLocaleString() }}
                                </p>
                            </div>
                        </div>
                    </div>

                    <!-- Total Products -->
                    <div class="bg-gray-50 rounded-lg p-6 border">
                        <div class="flex items-center">
                            <div class="p-3 rounded-full bg-purple-100 mr-4">
                                <BoxPlotOutlined
                                    class="text-2xl text-purple-600"
                                />
                            </div>
                            <div>
                                <p class="text-sm text-gray-600">
                                    Total Products
                                </p>
                                <p class="text-2xl font-bold text-purple-600">
                                    {{ totalProducts.toLocaleString() }}
                                </p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Valuation Table -->
                <div class="mb-2">
                    <a-card>
                        <template #title>
                            <div class="flex items-center justify-between">
                                <span>Inventory Valuation Details</span>
                                <div class="text-sm text-gray-500">
                                    Generated on
                                    {{ new Date().toLocaleDateString() }}
                                </div>
                            </div>
                        </template>

                        <a-table
                            v-if="isMdUp"
                            :columns="tableColumns"
                            :data-source="items"
                            :pagination="{
                                pageSize: 50,
                                showSizeChanger: true,
                                showQuickJumper: true,
                                showTotal: (total, range) =>
                                    `${range[0]}-${range[1]} of ${total} items`,
                            }"
                            bordered
                        >
                            <template #bodyCell="{ column, record }">
                                <template v-if="column.key === 'total_value'">
                                    <span class="font-semibold text-green-600">
                                        ₱{{
                                            parseFloat(
                                                record.total_value
                                            ).toLocaleString("en-US", {
                                                minimumFractionDigits: 2,
                                            })
                                        }}
                                    </span>
                                </template>
                            </template>
                        </a-table>

                        <div v-else class="px-2 py-2 md:px-0">
                            <div
                                v-if="!items?.length"
                                class="py-12 text-center text-sm text-gray-500"
                            >
                                <BoxPlotOutlined
                                    class="mx-auto mb-4 text-4xl text-gray-400"
                                />
                                <p>No valuation items found</p>
                                <p class="text-xs text-gray-400">
                                    Try adjusting your filters or check back
                                    later
                                </p>
                            </div>
                            <div v-else class="flex flex-col gap-3">
                                <div
                                    v-for="(record, index) in items"
                                    :key="record.sku || index"
                                    class="overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm"
                                >
                                    <div
                                        class="flex flex-wrap items-start justify-between gap-2 px-4 py-3"
                                    >
                                        <div class="min-w-0">
                                            <p
                                                class="truncate text-sm font-semibold text-gray-900"
                                            >
                                                {{ record.product_name }}
                                            </p>
                                            <p class="text-xs text-gray-500">
                                                SKU: {{ record.sku || "N/A" }}
                                            </p>
                                        </div>
                                        <span
                                            class="text-base font-bold text-green-600"
                                        >
                                            {{ formatCurrency(record.total_value) }}
                                        </span>
                                    </div>

                                    <div
                                        class="mx-4 mb-3 rounded-lg bg-gray-50 p-3"
                                    >
                                        <div
                                            class="grid grid-cols-[auto_1fr] gap-x-3 gap-y-2 text-sm"
                                        >
                                            <span class="text-gray-500"
                                                >Quantity</span
                                            >
                                            <span
                                                class="text-right font-semibold text-gray-900"
                                            >
                                                {{
                                                    record.quantity_on_hand?.toLocaleString() ??
                                                    0
                                                }}
                                            </span>
                                            <span class="text-gray-500"
                                                >Avg cost</span
                                            >
                                            <span
                                                class="text-right font-medium text-gray-900"
                                            >
                                                {{
                                                    formatCurrency(
                                                        record.average_cost,
                                                    )
                                                }}
                                            </span>
                                            <span class="text-gray-500"
                                                >Last movement</span
                                            >
                                            <span
                                                class="text-right font-medium text-gray-900"
                                            >
                                                {{
                                                    formatLastMovement(
                                                        record.last_movement_at,
                                                    )
                                                }}
                                            </span>
                                            <template v-if="showSuperUserDomain">
                                                <span class="text-gray-500"
                                                    >Domain</span
                                                >
                                                <span
                                                    class="flex min-w-0 items-center justify-end gap-1 truncate font-medium text-gray-900"
                                                >
                                                    <IconWorld
                                                        size="16"
                                                        class="shrink-0"
                                                    />
                                                    {{ record.domain || "N/A" }}
                                                </span>
                                            </template>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </a-card>
                </div>

                <!-- Summary Footer -->
                <div class="mb-6 px-6">
                    <div
                        class="flex flex-col gap-2 md:flex-row md:items-center md:justify-between"
                    >
                        <div>
                            <h3 class="text-lg font-semibold text-gray-800">
                                Report Summary
                            </h3>
                            <p class="text-sm text-gray-600">
                                Total value of {{ totalProducts }} products
                                with {{ totalQuantity }} units in stock
                            </p>
                        </div>
                        <div class="md:text-right">
                            <p class="text-sm font-bold text-gray-600">
                                Grand Total
                            </p>
                            <p class="text-3xl font-bold text-green-600">
                                ₱{{
                                    totalValue.toLocaleString("en-US", {
                                        minimumFractionDigits: 2,
                                    })
                                }}
                            </p>
                        </div>
                    </div>
                </div>
            </template>
        </ContentLayout>
    </AuthenticatedLayout>
</template>
