<template>
    <div class="bg-white rounded-lg border p-6 shadow-sm overflow-hidden">
        <div class="flex items-center mb-4">
            <div
                class="p-2 rounded-lg border border-orange-200 bg-orange-50 mr-3"
            >
                <WarningOutlined class="w-5 h-5 text-orange-600" />
            </div>
            <div>
                <div class="text-lg font-semibold text-gray-900">
                    Inventory Alerts
                </div>
                <div class="text-sm text-gray-500">
                    {{ totalAlerts }} products need attention
                </div>
            </div>
        </div>

        <div class="max-h-[400px] overflow-y-scroll overflow-x-hidden">
            <div v-if="hasAlerts" class="space-y-2">
                <!-- Low Stock Items (Orange) -->
                <div
                    v-for="product in alerts.low_stock"
                    :key="`low-${product.id}`"
                    class="flex items-center justify-between p-3 bg-orange-50 rounded-lg border border-orange-200"
                >
                    <div>
                        <div class="font-medium text-gray-900">
                            {{ product.name }}
                        </div>
                        <div class="text-sm text-gray-500">
                            SKU: {{ product.SKU }}
                        </div>
                    </div>
                    <div class="text-right">
                        <div class="text-sm font-medium text-orange-600">
                            {{ product.current_stock }} left
                        </div>
                        <div class="text-xs text-gray-500">
                            Min: {{ product.min_stock_level }}
                        </div>
                    </div>
                </div>

                <!-- Out of Stock Items (Red) -->
                <div
                    v-for="product in alerts.out_of_stock"
                    :key="`out-${product.id}`"
                    class="flex items-center justify-between p-3 bg-red-50 rounded-lg border border-red-200"
                >
                    <div>
                        <div class="font-medium text-gray-900">
                            {{ product.name }}
                        </div>
                        <div class="text-sm text-gray-500">
                            SKU: {{ product.SKU }}
                        </div>
                    </div>
                    <div class="text-right">
                        <div class="text-sm font-medium text-red-600">
                            Out of Stock
                        </div>
                        <div class="text-xs text-gray-500">
                            Min: {{ product.min_stock_level }}
                        </div>
                    </div>
                </div>
            </div>

            <div v-else class="text-center py-4 text-gray-500">
                <div class="text-lg font-medium mb-1">All Good!</div>
                <div class="text-sm">No inventory alerts at this time</div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { computed } from "vue";
import { WarningOutlined } from "@ant-design/icons-vue";

const props = defineProps({
    alerts: {
        type: Object,
        required: true,
        default: () => ({
            low_stock: [],
            out_of_stock: [],
        }),
    },
});

const totalAlerts = computed(
    () =>
        (props.alerts.low_stock?.length || 0) +
        (props.alerts.out_of_stock?.length || 0)
);

const hasAlerts = computed(() => totalAlerts.value > 0);
</script>
