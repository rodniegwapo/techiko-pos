<template>
    <div class="bg-white rounded-lg border p-6 shadow-sm">
        <div class="flex items-center justify-between mb-3">
            <div class="text-lg font-semibold text-gray-900">Top Products</div>
            <span class="text-sm text-gray-500">This week</span>
        </div>
        <div class="space-y-2">
            <div
                v-for="(product, index) in products"
                :key="product.name"
                class="flex items-center justify-between px-3 py-1 border bg-gray-50 rounded-lg"
            >
                <div class="flex items-center">
                    <div
                        class="bg-blue-100 rounded-full flex items-center justify-center mr-3"
                    >
                        <span class="text-sm font-bold text-blue-600"
                            >#{{ index + 1 }}</span
                        >
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
                    <p class="text-sm font-semibold text-gray-900 py-2">
                        {{ product.revenue }}
                    </p>
                    <p class="text-xs text-gray-500">revenue</p>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
defineProps({
    products: Array,
});
</script>
