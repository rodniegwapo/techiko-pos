<script setup>
import { computed } from "vue";
import { usePage } from "@inertiajs/vue3";
import { useMediaQuery } from "@vueuse/core";
import IconTooltipButton from "@/Components/buttons/IconTooltip.vue";
import {
    IconTrash,
    IconEdit,
    IconEye,
    IconWorld,
} from "@tabler/icons-vue";
import { useHelpers } from "@/Composables/useHelpers";
import { useGlobalVariables } from "@/Composables/useGlobalVariable";
import { usePermissionsV2 } from "@/Composables/usePermissionV2";
import dayjs from "dayjs";

const emit = defineEmits(["handleTableChange", "selectedDiscount"]);
const { confirmDelete, formattedTotal, formattedPercent } = useHelpers();
const { formData, openModal, isEdit, spinning, openViewModal } =
    useGlobalVariables();
const { hasPermission } = usePermissionsV2();
const page = usePage();

/** Only offer actions the server will allow for this user. */
const canEdit = computed(() => hasPermission("products.discounts.update"));
const canDelete = computed(() => hasPermission("products.discounts.destroy"));

const isMdUp = useMediaQuery("(min-width: 768px)");

const props = defineProps({
    products: { type: Object, required: true },
    pagination: { type: Object, required: false, default: () => ({}) },
    isGlobalView: { type: Boolean, default: false },
});

const showSuperUserDomain = computed(
    () =>
        page.props.auth?.user?.data?.is_super_user && props.isGlobalView,
);

const columns = computed(() => {
    const baseColumns = [
        {
            title: "Discount Name",
            dataIndex: "name",
            key: "name",
            align: "left",
        },
        {
            title: "Type",
            dataIndex: "type",
            key: "type",
            align: "left",
        },
        {
            title: "Value",
            dataIndex: "value",
            key: "value",
            align: "left",
        },
        {
            title: "Start Date",
            dataIndex: "start_date",
            key: "start_date",
            align: "left",
        },
        {
            title: "End Date",
            dataIndex: "end_date",
            key: "end_date",
            align: "left",
        },
    ];

    if (showSuperUserDomain.value) {
        baseColumns.splice(1, 0, {
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

const handleTableChange = (event) => {
    emit("handleTableChange", event);
};

const handleDelete = (record) => {
    confirmDelete(
        "products.discounts.destroy",
        { discount: record.id },
        "Do you want to delete this item ?",
    );
};

const handleClickEdit = (record) => {
    formData.value = {
        ...record,
        // 'percent' is the older spelling; the form's radio uses 'percentage'.
        type: isAmount(record) ? "amount" : "percentage",
        // dayjs(null) is an invalid date that the form would send back as "Invalid Date".
        start_date: record.start_date ? dayjs(record.start_date) : null,
        end_date: record.end_date ? dayjs(record.end_date) : null,
    };
    isEdit.value = true;
    openModal.value = true;
};

/** Types are stored lowercase ('amount', 'percentage' or the older 'percent'). */
function isAmount(record) {
    return String(record.type).toLowerCase() === "amount";
}

function formatDateCell(value) {
    return value ? dayjs(value).format("MMM DD YYYY hh:mm:ss a") : "—";
}

const handleViewDetail = (record) => {
    openViewModal.value = true;
    emit("selectedDiscount", record);
};

function discountValueLabel(record) {
    if (isAmount(record)) {
        return formattedTotal(record.value);
    }
    return formattedPercent(record.value);
}

function discountTypeLabel(record) {
    return isAmount(record) ? "Amount" : "Percentage";
}

function formatMobileDate(iso) {
    if (!iso) return "—";
    return dayjs(iso).format("MMM DD, YYYY h:mm A");
}

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
        @change="handleTableChange"
        :pagination="pagination"
        :loading="spinning"
    >
        <template #bodyCell="{ column, record }">
            <template v-if="column.key == 'domain'">
                <div class="flex items-center gap-2">
                    <IconWorld size="16" class="text-blue-500" />
                    <span class="text-sm font-medium">{{
                        record.domain || "N/A"
                    }}</span>
                </div>
            </template>

            <template v-if="column.key == 'value'">
                {{ discountValueLabel(record) }}
            </template>

            <template v-if="column.key == 'type'">
                {{ discountTypeLabel(record) }}
            </template>

            <template v-if="column.key == 'start_date'">
                {{ formatDateCell(record.start_date) }}
            </template>
            <template v-if="column.key == 'end_date'">
                {{ formatDateCell(record.end_date) }}
            </template>

            <template v-if="column.key == 'action'">
                <div class="flex items-center gap-2">
                    <icon-tooltip-button
                        v-if="canEdit"
                        hover="group-hover:bg-blue-500"
                        name="Edit Discount"
                        @click="handleClickEdit(record)"
                    >
                        <IconEdit size="20" class="mx-auto" />
                    </icon-tooltip-button>

                    <icon-tooltip-button
                        v-if="canDelete"
                        hover="group-hover:bg-red-500"
                        name="Delete Discount"
                        @click="handleDelete(record)"
                    >
                        <IconTrash size="20" class="mx-auto" />
                    </icon-tooltip-button>

                    <icon-tooltip-button
                        hover="group-hover:bg-[#3379B4]"
                        name="View Discount"
                        @click="handleViewDetail(record)"
                    >
                        <IconEye size="20" class="mx-auto" />
                    </icon-tooltip-button>
                </div>
            </template>
        </template>
    </a-table>

    <div v-else class="px-2 py-2 md:px-0">
        <a-spin :spinning="spinning">
            <div
                v-if="!products?.length"
                class="py-12 text-center text-sm text-gray-500"
            >
                No discounts found.
            </div>
            <div v-else class="flex flex-col gap-3">
                <div
                    v-for="record in products"
                    :key="record.id"
                    class="overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm"
                >
                    <div class="px-4 py-3">
                        <div class="truncate text-base font-semibold text-gray-900">
                            {{ record.name }}
                        </div>
                        <div class="mt-2 flex flex-wrap items-center gap-2">
                            <a-tag color="blue" class="m-0 text-xs">
                                {{ discountTypeLabel(record) }}
                            </a-tag>
                        </div>
                    </div>

                    <div class="mx-4 mb-3 rounded-lg bg-gray-50 p-3">
                        <div
                            class="grid grid-cols-[auto_1fr] gap-x-3 gap-y-2 text-sm"
                        >
                            <span class="text-gray-500">Value</span>
                            <span
                                class="text-right font-semibold text-green-600"
                            >
                                {{ discountValueLabel(record) }}
                            </span>
                            <span class="text-gray-500">Start</span>
                            <span
                                class="text-right font-medium text-gray-900"
                            >
                                {{ formatMobileDate(record.start_date) }}
                            </span>
                            <span class="text-gray-500">End</span>
                            <span
                                class="text-right font-medium text-gray-900"
                            >
                                {{ formatMobileDate(record.end_date) }}
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
                                @click="handleViewDetail(record)"
                            >
                                <template #icon>
                                    <IconEye size="18" />
                                </template>
                                View discount
                            </a-button>
                            <div
                                v-if="canEdit || canDelete"
                                class="grid grid-cols-2 gap-2"
                            >
                                <a-button
                                    v-if="canEdit"
                                    class="flex items-center justify-center gap-2"
                                    @click="handleClickEdit(record)"
                                >
                                    <template #icon>
                                        <IconEdit size="18" />
                                    </template>
                                    Edit
                                </a-button>
                                <a-button
                                    v-if="canDelete"
                                    class="flex items-center justify-center gap-2"
                                    danger
                                    @click="handleDelete(record)"
                                >
                                    <template #icon>
                                        <IconTrash size="18" />
                                    </template>
                                    Delete
                                </a-button>
                            </div>
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
