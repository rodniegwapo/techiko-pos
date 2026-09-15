<script setup>
import { ref, computed, onMounted } from "vue";
import { router, Head } from "@inertiajs/vue3";
import {
    ShoppingCartOutlined,
    WarningOutlined,
    StopOutlined,
    BoxPlotOutlined,
    HistoryOutlined,
} from "@ant-design/icons-vue";
import { useHelpers } from "@/Composables/useHelpers";
import { usePermissionsV2 } from "@/Composables/usePermissionV2";
import { useDomainRoutes } from "@/Composables/useDomainRoutes";
import { useMediaQuery } from "@vueuse/core";
import VueApexCharts from "vue3-apexcharts";

import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import ContentHeader from "@/Components/ContentHeader.vue";

const { formattedTotal } = useHelpers();
const { hasPermission } = usePermissionsV2();
const { getRoute } = useDomainRoutes();

const props = defineProps({
    report: Object,
    locations: Array,
});

// Computed Data
const summary = computed(() => props.report?.summary || {});
const location = computed(() => props.report?.location || {});
const lowStockProducts = computed(() => props.report?.low_stock_products || []);

// Stores are switched from the header's location badge, which sets ?location_id.
const locationId = computed(() => location.value.id || undefined);

// Navigation (organization routes, keeping the store shown here)
const navigateToProducts = (extra = {}) =>
    router.visit(getRoute("inventory.products"), {
        data: { location_id: locationId.value, ...extra },
    });

const navigateToLowStockProducts = () =>
    navigateToProducts({ stock_status: "low_stock" });

const navigateToMovements = () =>
    router.visit(getRoute("inventory.movements"), {
        data: { location_id: locationId.value },
    });

const navigateToAdjustments = () =>
    router.visit(getRoute("inventory.adjustments.index"));

const navigateToValuation = () =>
    router.visit(getRoute("inventory.valuation"), {
        data: { location_id: locationId.value },
    });

const canViewValuation = computed(() => hasPermission("inventory.valuation"));

const summaryCards = computed(() => [
    {
        title: "Total Products",
        value: summary.value.total_products || 0,
        icon: BoxPlotOutlined,
        color: "blue",
    },
    {
        title: "In Stock",
        value: summary.value.in_stock_products || 0,
        icon: ShoppingCartOutlined,
        color: "green",
    },
    {
        title: "Low Stock",
        value: summary.value.low_stock_products || 0,
        icon: WarningOutlined,
        color: "orange",
    },
    {
        title: "Out of Stock",
        value: summary.value.out_of_stock_products || 0,
        icon: StopOutlined,
        color: "red",
    },
]);

const quickActions = computed(() => {
    const actions = [];

    if (hasPermission("inventory.products")) {
        actions.push({
            title: "Manage Products",
            desc: "View and manage product inventory levels",
            color: "blue",
            icon: BoxPlotOutlined,
            action: () => navigateToProducts(),
        });
    }

    if (hasPermission("inventory.movements")) {
        actions.push({
            title: "Inventory Movements",
            desc: "Track all inventory transactions",
            color: "green",
            icon: HistoryOutlined,
            action: navigateToMovements,
        });
    }

    if (hasPermission("inventory.adjustments.index")) {
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
const isMdUp = useMediaQuery("(min-width: 768px)");
const chartHeight = computed(() => (isMdUp.value ? 350 : 280));
onMounted(() => setTimeout(() => (chartLoaded.value = true), 400));
</script>

<template>
    <Head title="Inventory Dashboard" />

    <AuthenticatedLayout>
        <div class="w-full min-w-0">
            <ContentHeader
                class="mb-4 md:mb-8"
                title="Inventory Dashboard"
                :isDashboard="true"
            >
            </ContentHeader>

            <!-- KPI Cards -->
            <div
                class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8"
            >
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
                            <p class="text-2xl font-bold text-gray-900">
                                {{ card.value }}
                            </p>
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
            <div class="flex w-full min-w-0 flex-col gap-6 pb-8 md:flex-row">
                <!-- Stock Level Chart -->
                <div
                    class="w-full min-w-0 rounded-lg border bg-white p-6 shadow-sm md:w-[60%]"
                >
                    <div
                        class="mb-4 flex flex-col gap-1 md:flex-row md:items-center md:justify-between"
                    >
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
                        :height="chartHeight"
                    />
                </div>

                <!-- Inventory Value + Location Info (Original Design) -->
                <div
                    class="flex w-full min-w-0 flex-col rounded-lg border bg-white p-6 transition-shadow hover:shadow-lg md:w-[40%]"
                >
                    <div>
                        <div class="flex items-center">
                            <div
                                class="mr-4 shrink-0 rounded-lg bg-indigo-100 px-4 py-4"
                            >
                                <BoxPlotOutlined
                                    class="text-3xl text-indigo-600"
                                />
                            </div>
                            <div class="min-w-0">
                                <div
                                    class="text-2xl font-semibold text-gray-800 md:text-3xl"
                                >
                                    {{ location.name || "All Locations" }}
                                </div>
                                <div class="break-words text-sm text-gray-600">
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
                            <p
                                class="text-3xl font-bold text-green-700 md:text-4xl"
                            >
                                {{
                                    formattedTotal(
                                        summary.total_inventory_value,
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
                        v-if="canViewValuation"
                        type="primary"
                        class="bg-purple-600 border-purple-600 hover:bg-purple-700 mt-4 w-full rounded-lg"
                        @click="navigateToValuation"
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
                            class="flex items-center justify-between gap-2 rounded-lg border border-orange-200 bg-orange-50 p-3"
                        >
                            <div class="min-w-0">
                                <p class="truncate font-medium text-gray-900">
                                    {{ product.name }}
                                </p>
                                <p class="text-sm text-gray-500">
                                    SKU: {{ product.SKU }}
                                </p>
                            </div>
                            <div class="shrink-0 text-right">
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
                            @click="navigateToLowStockProducts"
                            class="text-orange-600 hover:text-orange-700"
                        >
                            View All Low Stock Products →
                        </a-button>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
