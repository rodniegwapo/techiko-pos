<script setup>
import { Head } from "@inertiajs/vue3";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import ContentHeader from "@/Components/ContentHeader.vue";
import RefreshButton from "@/Components/buttons/Refresh.vue";
import FilterDropdown from "@/Components/filters/FilterDropdown.vue";

import { useDashboardData } from "@/Composables/useDashboardData.js";

import SummaryCards from "./components/SummaryCards.vue";
import SalesOverview from "./components/SalesOverview.vue";
import TopProducts from "./components/TopProducts.vue";
import CombinedInventoryAlerts from "./components/CombinedInventoryAlerts.vue";
import PerformanceCard from "./components/PerformanceCard.vue";

const {
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
} = useDashboardData();
</script>

<template>
    <Head title="Dashboard" />

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

        <SummaryCards :cards="summaryCards" />

        <div class="flex w-full gap-6 mb-8">
            <SalesOverview
                class="w-[60%]"
                :options="salesChartOptions"
                :series="salesChartSeries"
                v-model:graphFilter="graphFilter"
            />
            <TopProducts class="w-[40%]" :products="topProducts" />
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
            <CombinedInventoryAlerts :alerts="inventoryAlerts" />
            <PerformanceCard 
                :storePerformance="storePerformance" 
                :topUsers="topUsers" 
            />
        </div>
    </AuthenticatedLayout>
</template>
