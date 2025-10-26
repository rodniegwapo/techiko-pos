import { ref, computed, watch } from "vue";
import { router } from "@inertiajs/vue3";
import { useDomainRoutes } from "@/Composables/useDomainRoutes.js";

export function useSalesChartData(graphFilter, selectedLocation) {
    const { getRoute } = useDomainRoutes();
    const isLoading = ref(false);
    const chartData = ref({
        categories: [],
        salesData: [],
        transactionsData: [],
    });

    const generateFallbackData = () => {
        const now = new Date();

        switch (graphFilter.value) {
            case "daily":
                return generateWeeklyData(now);
            case "weekly":
                return generateMonthlyData(now);
            case "monthly":
                return generateYearlyData(now);
            default:
                return generateWeeklyData(now);
        }
    };

    const fetchChartData = async () => {
        isLoading.value = true;

        try {
            // Use domain-aware routing for API calls
            const response = await window.axios.post(
                getRoute('dashboard.sales-chart'),
                {
                    time_range: graphFilter.value,
                    location_id: selectedLocation?.value || null,
                }
            );

            if (response.data) {
                chartData.value = response.data;
            } else {
                // Fallback to generated data if API fails
                chartData.value = generateFallbackData();
            }
        } catch (error) {
            console.warn("Failed to fetch chart data, using fallback:", error);
            chartData.value = generateFallbackData();
        } finally {
            isLoading.value = false;
        }
    };

    // Watch for changes in filter or location to fetch new data
    watch(
        [graphFilter, selectedLocation],
        () => {
            fetchChartData();
        },
        { immediate: true }
    );

    // Weekly view: Monday to Sunday of current week
    const generateWeeklyData = (date) => {
        const startOfWeek = new Date(date);
        const day = startOfWeek.getDay();
        const diff = startOfWeek.getDate() - day + (day === 0 ? -6 : 1);
        startOfWeek.setDate(diff);

        const days = ["Mon", "Tue", "Wed", "Thu", "Fri", "Sat", "Sun"];
        const categories = [];
        const salesData = [];
        const transactionsData = [];

        for (let i = 0; i < 7; i++) {
            const currentDay = new Date(startOfWeek);
            currentDay.setDate(startOfWeek.getDate() + i);

            categories.push(days[i]);

            // Generate realistic sales data
            const daySales = generateDaySales(currentDay);
            const dayTransactions = generateDayTransactions(currentDay);

            salesData.push(daySales);
            transactionsData.push(dayTransactions);
        }

        return { categories, salesData, transactionsData };
    };

    // Monthly view: Weeks within the selected month
    const generateMonthlyData = (date) => {
        const year = date.getFullYear();
        const month = date.getMonth();
        const firstDay = new Date(year, month, 1);
        const lastDay = new Date(year, month + 1, 0);

        const weeks = [];
        const salesData = [];
        const transactionsData = [];

        let currentWeek = 1;
        let currentDate = new Date(firstDay);

        while (currentDate <= lastDay) {
            const weekStart = new Date(currentDate);
            const weekEnd = new Date(currentDate);
            weekEnd.setDate(weekEnd.getDate() + 6);

            if (weekEnd > lastDay) {
                weekEnd.setTime(lastDay.getTime());
            }

            weeks.push(`Week ${currentWeek}`);

            const weekSales = generateWeekSales(weekStart, weekEnd);
            const weekTransactions = generateWeekTransactions(
                weekStart,
                weekEnd
            );

            salesData.push(weekSales);
            transactionsData.push(weekTransactions);

            currentDate.setDate(currentDate.getDate() + 7);
            currentWeek++;
        }

        return { categories: weeks, salesData, transactionsData };
    };

    // Yearly view: Months of the current year
    const generateYearlyData = (date) => {
        const year = date.getFullYear();
        const months = [
            "Jan",
            "Feb",
            "Mar",
            "Apr",
            "May",
            "Jun",
            "Jul",
            "Aug",
            "Sep",
            "Oct",
            "Nov",
            "Dec",
        ];
        const salesData = [];
        const transactionsData = [];

        for (let month = 0; month < 12; month++) {
            const monthStart = new Date(year, month, 1);
            const monthEnd = new Date(year, month + 1, 0);

            const monthSales = generateMonthSales(monthStart, monthEnd);
            const monthTransactions = generateMonthTransactions(
                monthStart,
                monthEnd
            );

            salesData.push(monthSales);
            transactionsData.push(monthTransactions);
        }

        return { categories: months, salesData, transactionsData };
    };

    // Data generation functions with realistic patterns
    const generateDaySales = (date) => {
        const baseSales = 5000 + Math.random() * 3000;
        const dayOfWeek = date.getDay();
        const weekendMultiplier =
            dayOfWeek === 0 || dayOfWeek === 6 ? 0.7 : 1.2;
        const seasonalMultiplier = getSeasonalMultiplier(date);
        return Math.round(baseSales * weekendMultiplier * seasonalMultiplier);
    };

    const generateDayTransactions = (date) => {
        const baseTransactions = 20 + Math.random() * 30;
        const dayOfWeek = date.getDay();
        const weekendMultiplier =
            dayOfWeek === 0 || dayOfWeek === 6 ? 0.6 : 1.1;
        return Math.round(baseTransactions * weekendMultiplier);
    };

    const generateWeekSales = (startDate, endDate) => {
        let totalSales = 0;
        const currentDate = new Date(startDate);

        while (currentDate <= endDate) {
            totalSales += generateDaySales(currentDate);
            currentDate.setDate(currentDate.getDate() + 1);
        }

        return totalSales;
    };

    const generateWeekTransactions = (startDate, endDate) => {
        let totalTransactions = 0;
        const currentDate = new Date(startDate);

        while (currentDate <= endDate) {
            totalTransactions += generateDayTransactions(currentDate);
            currentDate.setDate(currentDate.getDate() + 1);
        }

        return totalTransactions;
    };

    const generateMonthSales = (startDate, endDate) => {
        let totalSales = 0;
        const currentDate = new Date(startDate);

        while (currentDate <= endDate) {
            totalSales += generateDaySales(currentDate);
            currentDate.setDate(currentDate.getDate() + 1);
        }

        return totalSales;
    };

    const generateMonthTransactions = (startDate, endDate) => {
        let totalTransactions = 0;
        const currentDate = new Date(startDate);

        while (currentDate <= endDate) {
            totalTransactions += generateDayTransactions(currentDate);
            currentDate.setDate(currentDate.getDate() + 1);
        }

        return totalTransactions;
    };

    const getSeasonalMultiplier = (date) => {
        const month = date.getMonth();
        // Simulate seasonal patterns
        const seasonalPatterns = [
            0.8, 0.9, 1.1, 1.2, 1.3, 1.1, 0.9, 0.8, 1.0, 1.1, 1.2, 1.4,
        ];
        return seasonalPatterns[month] || 1.0;
    };

    const getChartTitle = () => {
        const now = new Date();
        switch (graphFilter.value) {
            case "daily":
                return `Weekly Sales Overview (${now.toLocaleDateString(
                    "en-US",
                    { month: "short", day: "numeric" }
                )} - ${new Date(
                    now.getTime() + 6 * 24 * 60 * 60 * 1000
                ).toLocaleDateString("en-US", {
                    month: "short",
                    day: "numeric",
                })})`;
            case "weekly":
                return `Monthly Sales Overview (${now.toLocaleDateString(
                    "en-US",
                    { month: "long", year: "numeric" }
                )})`;
            case "monthly":
                return `Yearly Sales Overview (${now.getFullYear()})`;
            default:
                return "Sales Overview";
        }
    };

    const chartOptions = computed(() => ({
        chart: {
            type: "line",
            height: 350,
            toolbar: { show: false },
            zoom: { enabled: false },
            animations: {
                enabled: true,
                easing: "easeinout",
                speed: 800,
            },
        },
        colors: ["#3B82F6", "#10B981"],
        stroke: {
            curve: "smooth",
            width: 4,
        },
        xaxis: {
            categories: chartData.value.categories,
            labels: {
                style: { colors: "#6B7280" },
                rotate: graphFilter.value === "monthly" ? -45 : 0,
            },
        },
        yaxis: [
            {
                seriesName: "Sales",
                labels: {
                    formatter: (val) => "₱" + val.toLocaleString(),
                    style: { colors: "#3B82F6" },
                },
                title: {
                    text: "Sales (₱)",
                    style: { color: "#3B82F6" },
                },
            },
            {
                seriesName: "Transactions",
                labels: {
                    formatter: (val) => val.toLocaleString(),
                    style: { colors: "#10B981" },
                },
                title: {
                    text: "Transactions",
                    style: { color: "#10B981" },
                },
            },
        ],
        grid: {
            borderColor: "#F3F4F6",
            strokeDashArray: 20,
        },
        tooltip: {
            y: [
                {
                    formatter: function (val) {
                        return "₱" + val.toLocaleString();
                    },
                },
                {
                    formatter: function (val) {
                        return val.toLocaleString() + " transactions";
                    },
                },
            ],
        },
        legend: {
            position: "top",
            horizontalAlign: "right",
        },
        title: {
            text: getChartTitle(),
            align: "left",
            style: {
                fontSize: "16px",
                fontWeight: "600",
                color: "#374151",
            },
        },
    }));

    const chartSeries = computed(() => [
        {
            name: "Sales",
            data: chartData.value.salesData,
            yAxis: 0, // Use first Y-axis (left side with peso sign)
        },
        {
            name: "Transactions",
            data: chartData.value.transactionsData,
            yAxis: 1, // Use second Y-axis (right side with count)
        },
    ]);

    return {
        isLoading,
        chartOptions,
        chartSeries,
        fetchChartData,
    };
}
