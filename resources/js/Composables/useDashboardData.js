import { ref, computed, onMounted } from "vue";
import { usePage, router } from "@inertiajs/vue3";
import { useGlobalVariables } from "@/Composables/useGlobalVariable";
import { useHelpers } from "@/Composables/useHelpers";
import { useFilters, toLabel } from "@/Composables/useFilters";
import { useSalesChartData } from "@/Composables/useSalesChartData";
import {
    DollarOutlined,
    LineChartOutlined,
    ShoppingCartOutlined,
    BoxPlotOutlined,
    ArrowUpOutlined,
    ArrowDownOutlined,
} from "@ant-design/icons-vue";

export function useDashboardData() {
    const page = usePage();
    const { spinning } = useGlobalVariables();
    const { formatCurrency, formatDateTime } = useHelpers();

    const props = page.props;
    const selectedLocation = ref(null);
    const graphFilter = ref("daily");

    onMounted(() => {
        selectedLocation.value =
            props.filters?.location_id || props.currentLocation?.id || null;
    });

    const getItems = () => {
        router.reload({
            only: ["stats"],
            preserveScroll: true,
            data: { location_id: selectedLocation.value || undefined },
            onStart: () => (spinning.value = true),
            onFinish: () => (spinning.value = false),
        });
    };

    // Locations
    const locationFilterOptions = computed(
        () =>
            props.locations?.map((loc) => ({
                label: loc.name,
                value: loc.id,
            })) || []
    );

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

    const summaryCards = computed(() => [
        {
            title: "Today's Sales",
            value: formatCurrency(props.stats?.kpis?.today_sales?.value || 0),
            icon: DollarOutlined,
            color: "green",
            change: props.stats?.kpis?.today_sales?.growth || 0,
            trend:
                (props.stats?.kpis?.today_sales?.growth || 0) >= 0
                    ? "up"
                    : "down",
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
            value: formatCurrency(
                props.stats?.kpis?.inventory_value?.value || 0
            ),
            icon: BoxPlotOutlined,
            color: "purple",
            change: props.stats?.kpis?.inventory_value?.growth || 0,
            trend:
                (props.stats?.kpis?.inventory_value?.growth || 0) >= 0
                    ? "up"
                    : "down",
        },
    ]);

    // Use the dedicated sales chart data composable
    const { chartOptions: salesChartOptions, chartSeries: salesChartSeries } =
        useSalesChartData(graphFilter, selectedLocation);

    const topProducts = computed(() =>
        (props.stats?.top_products || []).slice(0, 5).map((p, i) => ({
            name: p.product?.name || "Unknown",
            sales: p.quantity_sold || 0,
            revenue: formatCurrency(
                (p.quantity_sold || 0) * (p.product?.price || 0)
            ),
            trend: i % 2 === 0 ? "up" : "down",
        }))
    );

    const inventoryAlerts = computed(() => ({
        low_stock:
            props.stats?.inventory_alerts?.low_stock_products?.slice(0, 5) ||
            [],
        out_of_stock:
            props.stats?.inventory_alerts?.out_of_stock_products?.slice(0, 5) ||
            [],
    }));

    const storePerformance = computed(() => {
        const data = props.stats?.store_performance || {};
        return {
            locations: data.locations?.slice(0, 5) || [],
            totalSales: data.total_sales || 0,
            totalTransactions: data.total_transactions || 0,
            totalLocations: data.total_locations || 0,
        };
    });

    const topUsers = computed(() => 
        props.stats?.top_users?.slice(0, 5) || []
    );

    return {
        selectedLocation,
        filtersConfig,
        summaryCards,
        topProducts,
        inventoryAlerts,
        storePerformance,
        topUsers,
        graphFilter,
        salesChartOptions,
        salesChartSeries,
        getItems,
    };
}
