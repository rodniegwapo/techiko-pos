<template>
    <div class="bg-white rounded-lg border p-6 shadow-sm">
        <!-- Store Performance (Admin/Super User/Role 1-2) -->
        <div v-if="isHighLevelUser" class="flex flex-col h-full">
            <div class="flex items-center mb-4">
                <div
                    class="p-2 rounded-lg border border-blue-200 bg-blue-50 mr-3 relative"
                >
                    <ShopOutlined class="w-5 h-5 text-blue-600" />
                </div>
                <div>
                    <div class="text-lg font-semibold text-gray-900">
                        Today's Store Performance
                    </div>
                    <div class="text-sm text-gray-500">
                        Sales across all locations
                    </div>
                </div>
            </div>

            <!-- Main content area with flex layout -->
            <div class="flex flex-col flex-1 min-h-0">
                <!-- Scrollable stores list -->
                <div
                    v-if="storePerformance.locations.length > 0"
                    class="flex-1 overflow-y-auto space-y-2"
                >
                    <div
                        v-for="(store, index) in storePerformance.locations"
                        :key="store.id"
                        class="flex items-center justify-between p-3 bg-blue-50 rounded-lg border border-blue-200"
                    >
                        <div class="flex items-center">
                            <div
                                class="bg-blue-100 rounded-full flex items-center justify-center mr-3 w-8 h-8"
                            >
                                <span class="text-sm font-bold text-blue-600">
                                    #{{ index + 1 }}
                                </span>
                            </div>
                            <div>
                                <div class="font-medium text-gray-900">
                                    {{ store.name }}
                                </div>
                                <div class="text-sm text-gray-500">
                                    {{ store.location_type || "Store" }}
                                </div>
                            </div>
                        </div>
                        <div class="text-right">
                            <div class="text-sm font-semibold text-blue-600">
                                {{ formatCurrency(store.today_sales) }}
                            </div>
                            <div class="text-xs text-gray-500">
                                {{ store.transaction_count }} transactions
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Empty state -->
                <div
                    v-else
                    class="flex-1 flex items-center justify-center text-center py-8 text-gray-500"
                >
                    <div>
                        <div class="text-lg font-medium mb-1">
                            No Sales Today
                        </div>
                        <div class="text-sm">Check back later for updates</div>
                    </div>
                </div>

                <!-- Total Sales Summary - Always at bottom -->
                <div
                    v-if="storePerformance.locations.length > 0"
                    class="mt-4 pt-4 border-t border-gray-200 flex-shrink-0"
                >
                    <div
                        class="flex items-center justify-between p-3 bg-gray-50 rounded-lg"
                    >
                        <div class="flex items-center">
                            <div
                                class="bg-gray-100 rounded-full flex items-center justify-center mr-3 w-8 h-8"
                            >
                                <span class="text-sm font-bold text-gray-600"
                                    >Σ</span
                                >
                            </div>
                            <div>
                                <div class="font-medium text-gray-900">
                                    Total Sales Today
                                </div>
                                <div class="text-sm text-gray-500">
                                    {{ storePerformance.totalLocations }}
                                    locations
                                </div>
                            </div>
                        </div>
                        <div class="text-right">
                            <div class="text-lg font-bold text-gray-900">
                                {{
                                    formatCurrency(storePerformance.totalSales)
                                }}
                            </div>
                            <div class="text-xs text-gray-500">
                                {{ storePerformance.totalTransactions }}
                                transactions
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- User Performance (Role 3+) -->
        <div v-else>
            <div class="flex items-center mb-4">
                <div
                    class="p-2 rounded-lg border border-green-200 bg-green-50 mr-3"
                >
                    <UserOutlined class="w-5 h-5 text-green-600" />
                </div>
                <div>
                    <div class="text-lg font-semibold text-gray-900">
                        Top Performers Today
                    </div>
                    <div class="text-sm text-gray-500">
                        Best sales in your location
                    </div>
                </div>
            </div>

            <div v-if="topUsers.length > 0" class="space-y-2">
                <div
                    v-for="(user, index) in topUsers"
                    :key="user.id"
                    class="flex items-center justify-between p-3 bg-green-50 rounded-lg border border-green-200"
                >
                    <div class="flex items-center">
                        <div
                            class="bg-green-100 rounded-full flex items-center justify-center mr-3 w-8 h-8"
                        >
                            <span class="text-sm font-bold text-green-600">
                                #{{ index + 1 }}
                            </span>
                        </div>
                        <div>
                            <div class="font-medium text-gray-900">
                                {{ user.name }}
                            </div>
                            <div class="text-sm text-gray-500">
                                {{ user.role || "Staff" }}
                            </div>
                        </div>
                    </div>
                    <div class="text-right">
                        <div class="text-sm font-semibold text-green-600">
                            {{ formatCurrency(user.today_sales) }}
                        </div>
                        <div class="text-xs text-gray-500">
                            {{ user.transaction_count }} transactions
                        </div>
                    </div>
                </div>
            </div>

            <div v-else class="text-center py-4 text-gray-500">
                <div class="text-lg font-medium mb-1">No Sales Today</div>
                <div class="text-sm">Check back later for updates</div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { computed } from "vue";
import { ShopOutlined, UserOutlined } from "@ant-design/icons-vue";
import { useHelpers } from "@/Composables/useHelpers";
import { usePage } from "@inertiajs/vue3";

const { formatCurrency } = useHelpers();
const page = usePage();

const props = defineProps({
    storePerformance: {
        type: Object,
        default: () => ({
            locations: [],
            totalSales: 0,
            totalTransactions: 0,
            totalLocations: 0,
        }),
    },
    topUsers: {
        type: Array,
        default: () => [],
    },
});

// Determine if user is high level (Admin/Super User/Role 1-2)
const isHighLevelUser = computed(() => {
    const user = page.props.auth?.user?.data;
    if (!user) return false;

    // Check if super user
    if (user.is_super_user) return true;

    // Check role level (assuming role level is stored in user data)
    const roleLevel = user.role_level || user.role?.level;
    return roleLevel && roleLevel <= 2;
});
</script>
