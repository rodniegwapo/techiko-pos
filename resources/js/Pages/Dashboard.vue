<script setup>
import { ref, computed, onMounted } from "vue";
import { usePage, router, Head } from "@inertiajs/vue3";
import { useGlobalVariables } from "@/Composables/useGlobalVariable";
import { useHelpers } from "@/Composables/useHelpers";
import { useFilters, toLabel } from "@/Composables/useFilters";
import {
    DollarOutlined,
    ShoppingCartOutlined,
    LineChartOutlined,
    UserOutlined,
    BoxPlotOutlined,
    WarningOutlined,
    StopOutlined,
    HistoryOutlined,
    ArrowUpOutlined,
    ArrowDownOutlined,
} from "@ant-design/icons-vue";
import VueApexCharts from "vue3-apexcharts";

import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import ContentHeader from "@/Components/ContentHeader.vue";
import ContentLayout from "@/Components/ContentLayout.vue";
import RefreshButton from "@/Components/buttons/Refresh.vue";
import FilterDropdown from "@/Components/filters/FilterDropdown.vue";
import ActiveFilters from "@/Components/filters/ActiveFilters.vue";

const page = usePage();
const { spinning } = useGlobalVariables();
const { formatCurrency, formatDateTime } = useHelpers();

const selectedLocation = ref(null);

const props = defineProps({
    stats: Object,
    locations: Array,
    currentLocation: Object,
    filters: Object,
});

// Initialize filters
onMounted(() => {
    selectedLocation.value =
        props.filters?.location_id || props.currentLocation?.id || null;
});

// Fetch Data
const getItems = () => {
    router.reload({
        only: ["stats"],
        preserveScroll: true,
        data: { location_id: selectedLocation.value || undefined },
        onStart: () => (spinning.value = true),
        onFinish: () => (spinning.value = false),
    });
};

// Filter options
const locationFilterOptions = computed(
    () =>
        props.locations?.map((location) => ({
            label: location.name,
            value: location.id,
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

// KPI Cards Data
const summaryCards = computed(() => [
    {
        title: "Today's Sales",
        value: formatCurrency(props.stats?.kpis?.today_sales?.value || 0),
        icon: DollarOutlined,
        color: "green",
        change: props.stats?.kpis?.today_sales?.growth || 0,
        trend:
            (props.stats?.kpis?.today_sales?.growth || 0) >= 0 ? "up" : "down",
    },
    {
        title: "This Week Revenue",
        value: formatCurrency(props.stats?.kpis?.total_revenue?.value || 0),
        icon: LineChartOutlined,
        color: "blue",
        change: props.stats?.kpis?.total_revenue?.growth || 0,
        trend:
            (props.stats?.kpis?.total_revenue?.growth || 0) >= 0
                ? "up"
                : "down",
    },
    {
        title: "Pending Orders",
        value: props.stats?.kpis?.active_orders?.value || 0,
        icon: ShoppingCartOutlined,
        color: "orange",
        change: props.stats?.kpis?.active_orders?.growth || 0,
        trend: "neutral",
    },
    {
        title: "Inventory Value",
        value: formatCurrency(props.stats?.kpis?.inventory_value?.value || 0),
        icon: BoxPlotOutlined,
        color: "purple",
        change: props.stats?.kpis?.inventory_value?.growth || 0,
        trend:
            (props.stats?.kpis?.inventory_value?.growth || 0) >= 0
                ? "up"
                : "down",
    },
]);

// Chart Data
const salesChartOptions = computed(() => ({
    chart: {
        type: "line",
        height: 300,
        toolbar: { show: false },
        zoom: { enabled: false },
    },
    colors: ["#3B82F6", "#10B981"],
    stroke: {
        curve: "smooth",
        width: 3,
    },
    fill: {
        type: "gradient",
        gradient: {
            shadeIntensity: 1,
            opacityFrom: 0.7,
            opacityTo: 0.1,
            stops: [0, 90, 100],
        },
    },
    xaxis: {
        categories:
            props.stats?.sales_analytics?.daily_sales?.map((day) => day.date) ||
            [],
        labels: { style: { colors: "#6B7280" } },
    },
    yaxis: {
        labels: {
            formatter: function (val) {
                return "₱" + val.toLocaleString();
            },
            style: { colors: "#6B7280" },
        },
    },
    grid: {
        borderColor: "#F3F4F6",
        strokeDashArray: 4,
    },
    tooltip: {
        y: {
            formatter: function (val) {
                return "₱" + val.toLocaleString();
            },
        },
    },
    legend: {
        position: "top",
        horizontalAlign: "right",
    },
}));

const salesChartSeries = computed(() => [
    {
        name: "Sales",
        data:
            props.stats?.sales_analytics?.daily_sales?.map(
                (day) => day.sales
            ) || [],
    },
    {
        name: "Transactions",
        data:
            props.stats?.sales_analytics?.daily_sales?.map(
                (day) => day.transactions
            ) || [],
    },
]);

// Top Products Data
const topProducts = computed(() => {
    if (!props.stats?.top_products) return [];

    return props.stats.top_products.slice(0, 5).map((item, index) => ({
        name: item.product?.name || "Unknown Product",
        sales: item.quantity_sold || 0,
        revenue: formatCurrency(
            (item.quantity_sold || 0) * (item.product?.price || 0)
        ),
        trend: index % 2 === 0 ? "up" : "down",
    }));
});

// Recent Transactions Data
const recentTransactions = computed(() => {
    if (!props.stats?.recent_transactions) return [];

    return props.stats.recent_transactions.slice(0, 10).map((transaction) => ({
        id: transaction.id,
        customer: transaction.customer_name || "Walk-in Customer",
        amount: formatCurrency(transaction.total || 0),
        method: transaction.payment_method?.toLowerCase() || "cash",
        status: transaction.status === "paid" ? "completed" : "pending",
        time: formatDateTime(transaction.created_at),
        items_count: transaction.items_count || 0,
    }));
});

// Inventory Alerts Data
const inventoryAlerts = computed(() => {
    if (!props.stats?.inventory_alerts)
        return { low_stock: [], out_of_stock: [] };

    return {
        low_stock:
            props.stats.inventory_alerts.low_stock_products?.slice(0, 5) || [],
        out_of_stock:
            props.stats.inventory_alerts.out_of_stock_products?.slice(0, 5) ||
            [],
    };
});

const graphFilter = ref("weekly");
</script>

<template>
    <Head title="POS Dashboard" />

    <AuthenticatedLayout>
        <ContentHeader title="Dashboard" :isDashboard="true">
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
                                >vs yesterday</span
                            >
                        </div>
                    </div>
                    <div
                        :class="`p-3 rounded-lg border ${
                            card.color === 'green'
                                ? 'text-green-600 bg-green-50 border-green-200'
                                : card.color === 'blue'
                                ? 'text-blue-600 bg-blue-50 border-blue-200'
                                : card.color === 'orange'
                                ? 'text-orange-600 bg-orange-50 border-orange-200'
                                : 'text-purple-600 bg-purple-50 border-purple-200'
                        }`"
                    >
                        <component :is="card.icon" class="w-6 h-6" />
                    </div>
                </div>
            </div>
        </div>

        <div class="flex w-full gap-6 mb-8">
            <!-- Sales Overview Chart (70%) -->
            <div class="bg-white rounded-lg border p-6 shadow-sm w-[60%]">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-semibold text-gray-900">
                        Sales Overview
                    </h3>
                    <a-radio-group
                        v-model:value="graphFilter"
                        button-style="solid"
                    >
                        <a-radio-button value="weekly">Weekly</a-radio-button>
                        <a-radio-button value="monthly">Monthly</a-radio-button>
                        <a-radio-button value="yearly">Yearly</a-radio-button>
                    </a-radio-group>
                </div>
                <VueApexCharts
                    :options="salesChartOptions"
                    :series="salesChartSeries"
                    type="line"
                    height="300"
                />
            </div>

            <!-- Top Products (30%) -->
            <div class="bg-white rounded-lg border p-6 shadow-sm w-[40%]">
                <div class="flex items-center justify-between mb-3">
                    <h3 class="text-lg font-semibold text-gray-900">
                        Top Products
                    </h3>
                    <span class="text-sm text-gray-500">This week</span>
                </div>

                <div class="space-y-2">
                    <div
                        v-for="(product, index) in topProducts"
                        :key="product.name"
                        class="flex items-center justify-between px-3 py-1 border bg-gray-50 rounded-lg"
                    >
                        <div class="flex items-center">
                            <div
                                class="bg-blue-100 rounded-full flex items-center justify-center mr-3"
                            >
                                <span class="text-sm font-bold text-blue-600">
                                    #{{ index + 1 }}
                                </span>
                            </div>
                            <div>
                                <div class="font-medium text-gray-900">
                                    {{ product.name }}
                                </div>
                                <div class="text-sm text-gray-500">
                                    {{ product.sales }} sales
                                </div>
                            </div>
                        </div>
                        <div class="text-right">
                            <div class="text-sm text-gray-900 py-2">
                                {{ product.revenue }}
                            </div>
                            <div class="text-xs text-gray-500">revenue</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Inventory Alerts -->

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
            <!-- Low Stock Alert -->
            <div class="bg-white rounded-lg border p-6 shadow-sm">
                <div class="flex items-center justify-between mb-4">
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
                                {{ inventoryAlerts.low_stock.length }}
                                products need attention
                            </p>
                        </div>
                    </div>
                </div>

                <div
                    v-if="inventoryAlerts.low_stock.length > 0"
                    class="space-y-2"
                >
                    <div
                        v-for="product in inventoryAlerts.low_stock"
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
                <div v-else class="text-center py-4">
                    <p class="text-gray-500">No low stock products</p>
                </div>
            </div>

            <!-- Out of Stock Alert -->
            <div class="bg-white rounded-lg border p-6 shadow-sm">
                <div class="flex items-center justify-between mb-4">
                    <div class="flex items-center">
                        <div
                            class="p-2 rounded-lg border border-red-200 bg-red-50 mr-3"
                        >
                            <StopOutlined class="w-5 h-5 text-red-600" />
                        </div>
                        <div>
                            <h3 class="text-lg font-semibold text-gray-900">
                                Out of Stock
                            </h3>
                            <p class="text-sm text-gray-500">
                                {{ inventoryAlerts.out_of_stock.length }}
                                products unavailable
                            </p>
                        </div>
                    </div>
                </div>

                <div
                    v-if="inventoryAlerts.out_of_stock.length > 0"
                    class="space-y-2"
                >
                    <div
                        v-for="product in inventoryAlerts.out_of_stock"
                        :key="product.id"
                        class="flex items-center justify-between p-3 bg-red-50 rounded-lg border border-red-200"
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
                            <p class="text-sm font-medium text-red-600">
                                0 in stock
                            </p>
                            <p class="text-xs text-gray-500">Restock needed</p>
                        </div>
                    </div>
                </div>
                <div v-else class="text-center py-4">
                    <p class="text-gray-500">All products in stock</p>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
