<script setup>
import { computed, ref } from "vue";
import IconTooltipButton from "@/Components/buttons/IconTooltip.vue";
import {
    IconTrash,
    IconEdit,
    IconCurrencyPeso,
    IconWorld,
    IconEye,
} from "@tabler/icons-vue";
import { useHelpers } from "@/Composables/useHelpers";
import { useGlobalVariables } from "@/Composables/useGlobalVariable";
import { useDomainRoutes } from "@/Composables/useDomainRoutes";

import { usePage, router } from "@inertiajs/vue3";

const page = usePage();

const { confirmDelete, formatCurrency, formatDate } = useHelpers();
const { formData, openModal, isEdit, spinning } = useGlobalVariables();
const { getRoute } = useDomainRoutes();

// Modal state for product details
const detailsModalVisible = ref(false);
const selectedProduct = ref(null);

// Access location data from backend query
const currentLocation = computed(() => page.props.currentLocation);

defineEmits(["handleTableChange"]);

const props = defineProps({
    pagination: {
        type: Object,
        default: {},
    },
    isGlobalView: {
        type: Boolean,
        default: false,
    },
});

const columns = computed(() => {
    const baseColumns = [
        { title: "Avatar", dataIndex: "avatar", key: "avatar", align: "left" },
        { title: "Product", dataIndex: "name", key: "name", align: "left" },
        {
            title: "Category",
            dataIndex: "category",
            key: "category",
            align: "left",
        },
        {
            title: "Price",
            dataIndex: "price",
            key: "price",
            align: "left",
        },
        { title: "Cost", dataIndex: "cost", key: "cost", align: "left" },
        {
            title: "SKU",
            dataIndex: "SKU",
            key: "SKU",
            align: "left",
        },
    ];

    // Add domain column for super users only in global view
    if (page.props.auth?.user?.data?.is_super_user && props.isGlobalView) {
        baseColumns.splice(2, 0, {
            title: "Domain",
            dataIndex: "domain",
            key: "domain",
            align: "left",
        });
    }

    baseColumns.push({
        title: "Action",
        key: "action",
        align: "center",
        width: "1%",
    });

    return baseColumns;
});

const handleDeleteCategory = (record) => {
    confirmDelete(
        "products.destroy",
        { product: record.id },
        "Do you want to delete this item ?",
    );
};

const handleClickEdit = (record) => {
    router.visit(getRoute("products.edit", { product: record.id }));
};

const showDetails = (product) => {
    selectedProduct.value = product;
    detailsModalVisible.value = true;
};
</script>

<template>
    <a-table
        class="ant-table-striped"
        :columns="columns"
        :data-source="page.props?.items?.data ?? []"
        :row-class-name="
            (_, index) => (index % 2 === 1 ? 'bg-gray-50 group' : 'group')
        "
        @change="$emit('handleTableChange', $event)"
        :pagination="pagination"
        :loading="spinning"
    >
        <template #bodyCell="{ index, column, record }">
            <template v-if="column.key == 'avatar'">
                <a-avatar
                    v-if="record.representation_type == 'color'"
                    shape="circle"
                    size="large"
                    :src="`https://ui-avatars.com/api/?name=${record.name}&background=${record.representation}&color=ffff`"
                >
                </a-avatar>
                <a-avatar
                    v-else
                    shape="circle"
                    size="large"
                    :src="`https://ui-avatars.com/api/?name=${record.name}&background=blue&color=ffff`"
                >
                </a-avatar>
            </template>

            <template v-if="column.key == 'domain'">
                <div class="flex items-center">
                    <IconWorld class="mr-1" size="16" />
                    <span class="text-sm font-medium">{{
                        record.domain || "N/A"
                    }}</span>
                </div>
            </template>

            <template v-if="column.key == 'category'">
                {{ record.category?.name }}
            </template>

            <template v-if="column.key == 'price'">
                <div class="flex items-center">
                    <IconCurrencyPeso /> {{ record.price }}
                </div>
            </template>
            <template v-if="column.key == 'cost'">
                <div class="flex items-center">
                    <IconCurrencyPeso /> {{ record.cost }}
                </div>
            </template>

            <template v-if="column.key == 'SKU'">
                <div class="flex items-center">
                    <a-tag color="blue">
                        <div class="flex items-center">
                            {{ record.SKU }}
                        </div></a-tag
                    >
                </div>
            </template>
            <template v-if="column.key == 'action'">
                <div class="flex items-center gap-2">
                    <IconTooltipButton
                        hover="group-hover:bg-green-500"
                        name="View Details"
                        @click="showDetails(record)"
                    >
                        <IconEye size="20" class="mx-auto" />
                    </IconTooltipButton>

                    <IconTooltipButton
                        hover="group-hover:bg-blue-500"
                        name="Edit Product"
                        @click="handleClickEdit(record)"
                    >
                        <IconEdit size="20" class="mx-auto" />
                    </IconTooltipButton>

                    <IconTooltipButton
                        hover="group-hover:bg-red-500"
                        name="Delete Product"
                        @click="handleDeleteCategory(record)"
                    >
                        <IconTrash size="20" class="mx-auto" />
                    </IconTooltipButton>
                </div>
            </template>
        </template>
    </a-table>

    <!-- Product Details Modal -->
    <a-modal
        v-model:visible="detailsModalVisible"
        title="Product Details"
        width="800px"
        :footer="null"
    >
        <div v-if="selectedProduct" class="space-y-6">
            <!-- Product Header -->
            <div class="flex items-start space-x-4">
                <a-avatar
                    v-if="selectedProduct.representation_type == 'color'"
                    shape="circle"
                    size="large"
                    :src="`https://ui-avatars.com/api/?name=${selectedProduct.name}&background=${selectedProduct.representation}&color=ffff`"
                />
                <a-avatar
                    v-else
                    shape="circle"
                    size="large"
                    :src="`https://ui-avatars.com/api/?name=${selectedProduct.name}&background=blue&color=ffff`"
                />
                <div class="flex-1">
                    <h3 class="text-xl font-semibold text-gray-900">
                        {{ selectedProduct.name }}
                    </h3>
                    <p class="text-sm text-gray-600">
                        {{
                            selectedProduct.description ||
                            "No description available"
                        }}
                    </p>
                    <div class="flex items-center mt-2">
                        <a-tag color="blue" class="text-xs">{{
                            selectedProduct.SKU
                        }}</a-tag>
                        <a-tag
                            v-if="selectedProduct.track_inventory"
                            color="green"
                            class="text-xs ml-2"
                            >Inventory Tracked</a-tag
                        >
                        <a-tag v-else color="orange" class="text-xs ml-2"
                            >No Inventory Tracking</a-tag
                        >
                    </div>
                </div>
            </div>

            <!-- Product Information Grid -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <!-- Pricing Information -->
                <div class="bg-gray-50 rounded-lg p-4">
                    <h4 class="font-semibold text-gray-900 mb-3">
                        Pricing Information
                    </h4>
                    <div class="space-y-2">
                        <div class="flex justify-between">
                            <span class="text-sm text-gray-600"
                                >Selling Price:</span
                            >
                            <span class="font-semibold text-green-600">{{
                                formatCurrency(selectedProduct.price)
                            }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-sm text-gray-600"
                                >Cost Price:</span
                            >
                            <span class="font-semibold text-blue-600">{{
                                formatCurrency(selectedProduct.cost || 0)
                            }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-sm text-gray-600"
                                >Profit Margin:</span
                            >
                            <span class="font-semibold text-purple-600">
                                {{
                                    selectedProduct.cost
                                        ? (
                                              ((selectedProduct.price -
                                                  selectedProduct.cost) /
                                                  selectedProduct.price) *
                                              100
                                          ).toFixed(1)
                                        : "N/A"
                                }}%
                            </span>
                        </div>
                    </div>
                </div>

                <!-- Category & Type Information -->
                <div class="bg-gray-50 rounded-lg p-4">
                    <h4 class="font-semibold text-gray-900 mb-3">
                        Category & Type
                    </h4>
                    <div class="space-y-2">
                        <div class="flex justify-between">
                            <span class="text-sm text-gray-600">Category:</span>
                            <span class="font-semibold">{{
                                selectedProduct.category?.name || "No category"
                            }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-sm text-gray-600">Sold By:</span>
                            <span class="font-semibold">{{
                                selectedProduct.sold_type || "N/A"
                            }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-sm text-gray-600">Domain:</span>
                            <span class="font-semibold">{{
                                selectedProduct.domain || "N/A"
                            }}</span>
                        </div>
                        <!-- Location Information -->
                        <!-- Global view: show locations from eager-loaded relation -->
                        <div
                            v-if="
                                props.isGlobalView &&
                                (selectedProduct.locations?.length || 0) > 0
                            "
                            class="flex justify-between"
                        >
                            <span class="text-sm text-gray-600"
                                >Locations:</span
                            >
                            <div class="text-right">
                                <span class="font-semibold text-blue-600">
                                    {{ selectedProduct.locations.length }}
                                    location(s)
                                </span>
                                <div class="text-xs text-gray-500 mt-1">
                                    <div
                                        v-for="loc in selectedProduct.locations.slice(
                                            0,
                                            3,
                                        )"
                                        :key="loc.id"
                                    >
                                        {{ loc.name }}
                                    </div>
                                    <div
                                        v-if="
                                            selectedProduct.locations.length > 3
                                        "
                                        class="text-gray-400"
                                    >
                                        +{{
                                            selectedProduct.locations.length - 3
                                        }}
                                        more...
                                    </div>
                                </div>
                            </div>
                        </div>
                        <!-- Domain-scoped view: show the current location -->
                        <div
                            v-else-if="!props.isGlobalView && currentLocation"
                            class="flex justify-between"
                        >
                            <span class="text-sm text-gray-600">Location:</span>
                            <span class="font-semibold text-blue-600">{{
                                currentLocation.name || "Unknown Location"
                            }}</span>
                        </div>
                        <div v-else class="flex justify-between">
                            <span class="text-sm text-gray-600">Location:</span>
                            <span class="text-xs text-gray-500"
                                >No location data available</span
                            >
                        </div>
                    </div>
                </div>

                <!-- Inventory Settings -->
                <div
                    v-if="selectedProduct.track_inventory"
                    class="bg-gray-50 rounded-lg p-4"
                >
                    <h4 class="font-semibold text-gray-900 mb-3">
                        Inventory Settings
                    </h4>
                    <div class="space-y-2">
                        <div class="flex justify-between">
                            <span class="text-sm text-gray-600"
                                >Reorder Level:</span
                            >
                            <span class="font-semibold">{{
                                selectedProduct.reorder_level || "Not set"
                            }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-sm text-gray-600"
                                >Max Stock Level:</span
                            >
                            <span class="font-semibold">{{
                                selectedProduct.max_stock_level || "Not set"
                            }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-sm text-gray-600"
                                >Unit Weight:</span
                            >
                            <span class="font-semibold">{{
                                selectedProduct.unit_weight
                                    ? `${selectedProduct.unit_weight} kg`
                                    : "Not set"
                            }}</span>
                        </div>
                    </div>
                </div>

                <!-- Product Metadata -->
                <div class="bg-gray-50 rounded-lg p-4">
                    <h4 class="font-semibold text-gray-900 mb-3">
                        Product Metadata
                    </h4>
                    <div class="space-y-2">
                        <div class="flex justify-between">
                            <span class="text-sm text-gray-600">Created:</span>
                            <span class="font-semibold">{{
                                formatDate(selectedProduct.created_at)
                            }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-sm text-gray-600"
                                >Last Updated:</span
                            >
                            <span class="font-semibold">{{
                                formatDate(selectedProduct.updated_at)
                            }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-sm text-gray-600"
                                >Product ID:</span
                            >
                            <span class="font-semibold text-gray-500"
                                >#{{ selectedProduct.id }}</span
                            >
                        </div>
                    </div>
                </div>
            </div>

            <!-- Additional Notes -->
            <div
                v-if="selectedProduct.description"
                class="bg-blue-50 rounded-lg p-4"
            >
                <h4 class="font-semibold text-gray-900 mb-2">Description</h4>
                <p class="text-sm text-gray-700">
                    {{ selectedProduct.description }}
                </p>
            </div>
        </div>
    </a-modal>
</template>
