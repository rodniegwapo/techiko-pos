<script setup>
import { ref, computed, onMounted } from "vue";
import { usePage, router, Head } from "@inertiajs/vue3";
import {
    ShoppingCartOutlined,
    WarningOutlined,
    StopOutlined,
    DollarOutlined,
    BoxPlotOutlined,
    HistoryOutlined,
    ArrowUpOutlined,
    ArrowDownOutlined,
} from "@ant-design/icons-vue";
import { useGlobalVariables } from "@/Composables/useGlobalVariable";
import { useFilters, toLabel } from "@/Composables/useFilters";
import { useHelpers } from "@/Composables/useHelpers";
import { usePermissionsV2 } from "@/Composables/usePermissionV2";
import VueApexCharts from "vue3-apexcharts";

import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import ContentHeader from "@/Components/ContentHeader.vue";
import RefreshButton from "@/Components/buttons/Refresh.vue";
import FilterDropdown from "@/Components/filters/FilterDropdown.vue";
import ActiveFilters from "@/Components/filters/ActiveFilters.vue";
import LocationInfoAlert from "@/Components/LocationInfoAlert.vue";

const page = usePage();
const { spinning } = useGlobalVariables();
const { formattedTotal } = useHelpers();

// Use permission composable
const isSuperUser = computed(() => usePage().props.auth?.user?.data?.is_super_user || false);

const selectedLocation = ref(null);

const props = defineProps({
    report: Object,
    locations: Array,
    currentLocation: Object,
    filters: Object,
});

// Initialize filters
onMounted(() => {
    selectedLocation.value =
        props.filters?.location_id || props.currentLocation?.id || null;
});

// Computed Data
const summary = computed(() => props.report?.summary || {});
const location = computed(() => props.report?.location || {});
const lowStockProducts = computed(() => props.report?.low_stock_products || []);

// Fetch Data
const getItems = () => {
    router.reload({
        only: ["report"],
        preserveScroll: true,
        data: { location_id: selectedLocation.value || undefined },
        onStart: () => (spinning.value = true),
        onFinish: () => (spinning.value = false),
    });
};

// Filter Options
const locationFilterOptions = computed(
    () =>
        props.locations?.map((loc) => ({
            label: loc.name,
            value: loc.id,
        })) || []
);

// Filters
const { filters, activeFilters, handleClearSelectedFilter } = useFilters({
    getItems,
    configs: [
        {
            label: "Location",
            key: "location_id",
            ref: selectedLocation,
            getLabel: toLabel(computed(() => locationFilterOptions.value)),
        },
    ],
});

const filtersConfig = [
    {
        key: "location_id",
        label: "Location",
        type: "select",
        options: locationFilterOptions.value,
    },
];

// Navigation
const navigateToProducts = () =>
    router.visit(route("inventory.products"), {
        data: { location_id: selectedLocation.value },
    });

const navigateToMovements = () =>
    router.visit(route("inventory.movements"), {
        data: { location_id: selectedLocation.value },
    });

const navigateToAdjustments = () => {
    if (usePermissionsV2('inventory.adjustments.store') || isSuperUser.value) {
        router.visit(route("inventory.adjustments.index"));
    }
};

const summaryCards = computed(() => [
    {
        title: "Total Products",
        value: summary.value.total_products || 0,
        icon: BoxPlotOutlined,
        color: "blue",
        change: 5, // Mock growth percentage
        trend: "up",
    },
    {
        title: "In Stock",
        value: summary.value.in_stock_products || 0,
        icon: ShoppingCartOutlined,
        color: "green",
        change: 12,
        trend: "up",
    },
    {
        title: "Low Stock",
        value: summary.value.low_stock_products || 0,
        icon: WarningOutlined,
        color: "orange",
        change: -3,
        trend: "down",
    },
    {
        title: "Out of Stock",
        value: summary.value.out_of_stock_products || 0,
        icon: StopOutlined,
        color: "red",
        change: -8,
        trend: "down",
    },
]);

const quickActions = computed(() => {
    const actions = [];
    
    if (usePermissionsV2('inventory.index') || isSuperUser.value) {
        actions.push(
            {
                title: "Manage Products",
                desc: "View and manage product inventory levels",
                color: "blue",
                icon: BoxPlotOutlined,
                action: navigateToProducts,
            },
            {
                title: "Inventory Movements",
                desc: "Track all inventory transactions",
                color: "green",
                icon: HistoryOutlined,
                action: navigateToMovements,
            }
        );
    }
    
    if (usePermissionsV2('inventory.adjustments.store') || isSuperUser.value) {
        actions.push({
            title: "Stock Adjustments",
            desc: "Create and manage stock adjustments",
            color: "orange",
            icon: WarningOutlined,
            action: navigateToAdjustments,
        });
    }
    
    return actions;
});

// Chart Setup
const chartColors = ["#10B981", "#F59E0B", "#EF4444"];
const stockLevelChart = computed(() => {
    const categories = props.report?.category_stock_data || [];
    const series = [
        { name: "In Stock", data: categories.map((c) => c.in_stock) },
        { name: "Low Stock", data: categories.map((c) => c.low_stock) },
        { name: "Out of Stock", data: categories.map((c) => c.out_of_stock) },
    ];
    return {
        series,
        chartOptions: {
            chart: {
                type: "bar",
                height: 350,
                stacked: true,
                toolbar: { show: false },
                zoom: { enabled: false },
                animations: {
                    enabled: true,
                    easing: "easeinout",
                    speed: 800,
                },
            },
            plotOptions: {
                bar: { horizontal: false, columnWidth: "60%", borderRadius: 4 },
            },
            dataLabels: { enabled: false },
            xaxis: {
                categories: categories.map((c) => c.name),
                labels: {
                    style: { colors: "#6B7280" },
                    rotate: categories.length > 6 ? -45 : 0,
                },
            },
            yaxis: {
                title: { text: "Number of Products" },
                labels: {
                    style: { colors: "#6B7280" },
                    formatter: (val) => val.toLocaleString(),
                },
            },
            legend: {
                position: "top",
                horizontalAlign: "right",
                fontSize: "12px",
                markers: { width: 8, height: 8, radius: 4 },
            },
            colors: chartColors,
            grid: {
                borderColor: "#F3F4F6",
                strokeDashArray: 4,
            },
            tooltip: {
                y: {
                    formatter: function (val) {
                        return val.toLocaleString() + " products";
                    },
                },
            },
            responsive: [
                {
                    breakpoint: 768,
                    options: {
                        chart: { height: 300 },
                        legend: { position: "bottom" },
                    },
                },
            ],
        },
    };
});

const apexchart = VueApexCharts;
const chartLoaded = ref(false);
onMounted(() => setTimeout(() => (chartLoaded.value = true), 400));
</script>

<template>
    <Head title="Inventory Dashboard" />

    <AuthenticatedLayout>
        <ContentHeader title="Inventory Dashboard" :isDashboard="true">
            <template #actions>
                <FilterDropdown
                    :filters="filtersConfig"
                    :selectedValues="{ location_id: selectedLocation }"
                    @update:selectedValues="
                        (values) => {
                            selectedLocation = values.location_id;
                            getItems();
                        }
                    "
                />
                <RefreshButton @click="getItems" />
            </template>
        </ContentHeader>

        <LocationInfoAlert />

        <!-- KPI Cards -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
            <div
                v-for="card in summaryCards"
                :key="card.title"
                class="bg-white rounded-lg border p-6 shadow-sm hover:shadow-md transition-shadow"
            >
                <div class="flex items-center justify-between">
                    <div class="flex-1">
                        <p class="text-sm font-medium text-gray-600 mb-1">
                            {{ card.title }}
                        </p>
                        <p class="text-2xl font-bold text-gray-900 mb-2">
                            {{ card.value }}
                        </p>
                        <div class="flex items-center">
                            <component
                                :is="
                                    card.trend === 'up'
                                        ? ArrowUpOutlined
                                        : ArrowDownOutlined
                                "
                                :class="
                                    card.trend === 'up'
                                        ? 'text-green-500'
                                        : card.trend === 'down'
                                        ? 'text-red-500'
                                        : 'text-gray-500'
                                "
                                class="w-4 h-4 mr-1"
                            />
                            <span
                                :class="
                                    card.trend === 'up'
                                        ? 'text-green-600'
                                        : card.trend === 'down'
                                        ? 'text-red-600'
                                        : 'text-gray-600'
                                "
                                class="text-sm font-medium"
                            >
                                {{ card.change > 0 ? "+" : ""
                                }}{{ card.change }}%
                            </span>
                            <span class="text-sm text-gray-500 ml-2"
                                >vs last month</span
                            >
                        </div>
                    </div>
                    <div
                        :class="`p-3 rounded-lg border ${
                            card.color === 'blue'
                                ? 'text-blue-600 bg-blue-50 border-blue-200'
                                : card.color === 'green'
                                ? 'text-green-600 bg-green-50 border-green-200'
                                : card.color === 'orange'
                                ? 'text-orange-600 bg-orange-50 border-orange-200'
                                : 'text-red-600 bg-red-50 border-red-200'
                        }`"
                    >
                        <component :is="card.icon" class="w-6 h-6" />
                    </div>
                </div>
            </div>
        </div>

        <!-- Charts and Analytics Section -->
        <div class="w-full flex gap-6 pb-8">
            <!-- Stock Level Chart -->
            <div class="bg-white rounded-lg border p-6 shadow-sm w-[60%]">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-semibold text-gray-900">
                        Stock Level by Category
                    </h3>
                    <span class="text-sm text-gray-500"
                        >Current inventory distribution</span
                    >
                </div>

                <div
                    v-if="!props.report?.category_stock_data?.length"
                    class="flex items-center justify-center h-80 text-gray-500"
                >
                    <div class="text-center">
                        <BoxPlotOutlined class="text-4xl mb-2" />
                        <p class="text-lg font-medium">
                            No Category Data Available
                        </p>
                        <p class="text-sm">
                            Add products with categories to see stock
                            distribution
                        </p>
                    </div>
                </div>

                <VueApexCharts
                    v-else
                    :options="stockLevelChart.chartOptions"
                    :series="stockLevelChart.series"
                    type="bar"
                    height="350"
                />
            </div>

            <!-- Inventory Value + Location Info (Original Design) -->
            <div
                class="flex flex-col border rounded-lg p-6 hover:shadow-lg bg-white transition-shadow w-[40%]"
            >
                <div>
                    <div class="flex items-center">
                        <div class="px-4 py-4 rounded-lg bg-indigo-100 mr-4">
                            <BoxPlotOutlined class="text-3xl text-indigo-600" />
                        </div>
                        <div>
                            <div class="text-3xl font-semibold text-gray-800">
                                {{ location.name || "All Locations" }}
                            </div>
                            <div class="text-sm text-gray-600">
                                {{
                                    location.type
                                        ? location.type
                                              .charAt(0)
                                              .toUpperCase() +
                                          location.type.slice(1)
                                        : ""
                                }}
                                {{
                                    location.address
                                        ? " • " + location.address
                                        : ""
                                }}
                            </div>
                        </div>
                    </div>

                    <div class="mt-8">
                        <p>Total Inventory Value</p>
                        <p class="text-4xl font-bold text-green-700">
                            {{ formattedTotal(summary.total_inventory_value) }}
                        </p>
                    </div>

                    <div class="border rounded-lg p-6 mt-6">
                        <p class="text-md uppercase text-gray-600 font-bold">
                            Location Code
                        </p>
                        <p class="font-semibold text-indigo-600 mt-4">
                            {{ location.code || "ALL" }}
                        </p>
                    </div>
                </div>

                <a-button
                    type="primary"
                    class="bg-purple-600 border-purple-600 hover:bg-purple-700 mt-4 w-full rounded-lg"
                    @click="router.visit(route('inventory.valuation'))"
                    size="large"
                >
                    View Report
                </a-button>
            </div>
        </div>

        <!-- Quick Actions -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
            <div
                v-for="action in quickActions"
                :key="action.title"
                class="bg-white rounded-lg border p-6 shadow-sm hover:shadow-md transition-shadow cursor-pointer"
                @click="action.action"
            >
                <div class="text-center">
                    <div
                        :class="`w-16 h-16 bg-${action.color}-100 rounded-xl flex items-center justify-center mx-auto mb-4`"
                    >
                        <component
                            :is="action.icon"
                            :class="`text-2xl text-${action.color}-600`"
                        />
                    </div>
                    <h3 class="text-lg font-semibold mb-2 text-gray-900">
                        {{ action.title }}
                    </h3>
                    <p class="text-gray-600 text-sm">{{ action.desc }}</p>
                </div>
            </div>
        </div>

        <!-- Low Stock Alert -->
        <div
            v-if="lowStockProducts.length > 0"
            class="bg-white rounded-lg border shadow-sm"
        >
            <div class="p-6 border-b border-gray-200">
                <div class="flex items-center">
                    <div
                        class="p-2 rounded-lg border border-orange-200 bg-orange-50 mr-3"
                    >
                        <WarningOutlined class="w-5 h-5 text-orange-600" />
                    </div>
                    <div>
                        <h3 class="text-lg font-semibold text-gray-900">
                            Low Stock Alert
                        </h3>
                        <p class="text-sm text-gray-500">
                            {{ lowStockProducts.length }} products need
                            attention
                        </p>
                    </div>
                </div>
            </div>

            <div class="p-6">
                <div
                    class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4"
                >
                    <div
                        v-for="product in lowStockProducts.slice(0, 6)"
                        :key="product.id"
                        class="flex items-center justify-between p-3 bg-orange-50 rounded-lg border border-orange-200"
                    >
                        <div>
                            <p class="font-medium text-gray-900">
                                {{ product.name }}
                            </p>
                            <p class="text-sm text-gray-500">
                                SKU: {{ product.SKU }}
                            </p>
                        </div>
                        <div class="text-right">
                            <p class="text-sm font-medium text-orange-600">
                                {{ product.current_stock }} left
                            </p>
                            <p class="text-xs text-gray-500">
                                Min: {{ product.min_stock_level }}
                            </p>
                        </div>
                    </div>
                </div>

                <div class="mt-4 text-center">
                    <a-button
                        type="link"
                        @click="navigateToProducts"
                        class="text-orange-600 hover:text-orange-700"
                    >
                        View All Low Stock Products →
                    </a-button>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
