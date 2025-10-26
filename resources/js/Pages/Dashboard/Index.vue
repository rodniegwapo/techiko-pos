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
import InventoryAlerts from "./components/InventoryAlerts.vue";
import LocationInfoAlert from "@/Components/LocationInfoAlert.vue";

const {
    selectedLocation,
    filtersConfig,
    summaryCards,
    topProducts,
    inventoryAlerts,
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

        <LocationInfoAlert />

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

        <InventoryAlerts :alerts="inventoryAlerts" />
    </AuthenticatedLayout>
</template>
