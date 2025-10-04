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
} from "@ant-design/icons-vue";
import { useGlobalVariables } from "@/Composables/useGlobalVariable";
import { useFilters, toLabel } from "@/Composables/useFilters";
import { watchDebounced } from "@vueuse/core";
import { useHelpers } from "@/Composables/useHelpers";
import VueApexCharts from "vue3-apexcharts";

import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import ContentHeader from "@/Components/ContentHeader.vue";
import ContentLayout from "@/Components/ContentLayout.vue";
import RefreshButton from "@/Components/buttons/Refresh.vue";
import FilterDropdown from "@/Components/filters/FilterDropdown.vue";
import ActiveFilters from "@/Components/filters/ActiveFilters.vue";

const page = usePage();
const { spinning } = useGlobalVariables();
const { formattedTotal } = useHelpers();

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

const navigateToAdjustments = () =>
    router.visit(route("inventory.adjustments.index"));

const summaryCards = computed(() => [
    {
        title: "Total Products",
        value: summary.value.total_products,
        icon: BoxPlotOutlined,
        color: "blue",
    },
    {
        title: "In Stock",
        value: summary.value.in_stock_products,
        icon: ShoppingCartOutlined,
        color: "green",
    },
    {
        title: "Low Stock",
        value: summary.value.low_stock_products,
        icon: WarningOutlined,
        color: "orange",
    },
    {
        title: "Out of Stock",
        value: summary.value.out_of_stock_products,
        icon: StopOutlined,
        color: "red",
    },
]);

const quickActions = [
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
    },
    {
        title: "Stock Adjustments",
        desc: "Create and manage stock adjustments",
        color: "orange",
        icon: WarningOutlined,
        action: navigateToAdjustments,
    },
];

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
                height: 300,
                stacked: true,
                toolbar: { show: false },
            },
            plotOptions: {
                bar: { horizontal: false, columnWidth: "60%", borderRadius: 4 },
            },
            dataLabels: { enabled: false },
            xaxis: {
                categories: categories.map((c) => c.name),
                labels: { style: { fontSize: "12px" } },
            },
            yaxis: {
                title: { text: "Number of Products" },
                labels: { style: { fontSize: "11px" } },
            },
            legend: {
                position: "top",
                fontSize: "12px",
                markers: { width: 8, height: 8, radius: 4 },
            },
            colors: chartColors,
            grid: { borderColor: "#f1f1f1", strokeDashArray: 3 },
            responsive: [
                {
                    breakpoint: 768,
                    options: {
                        chart: { height: 250 },
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
        <ContentHeader title="Inventory Dashboard" />

        <ContentLayout title="Inventory Overview">
            <template #filters>
                <RefreshButton :loading="spinning" @click="getItems" />
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
                <div v-if="location.name" class="mb-2">
                    <a-alert
                        :message="`Viewing inventory for: ${location.name}`"
                        :description="location.address"
                        type="info"
                        show-icon
                        closable
                    />
                </div>
            </template>

            <template #table>
                <!-- Summary Cards -->
                <div
                    class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 p-6"
                >
                    <div
                        v-for="card in summaryCards"
                        :key="card.title"
                        class="bg-gray-50 border p-6 rounded-lg hover:shadow-lg transition-all relative overflow-hidden"
                        :class="`border-${card.color}-200`"
                    >
                        <div
                            class="absolute top-0 right-0 w-32 h-32 rounded-full"
                            :class="`bg-${card.color}-400/10`"
                        ></div>
                        <div class="relative flex items-center">
                            <div
                                :class="`p-3 rounded-full bg-${card.color}-100 mr-4`"
                            >
                                <component
                                    :is="card.icon"
                                    :class="`text-2xl text-${card.color}-600`"
                                />
                            </div>
                            <div>
                                <p
                                    :class="`text-sm text-${card.color}-600 font-medium`"
                                >
                                    {{ card.title }}
                                </p>
                                <p
                                    :class="`text-2xl font-bold text-${card.color}-800`"
                                >
                                    {{ card.value || 0 }}
                                </p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Inventory Value + Chart -->
                <div class="grid grid-cols-10 gap-4 px-6 py-2 rounded-lg">
                    <!-- Left -->
                    <div
                        class="col-span-3 flex flex-col border rounded-lg p-6 hover:shadow-lg"
                    >
                        <div>
                            <div class="flex items-center">
                                <div
                                    class="px-4 py-4 rounded-lg bg-indigo-100 mr-4"
                                >
                                    <BoxPlotOutlined
                                        class="text-3xl text-indigo-600"
                                    />
                                </div>
                                <div>
                                    <div
                                        class="text-3xl font-semibold text-gray-800"
                                    >
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
                                    {{
                                        formattedTotal(
                                            summary.total_inventory_value
                                        )
                                    }}
                                </p>
                            </div>

                            <div class="border rounded-lg p-6 mt-6">
                                <p
                                    class="text-md uppercase text-gray-600 font-bold"
                                >
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

                    <!-- Right -->
                    <div class="col-span-7 border rounded-lg p-6">
                        <div class="flex items-center justify-between mb-4">
                            <h4 class="text-md font-semibold text-gray-800">
                                Stock Level by Category
                            </h4>
                            <p class="text-sm text-gray-600">
                                Inventory distribution across categories
                            </p>
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

                        <apexchart
                            v-else
                            :width="chartLoaded ? '100%' : 500"
                            type="bar"
                            height="300"
                            :options="stockLevelChart.chartOptions"
                            :series="stockLevelChart.series"
                        />
                    </div>
                </div>

                <!-- Quick Actions -->
                <div
                    class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-6 px-6 pt-4"
                >
                    <div
                        v-for="action in quickActions"
                        :key="action.title"
                        class="bg-gray-50 border border-gray-200 p-6 rounded-lg hover:shadow-lg transition-all cursor-pointer"
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
                            <h3
                                class="text-lg font-semibold mb-2 text-gray-800"
                            >
                                {{ action.title }}
                            </h3>
                            <p class="text-gray-600 text-sm">
                                {{ action.desc }}
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Low Stock Alert -->
                <div v-if="lowStockProducts.length > 0" class="px-6">
                    <div
                        class="bg-white border border-orange-200 p-6 rounded-lg hover:shadow-lg"
                    >
                        <div class="flex items-center justify-between">
                            <div class="flex items-center">
                                <div
                                    class="p-2 rounded-full bg-orange-100 mr-3"
                                >
                                    <WarningOutlined
                                        class="text-orange-600 text-lg"
                                    />
                                </div>
                                <div>
                                    <h3
                                        class="text-lg font-semibold text-gray-800"
                                    >
                                        Low Stock Alert
                                    </h3>
                                    <p class="text-sm text-gray-600">
                                        ⚠️ Items running low — check restocking
                                        needs
                                    </p>
                                </div>
                            </div>
                            <div class="text-right">
                                <p class="text-2xl font-bold text-orange-600">
                                    {{ lowStockProducts.length }}
                                </p>
                                <p class="text-sm text-gray-600">
                                    items need attention
                                </p>
                                <a-button
                                    type="link"
                                    @click="navigateToProducts"
                                    class="text-orange-600 hover:text-orange-700 text-sm p-0"
                                >
                                    View All →
                                </a-button>
                            </div>
                        </div>
                    </div>
                </div>
            </template>
        </ContentLayout>
    </AuthenticatedLayout>
</template>

<style scoped>
.hover\:shadow-lg:hover {
    transform: translateY(-2px);
    box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.1),
        0 10px 10px -5px rgba(0, 0, 0, 0.04);
}
* {
    transition: all 0.3s ease;
}
</style>
