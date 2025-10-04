<script setup>
import { computed } from "vue";
import VueApexCharts from "vue3-apexcharts";

const props = defineProps({
    options: Object,
    series: Array,
});

const graphFilter = defineModel("graphFilter");

// Dynamic time range labels
const timeRangeLabels = computed(() => {
    switch (graphFilter.value) {
        case "daily":
            return {
                title: "Weekly View",
                subtitle: "Monday to Sunday of current week",
                buttonLabels: ["Daily", "Weekly", "Monthly"],
            };
        case "weekly":
            return {
                title: "Monthly View",
                subtitle: "Weeks within current month",
                buttonLabels: ["Daily", "Weekly", "Monthly"],
            };
        case "monthly":
            return {
                title: "Yearly View",
                subtitle: "Months of current year",
                buttonLabels: ["Daily", "Weekly", "Monthly"],
            };
        default:
            return {
                title: "Sales Overview",
                subtitle: "Select time range",
                buttonLabels: ["Daily", "Weekly", "Monthly"],
            };
    }
});
</script>

<template>
    <div class="bg-white rounded-lg border p-6 shadow-sm">
        <div class="flex items-center justify-between mb-4">
            <div>
                <h3 class="text-lg font-semibold text-gray-900">
                    {{ timeRangeLabels.title }}
                </h3>
                <p class="text-sm text-gray-500">
                    {{ timeRangeLabels.subtitle }}
                </p>
            </div>
            <a-radio-group v-model:value="graphFilter" button-style="solid">
                <a-radio-button
                    v-for="(label, index) in timeRangeLabels.buttonLabels"
                    :key="index"
                    :value="['daily', 'weekly', 'monthly'][index]"
                >
                    {{ label }}
                </a-radio-button>
            </a-radio-group>
        </div>

        <!-- Chart Container -->
        <div class="relative">
            <VueApexCharts
                :options="options"
                :series="series"
                type="line"
                height="400"
            />

            <!-- Loading indicator for dynamic updates -->
            <div
                v-if="!series || !series.length || !series[0]?.data?.length"
                class="absolute inset-0 flex items-center justify-center bg-white bg-opacity-75"
            >
                <a-spin size="large" />
            </div>
        </div>

        <!-- Chart Info -->
        <div
            class="mt-4 flex items-center justify-between text-sm text-gray-500"
        >
            <div class="flex items-center space-x-4">
                <div class="flex items-center">
                    <div class="w-3 h-3 bg-blue-500 rounded-full mr-2"></div>
                    <span>Sales Revenue (₱)</span>
                </div>
                <div class="flex items-center">
                    <div class="w-3 h-3 bg-green-500 rounded-full mr-2"></div>
                    <span>Transaction Count</span>
                </div>
            </div>
            <div class="text-xs">
                Data updates automatically when time range changes
            </div>
        </div>
    </div>
</template>
