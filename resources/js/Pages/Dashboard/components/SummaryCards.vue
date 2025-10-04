<template>
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
        <div
            v-for="card in cards"
            :key="card.title"
            class="bg-white rounded-lg border p-6 shadow-sm hover:shadow-md transition-shadow"
        >
            <div class="flex items-center justify-between">
                <div>
                    <div class="text-sm font-medium text-gray-600 mb-1">
                        {{ card.title }}
                    </div>
                    <div class="text-2xl font-bold text-green-700 mb-2">
                        {{ card.value }}
                    </div>
                    <div class="flex items-center">
                        <component
                            :is="
                                card.trend === 'up'
                                    ? 'ArrowUpOutlined'
                                    : 'ArrowDownOutlined'
                            "
                            class="w-4 h-4 mr-1"
                            :class="
                                card.trend === 'up'
                                    ? 'text-green-500'
                                    : 'text-red-500'
                            "
                        />
                        <span
                            :class="
                                card.trend === 'up'
                                    ? 'text-green-600'
                                    : 'text-red-600'
                            "
                            class="text-sm font-medium"
                        >
                            {{ card.change > 0 ? "+" : "" }}{{ card.change }}%
                        </span>
                        <span class="text-sm text-gray-500 ml-2"
                            >vs yesterday</span
                        >
                    </div>
                </div>
                <div
                    :class="`p-3 rounded-lg border text-${card.color}-600 bg-${card.color}-50 border-${card.color}-200`"
                >
                    <component :is="card.icon" class="w-6 h-6" />
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
defineProps({
    cards: Array,
});
</script>
