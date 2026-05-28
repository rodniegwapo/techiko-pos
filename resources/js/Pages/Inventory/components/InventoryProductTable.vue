<script setup>
import { computed } from "vue";
import { usePage } from "@inertiajs/vue3";
import { useMediaQuery } from "@vueuse/core";
import {
    IconCircleCheck,
    IconAlertTriangle,
    IconCircleX,
    IconEye,
    IconArrowsExchange,
    IconWorld,
} from "@tabler/icons-vue";
import IconTooltipButton from "@/Components/buttons/IconTooltip.vue";
import { useHelpers } from "@/Composables/useHelpers";

const { formatCurrency, formatDate } = useHelpers();
const page = usePage();
const isMdUp = useMediaQuery("(min-width: 768px)");

const emit = defineEmits(["handleTableChange", "showDetails", "transferStock"]);

const props = defineProps({
    inventories: {
        type: Object,
        default: () => ({}),
    },
    pagination: {
        type: Object,
        default: () => ({}),
    },
    loading: {
        type: Boolean,
        default: false,
    },
    isGlobalView: {
        type: Boolean,
        default: false,
    },
});

const showSuperUserDomain = computed(
    () =>
        page.props.auth?.user?.data?.is_super_user && props.isGlobalView,
);

// Simplified columns - only essential information
const columns = computed(() => {
    const baseColumns = [
        {
            title: "Product",
            dataIndex: "product",
            key: "product",
            align: "left",
        },
        {
            title: "Qty (store)",
            dataIndex: "available",
            key: "stock",
            align: "left",
        },
        { title: "Status", dataIndex: "status", key: "status", align: "left" },
        { title: "Value", dataIndex: "value", key: "value", align: "left" },
    ];

    // Add domain column for super users only in global view
    if (showSuperUserDomain.value) {
        baseColumns.splice(1, 0, {
            title: "Domain",
            dataIndex: "domain",
            key: "domain",
            align: "left",
        });
    }

    baseColumns.push({
        title: "Actions",
        key: "actions",
        align: "left",
        width: "1%",
    });

    return baseColumns;
});

const getStockStatusColor = (status) => {
    switch (status) {
        case "in_stock":
            return "success";
        case "low_stock":
            return "warning";
        case "out_of_stock":
            return "error";
        default:
            return "default";
    }
};

const getStockStatusIcon = (status) => {
    switch (status) {
        case "in_stock":
            return IconCircleCheck;
        case "low_stock":
            return IconAlertTriangle;
        case "out_of_stock":
            return IconCircleX;
        default:
            return IconCircleCheck;
    }
};

const getStockStatusText = (status) => {
    switch (status) {
        case "in_stock":
            return "In Stock";
        case "low_stock":
            return "Low Stock";
        case "out_of_stock":
            return "Out of Stock";
        default:
            return "Unknown";
    }
};

const showDetails = (inventory) => {
    emit("showDetails", inventory);
};

const transferStock = (inventory) => {
    emit("transferStock", inventory);
};

const dataSource = computed(() => {
    return (
        props.inventories?.data?.map((inventory) => ({
            key: inventory.id,
            id: inventory.id,
            product: inventory.product,
            sku: inventory.product?.SKU || "N/A",
            /** Sellable qty — matches Products catalog "Qty (store)" */
            available: inventory.quantity_available ?? 0,
            onHand: inventory.quantity_on_hand ?? 0,
            reserved: inventory.quantity_reserved ?? 0,
            status:
                inventory.location_stock_status ||
                inventory.product?.stock_status ||
                "unknown",
            domain: inventory.product?.domain,
            value: inventory.total_value,
            last_movement: inventory.last_movement_at,
            inventory: inventory,
        })) || []
    );
});

function onMobilePaginationChange(pageNum) {
    emit("handleTableChange", {
        current: pageNum,
        pageSize: props.pagination?.pageSize ?? 10,
    });
}
</script>

<template>
    <a-table
        v-if="isMdUp"
        :columns="columns"
        :data-source="dataSource"
        :pagination="pagination"
        :loading="loading"
        @change="$emit('handleTableChange', $event)"
    >
        <!-- Product Column -->
        <template #bodyCell="{ column, record }">
            <template v-if="column.key === 'product'">
                <div class="flex items-center space-x-3">
                    <!-- Product Image/Avatar -->
                    <div
                        class="w-10 h-10 bg-gray-200 rounded-lg flex items-center justify-center"
                    >
                        <img
                            v-if="
                                record.product?.representation_type ===
                                    'image' && record.product?.representation
                            "
                            :src="record.product.representation"
                            :alt="record.product.name"
                            class="w-full h-full object-cover rounded-lg"
                        />
                        <div
                            v-else-if="
                                record.product?.representation_type ===
                                    'color' && record.product?.representation
                            "
                            class="w-full h-full rounded-lg"
                            :style="{
                                backgroundColor: `#${record.product.representation}`,
                            }"
                        ></div>
                        <span v-else class="text-xs text-gray-500">
                            {{ record.product?.name?.charAt(0) || "P" }}
                        </span>
                    </div>

                    <!-- Product Info -->
                    <div>
                        <p class="font-semibold text-gray-900">
                            {{ record.product?.name || "Unknown Product" }}
                        </p>
                        <p class="text-sm text-gray-500">
                            {{
                                record.product?.category?.name || "No Category"
                            }}
                        </p>
                    </div>
                </div>
            </template>

            <!-- Domain Column -->
            <template v-else-if="column.key === 'domain'">
                <div class="flex items-center">
                    <IconWorld class="mr-1" size="16" />
                    <span class="text-sm font-medium">{{
                        record.domain || "N/A"
                    }}</span>
                </div>
            </template>

            <!-- Qty (store): sellable quantity; subline when on-hand differs (e.g. reserved) -->
            <template v-else-if="column.key === 'stock'">
                <div class="flex flex-col gap-0.5">
                    <div class="flex items-center gap-2">
                        <div class="font-semibold text-lg">
                            {{ Math.floor(record.available) }}
                        </div>
                        <div class="text-xs text-gray-500">
                            {{ record.product?.unit_of_measure || "pcs" }} (s)
                        </div>
                    </div>
                    <div
                        v-if="
                            Math.floor(record.onHand) !==
                            Math.floor(record.available)
                        "
                        class="text-xs text-gray-500"
                    >
                        On hand {{ Math.floor(record.onHand)
                        }}<span v-if="record.reserved > 0">
                            · Reserved {{ Math.floor(record.reserved) }}
                        </span>
                    </div>
                </div>
            </template>


            <!-- Status Column -->
            <template v-else-if="column.key === 'status'">
                <a-tag
                    class="w-fit"
                    :color="getStockStatusColor(record.status)"
                >
                    <component
                        :is="getStockStatusIcon(record.status)"
                        :size="16"
                        class="mr-1"
                    />
                    {{ getStockStatusText(record.status) }}
                </a-tag>
            </template>

            <!-- Value Column -->
            <template v-else-if="column.key === 'value'">
                <div>
                    <p class="font-semibold">
                        {{ formatCurrency(record.value) }}
                    </p>
                    <p class="text-xs text-gray-500">
                        @
                        {{
                            formatCurrency(record.inventory?.average_cost || 0)
                        }}
                    </p>
                </div>
            </template>

            <!-- Actions Column -->
            <template v-else-if="column.key === 'actions'">
                <div class="flex justify-center space-x-1">
                    <IconTooltipButton
                        name="View Details"
                        @click="showDetails(record.inventory)"
                    >
                        <IconEye :size="20" class="mx-auto" />
                    </IconTooltipButton>

                    <IconTooltipButton
                        name="Transfer Stock"
                        @click="transferStock(record.inventory)"
                    >
                        <IconArrowsExchange :size="20" class="mx-auto" />
                    </IconTooltipButton>
                </div>
            </template>
        </template>

        <!-- Empty State -->
        <template #emptyText>
            <div class="text-center py-8">
                <IconCircleX :size="48" class="mx-auto text-gray-400 mb-4" />
                <p class="text-gray-500">No inventory records found</p>
                <p class="text-sm text-gray-400">
                    Try adjusting your filters or add some inventory
                </p>
            </div>
        </template>
    </a-table>

    <div v-else class="px-2 py-2 md:px-0">
        <a-spin :spinning="loading">
            <div
                v-if="!dataSource.length"
                class="py-12 text-center text-sm text-gray-500"
            >
                <IconCircleX
                    :size="48"
                    class="mx-auto mb-4 text-gray-400"
                />
                <p>No inventory records found</p>
                <p class="text-xs text-gray-400">
                    Try adjusting your filters or add some inventory
                </p>
            </div>
            <div v-else class="flex flex-col gap-3">
                <div
                    v-for="record in dataSource"
                    :key="record.id"
                    class="overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm"
                >
                    <div class="flex gap-3 px-4 py-3">
                        <div
                            class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-gray-200"
                        >
                            <img
                                v-if="
                                    record.product?.representation_type ===
                                        'image' &&
                                    record.product?.representation
                                "
                                :src="record.product.representation"
                                :alt="record.product.name"
                                class="h-full w-full rounded-lg object-cover"
                            />
                            <div
                                v-else-if="
                                    record.product?.representation_type ===
                                        'color' &&
                                    record.product?.representation
                                "
                                class="h-full w-full rounded-lg"
                                :style="{
                                    backgroundColor: `#${record.product.representation}`,
                                }"
                            ></div>
                            <span v-else class="text-xs text-gray-500">
                                {{ record.product?.name?.charAt(0) || "P" }}
                            </span>
                        </div>
                        <div class="min-w-0 flex-1">
                            <div
                                class="truncate text-base font-semibold text-gray-900"
                            >
                                {{
                                    record.product?.name || "Unknown Product"
                                }}
                            </div>
                            <div class="mt-1 text-sm text-gray-600">
                                {{
                                    record.product?.category?.name ||
                                    "No Category"
                                }}
                            </div>
                            <div class="mt-2">
                                <a-tag
                                    class="m-0"
                                    :color="getStockStatusColor(record.status)"
                                >
                                    <component
                                        :is="getStockStatusIcon(record.status)"
                                        :size="14"
                                        class="mr-1"
                                    />
                                    {{ getStockStatusText(record.status) }}
                                </a-tag>
                            </div>
                        </div>
                    </div>

                    <div class="mx-4 mb-3 rounded-lg bg-gray-50 p-3">
                        <div
                            class="grid grid-cols-[auto_1fr] gap-x-3 gap-y-2 text-sm"
                        >
                            <span class="text-gray-500">Qty (store)</span>
                            <span
                                class="text-right font-semibold text-gray-900"
                            >
                                {{ Math.floor(record.available) }}
                                {{ record.product?.unit_of_measure || "pcs" }}
                                (s)
                            </span>
                            <template
                                v-if="
                                    Math.floor(record.onHand) !==
                                    Math.floor(record.available)
                                "
                            >
                                <span class="text-gray-500">On hand</span>
                                <span
                                    class="text-right font-medium text-gray-900"
                                >
                                    {{ Math.floor(record.onHand) }}
                                    <span v-if="record.reserved > 0">
                                        · Reserved
                                        {{ Math.floor(record.reserved) }}
                                    </span>
                                </span>
                            </template>
                            <span class="text-gray-500">Value</span>
                            <span
                                class="text-right font-semibold text-green-600"
                            >
                                {{ formatCurrency(record.value) }}
                            </span>
                            <span class="text-gray-500">Avg cost</span>
                            <span
                                class="text-right font-medium text-gray-900"
                            >
                                {{
                                    formatCurrency(
                                        record.inventory?.average_cost || 0,
                                    )
                                }}
                            </span>
                            <template v-if="showSuperUserDomain">
                                <span class="text-gray-500">Domain</span>
                                <span
                                    class="flex min-w-0 items-center justify-end gap-1 truncate font-medium text-gray-900"
                                >
                                    <IconWorld size="16" class="shrink-0" />
                                    {{ record.domain || "N/A" }}
                                </span>
                            </template>
                        </div>
                    </div>

                    <div class="border-t border-gray-100 px-4 py-3">
                        <div class="flex flex-col gap-2">
                            <a-button
                                class="flex items-center justify-center gap-2"
                                @click="showDetails(record.inventory)"
                            >
                                <template #icon>
                                    <IconEye size="18" />
                                </template>
                                View details
                            </a-button>
                            <a-button
                                class="flex items-center justify-center gap-2"
                                @click="transferStock(record.inventory)"
                            >
                                <template #icon>
                                    <IconArrowsExchange size="18" />
                                </template>
                                Transfer stock
                            </a-button>
                        </div>
                    </div>
                </div>
            </div>
            <a-pagination
                v-if="
                    pagination?.total &&
                    pagination.total > (pagination.pageSize ?? 10)
                "
                class="mt-4 justify-center pt-2"
                show-less-items
                :current="pagination.current"
                :page-size="pagination.pageSize"
                :total="pagination.total"
                :show-size-changer="false"
                @change="onMobilePaginationChange"
            />
        </a-spin>
    </div>
</template>

<style scoped>
.ant-table-tbody > tr > td {
    padding: 12px 8px;
}

.ant-table-thead > tr > th {
    background-color: #fafafa;
    font-weight: 600;
}
</style>
