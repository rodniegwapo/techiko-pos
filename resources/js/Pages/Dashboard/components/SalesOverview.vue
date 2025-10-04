<script setup>
import VueApexCharts from "vue3-apexcharts";

defineProps({
    options: Object,
    series: Array,
});
const graphFilter = defineModel("graphFilter");
</script>

<template>
    <div class="bg-white rounded-lg border p-6 shadow-sm">
        <div class="flex items-center justify-between mb-4">
            <h3 class="text-lg font-semibold text-gray-900">Sales Overview</h3>
            <a-radio-group v-model:value="graphFilter" button-style="solid">
                <a-radio-button value="weekly">Weekly</a-radio-button>
                <a-radio-button value="monthly">Monthly</a-radio-button>
                <a-radio-button value="yearly">Yearly</a-radio-button>
            </a-radio-group>
        </div>
        <VueApexCharts
            :options="options"
            :series="series"
            type="line"
            height="300"
        />
    </div>
</template>
