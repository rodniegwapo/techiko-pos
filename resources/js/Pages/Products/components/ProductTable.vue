<script setup>
import { computed, ref } from "vue";
import { useMediaQuery } from "@vueuse/core";
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
const emit = defineEmits(["handleTableChange"]);

const { confirmDelete, formatCurrency, formatDate } = useHelpers();
const { spinning } = useGlobalVariables();
const { getRoute, getLocationQueryFromPage } = useDomainRoutes();

const isMdUp = useMediaQuery("(min-width: 768px)");

const detailsModalVisible = ref(false);
const selectedProduct = ref(null);

const currentLocation = computed(() => page.props.currentLocation);

const props = defineProps({
    pagination: {
        type: Object,
        default: () => ({}),
    },
    isGlobalView: {
        type: Boolean,
        default: false,
    },
});

const products = computed(() => page.props?.items?.data ?? []);

const showSuperUserDomain = computed(
    () =>
        page.props.auth?.user?.data?.is_super_user && props.isGlobalView,
);

const showStoreQty = computed(
    () => !props.isGlobalView && !!currentLocation.value,
);

const detailsModalWidth = computed(() =>
    isMdUp.value ? 800 : "calc(100vw - 24px)",
);
const detailsModalRootStyle = computed(() =>
    isMdUp.value ? {} : { maxWidth: "100vw", top: "12px", paddingBottom: 0 },
);

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

    if (showStoreQty.value) {
        baseColumns.push({
            title: "Qty (store)",
            dataIndex: "location_quantity_available",
            key: "location_quantity_available",
            align: "left",
        });
    }

    if (showSuperUserDomain.value) {
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

function avatarSrc(record) {
    if (record.representation_type === "color") {
        return `https://ui-avatars.com/api/?name=${record.name}&background=${record.representation}&color=ffff`;
    }
    return `https://ui-avatars.com/api/?name=${record.name}&background=blue&color=ffff`;
}

function categoryName(record) {
    return record.category?.name || "Uncategorized";
}

function storeQtyLabel(record) {
    if (!record.track_inventory) return "N/A";
    return String(record.location_quantity_available ?? 0);
}

const handleDeleteCategory = (record) => {
    confirmDelete(
        "products.destroy",
        { product: record.id },
        "Do you want to delete this item ?",
    );
};

const handleClickEdit = (record) => {
    router.get(
        getRoute("products.edit", { product: record.id }),
        getLocationQueryFromPage(),
    );
};

const showDetails = (product) => {
    selectedProduct.value = product;
    detailsModalVisible.value = true;
};

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
        class="ant-table-striped"
        :columns="columns"
        :data-source="products"
        :row-class-name="
            (_, index) => (index % 2 === 1 ? 'bg-gray-50 group' : 'group')
        "
        :pagination="pagination"
        :loading="spinning"
        @change="$emit('handleTableChange', $event)"
    >
        <template #bodyCell="{ column, record }">
            <template v-if="column.key == 'avatar'">
                <a-avatar shape="circle" size="large" :src="avatarSrc(record)" />
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
                {{ categoryName(record) }}
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
                <a-tag color="blue">{{ record.SKU }}</a-tag>
            </template>
            <template v-if="column.key == 'location_quantity_available'">
                <span v-if="!record.track_inventory" class="text-gray-400"
                    >N/A</span
                >
                <span v-else class="font-medium">{{
                    record.location_quantity_available ?? 0
                }}</span>
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

    <div v-else class="px-2 py-2 sm:px-4 md:px-0">
        <a-spin :spinning="spinning">
            <div
                v-if="!products.length"
                class="py-12 text-center text-sm text-gray-500"
            >
                No products found.
            </div>
            <div v-else class="flex flex-col gap-3">
                <a-card
                    v-for="record in products"
                    :key="record.id"
                    size="small"
                    class="shadow-sm"
                >
                    <div class="flex gap-3">
                        <a-avatar
                            shape="circle"
                            size="large"
                            :src="avatarSrc(record)"
                        />
                        <div class="min-w-0 flex-1">
                            <div class="font-semibold text-gray-900">
                                {{ record.name }}
                            </div>
                            <div class="mt-1 text-sm text-gray-600">
                                {{ categoryName(record) }}
                            </div>
                            <div
                                v-if="showSuperUserDomain"
                                class="mt-1 flex items-center text-sm text-gray-600"
                            >
                                <IconWorld class="mr-1 shrink-0" size="16" />
                                <span class="truncate">{{
                                    record.domain || "N/A"
                                }}</span>
                            </div>
                            <div
                                class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm"
                            >
                                <span class="inline-flex items-center">
                                    <IconCurrencyPeso class="mr-0.5" />
                                    {{ record.price }}
                                </span>
                                <span class="inline-flex items-center text-gray-600">
                                    <IconCurrencyPeso class="mr-0.5" />
                                    {{ record.cost }}
                                </span>
                                <a-tag color="blue" class="m-0">{{
                                    record.SKU
                                }}</a-tag>
                            </div>
                            <div
                                v-if="showStoreQty"
                                class="mt-1 text-sm text-gray-600"
                            >
                                Qty (store):
                                <span
                                    :class="
                                        record.track_inventory
                                            ? 'font-medium text-gray-900'
                                            : 'text-gray-400'
                                    "
                                    >{{ storeQtyLabel(record) }}</span
                                >
                            </div>
                        </div>
                    </div>
                    <div
                        class="mt-3 flex flex-wrap items-center justify-end gap-2 border-t border-gray-100 pt-3"
                    >
                        <IconTooltipButton
                            hover="hover:bg-green-500"
                            name="View Details"
                            @click="showDetails(record)"
                        >
                            <IconEye size="20" class="mx-auto" />
                        </IconTooltipButton>
                        <IconTooltipButton
                            hover="hover:bg-blue-500"
                            name="Edit Product"
                            @click="handleClickEdit(record)"
                        >
                            <IconEdit size="20" class="mx-auto" />
                        </IconTooltipButton>
                        <IconTooltipButton
                            hover="hover:bg-red-500"
                            name="Delete Product"
                            @click="handleDeleteCategory(record)"
                        >
                            <IconTrash size="20" class="mx-auto" />
                        </IconTooltipButton>
                    </div>
                </a-card>
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

    <a-modal
        v-model:visible="detailsModalVisible"
        title="Product Details"
        :width="detailsModalWidth"
        :style="detailsModalRootStyle"
        centered
        :footer="null"
    >
        <div v-if="selectedProduct" class="space-y-6">
            <div class="flex items-start space-x-4">
                <a-avatar
                    shape="circle"
                    size="large"
                    :src="avatarSrc(selectedProduct)"
                />
                <div class="min-w-0 flex-1">
                    <h3 class="text-xl font-semibold text-gray-900">
                        {{ selectedProduct.name }}
                    </h3>
                    <p class="text-sm text-gray-600">
                        {{
                            selectedProduct.description ||
                            "No description available"
                        }}
                    </p>
                    <div class="mt-2 flex flex-wrap items-center gap-2">
                        <a-tag color="blue" class="text-xs">{{
                            selectedProduct.SKU
                        }}</a-tag>
                        <a-tag
                            v-if="selectedProduct.track_inventory"
                            color="green"
                            class="text-xs"
                            >Inventory Tracked</a-tag
                        >
                        <a-tag v-else color="orange" class="text-xs"
                            >No Inventory Tracking</a-tag
                        >
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
                <div class="rounded-lg bg-gray-50 p-4">
                    <h4 class="mb-3 font-semibold text-gray-900">
                        Pricing Information
                    </h4>
                    <div class="space-y-2">
                        <div class="flex justify-between gap-2">
                            <span class="text-sm text-gray-600"
                                >Selling Price:</span
                            >
                            <span class="font-semibold text-green-600">{{
                                formatCurrency(selectedProduct.price)
                            }}</span>
                        </div>
                        <div class="flex justify-between gap-2">
                            <span class="text-sm text-gray-600"
                                >Cost Price:</span
                            >
                            <span class="font-semibold text-blue-600">{{
                                formatCurrency(selectedProduct.cost || 0)
                            }}</span>
                        </div>
                        <div class="flex justify-between gap-2">
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

                <div class="rounded-lg bg-gray-50 p-4">
                    <h4 class="mb-3 font-semibold text-gray-900">
                        Category & Type
                    </h4>
                    <div class="space-y-2">
                        <div class="flex justify-between gap-2">
                            <span class="text-sm text-gray-600">Category:</span>
                            <span class="text-right font-semibold">{{
                                selectedProduct.category?.name ||
                                "Uncategorized"
                            }}</span>
                        </div>
                        <div class="flex justify-between gap-2">
                            <span class="text-sm text-gray-600">Sold By:</span>
                            <span class="font-semibold">{{
                                selectedProduct.sold_type || "N/A"
                            }}</span>
                        </div>
                        <div class="flex justify-between gap-2">
                            <span class="text-sm text-gray-600">Domain:</span>
                            <span class="font-semibold">{{
                                selectedProduct.domain || "N/A"
                            }}</span>
                        </div>
                        <div
                            v-if="
                                props.isGlobalView &&
                                (selectedProduct.locations?.length || 0) > 0
                            "
                            class="flex justify-between gap-2"
                        >
                            <span class="text-sm text-gray-600"
                                >Locations:</span
                            >
                            <div class="text-right">
                                <span class="font-semibold text-blue-600">
                                    {{ selectedProduct.locations.length }}
                                    location(s)
                                </span>
                                <div class="mt-1 text-xs text-gray-500">
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
                        <div
                            v-else-if="!props.isGlobalView && currentLocation"
                            class="flex justify-between gap-2"
                        >
                            <span class="text-sm text-gray-600">Location:</span>
                            <span class="font-semibold text-blue-600">{{
                                currentLocation.name || "Unknown Location"
                            }}</span>
                        </div>
                        <div v-else class="flex justify-between gap-2">
                            <span class="text-sm text-gray-600">Location:</span>
                            <span class="text-xs text-gray-500"
                                >No location data available</span
                            >
                        </div>
                    </div>
                </div>

                <div
                    v-if="selectedProduct.track_inventory"
                    class="rounded-lg bg-gray-50 p-4"
                >
                    <h4 class="mb-3 font-semibold text-gray-900">
                        Inventory Settings
                    </h4>
                    <div class="space-y-2">
                        <div class="flex justify-between gap-2">
                            <span class="text-sm text-gray-600"
                                >Reorder Level:</span
                            >
                            <span class="font-semibold">{{
                                selectedProduct.reorder_level || "Not set"
                            }}</span>
                        </div>
                        <div class="flex justify-between gap-2">
                            <span class="text-sm text-gray-600"
                                >Max Stock Level:</span
                            >
                            <span class="font-semibold">{{
                                selectedProduct.max_stock_level || "Not set"
                            }}</span>
                        </div>
                        <div class="flex justify-between gap-2">
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

                <div class="rounded-lg bg-gray-50 p-4">
                    <h4 class="mb-3 font-semibold text-gray-900">
                        Product Metadata
                    </h4>
                    <div class="space-y-2">
                        <div class="flex justify-between gap-2">
                            <span class="text-sm text-gray-600">Created:</span>
                            <span class="font-semibold">{{
                                formatDate(selectedProduct.created_at)
                            }}</span>
                        </div>
                        <div class="flex justify-between gap-2">
                            <span class="text-sm text-gray-600"
                                >Last Updated:</span
                            >
                            <span class="font-semibold">{{
                                formatDate(selectedProduct.updated_at)
                            }}</span>
                        </div>
                        <div class="flex justify-between gap-2">
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

            <div
                v-if="selectedProduct.description"
                class="rounded-lg bg-blue-50 p-4"
            >
                <h4 class="mb-2 font-semibold text-gray-900">Description</h4>
                <p class="text-sm text-gray-700">
                    {{ selectedProduct.description }}
                </p>
            </div>
        </div>
    </a-modal>
</template>
