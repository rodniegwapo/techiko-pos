<template>
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
        <!-- Low Stock -->
        <div class="bg-white rounded-lg border p-6 shadow-sm">
            <div class="flex items-center mb-4">
                <div
                    class="p-2 rounded-lg border border-orange-200 bg-orange-50 mr-3"
                >
                    <WarningOutlined class="w-5 h-5 text-orange-600" />
                </div>
                <div>
                    <div class="text-lg font-semibold text-gray-900">
                        Low Stock Alert
                    </div>
                    <div class="text-sm text-gray-500">
                        {{ alerts.low_stock.length }} products need attention
                    </div>
                </div>
            </div>
            <div v-if="alerts.low_stock.length" class="space-y-2">
                <div
                    v-for="p in alerts.low_stock"
                    :key="p.id"
                    class="flex items-center justify-between p-3 bg-orange-50 rounded-lg border border-orange-200"
                >
                    <div>
                        <div class="font-medium text-gray-900">
                            {{ p.name }}
                        </div>
                        <div class="text-sm text-gray-500">
                            SKU: {{ p.SKU }}
                        </div>
                    </div>
                    <div class="text-right">
                        <div class="text-sm font-medium text-orange-600">
                            {{ p.current_stock }} left
                        </div>
                        <div class="text-xs text-gray-500">
                            Min: {{ p.min_stock_level }}
                        </div>
                    </div>
                </div>
            </div>
            <div v-else class="text-center py-4 text-gray-500">
                No low stock products
            </div>
        </div>

        <!-- Out of Stock -->
        <div class="bg-white rounded-lg border p-6 shadow-sm">
            <div class="flex items-center mb-4">
                <div
                    class="p-2 rounded-lg border border-red-200 bg-red-50 mr-3"
                >
                    <StopOutlined class="w-5 h-5 text-red-600" />
                </div>
                <div>
                    <div class="text-lg font-semibold text-gray-900">
                        Out of Stock
                    </div>
                    <div class="text-sm text-gray-500">
                        {{ alerts.out_of_stock.length }} products unavailable
                    </div>
                </div>
            </div>
            <div v-if="alerts.out_of_stock.length" class="space-y-2">
                <div
                    v-for="p in alerts.out_of_stock"
                    :key="p.id"
                    class="flex items-center justify-between p-3 bg-red-50 rounded-lg border border-red-200"
                >
                    <div>
                        <div class="font-medium text-gray-900">
                            {{ p.name }}
                        </div>
                        <div class="text-sm text-gray-500">
                            SKU: {{ p.SKU }}
                        </div>
                    </div>
                    <div class="text-right">
                        <div class="text-sm font-medium text-red-600">
                            {{ p.current_stock }} left
                        </div>
                        <div class="text-xs text-gray-500">
                            Min: {{ p.min_stock_level }}
                        </div>
                    </div>
                </div>
            </div>
            <div v-else class="text-center py-4 text-gray-500">
                All products in stock
            </div>
        </div>
    </div>
</template>

<script setup>
import { WarningOutlined, StopOutlined } from "@ant-design/icons-vue";
defineProps({
    alerts: Object,
});
</script>
