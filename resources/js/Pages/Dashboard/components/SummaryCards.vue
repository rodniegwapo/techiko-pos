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
                                    : card.trend === 'down'
                                    ? 'ArrowDownOutlined'
                                    : 'MinusOutlined'
                            "
                            class="w-4 h-4 mr-1"
                            :class="
                                card.trend === 'up'
                                    ? 'text-green-500'
                                    : card.trend === 'down'
                                    ? 'text-red-500'
                                    : 'text-gray-500'
                            "
                        />
                        <span
                            :class="
                                card.trend === 'up'
                                    ? 'text-green-600'
                                    : card.trend === 'down'
                                    ? 'text-red-600'
                                    : 'text-gray-600'
                            "
                            class="text-sm font-medium"
                        >
                            {{ formatChange(card.change) }}
                        </span>
                        <span class="text-sm text-gray-500 ml-2">
                            {{ getComparisonLabel(card.title) }}
                        </span>
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
import { ArrowUpOutlined, ArrowDownOutlined, MinusOutlined } from "@ant-design/icons-vue";

defineProps({
    cards: Array,
});

// Function to format the change percentage
const formatChange = (change) => {
    if (change === 0) return "0%";
    return `${change > 0 ? "+" : ""}${change}%`;
};

// Function to get the correct comparison label based on card title
const getComparisonLabel = (title) => {
    switch (title) {
        case "Today's Sales":
            return "vs yesterday";
        case "This Week Revenue":
            return "vs last week";
        case "Pending Orders":
            return "vs yesterday";
        case "Inventory Value":
            return "vs last month";
        default:
            return "vs previous";
    }
};
</script>