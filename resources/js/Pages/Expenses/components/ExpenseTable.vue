<script setup>
import { useMediaQuery } from "@vueuse/core";
import dayjs from "dayjs";
import { IconEdit, IconTrash, IconPaperclip } from "@tabler/icons-vue";
import IconTooltipButton from "@/Components/buttons/IconTooltip.vue";
import { useDomainRoutes } from "@/Composables/useDomainRoutes";
import { useGlobalVariables } from "@/Composables/useGlobalVariable";
import { useHelpers } from "@/Composables/useHelpers";
import { usePermissionsV2 } from "@/Composables/usePermissionV2";
import { paymentMethodLabel } from "../paymentMethods";

const props = defineProps({
    expenses: { type: Array, default: () => [] },
    pagination: { type: Object, default: () => ({}) },
});
const emit = defineEmits(["handleTableChange", "edit"]);

const { getRoute } = useDomainRoutes();
const { spinning } = useGlobalVariables();
const { formattedTotal, confirmDelete } = useHelpers();
const { hasPermission } = usePermissionsV2();
const isMdUp = useMediaQuery("(min-width: 768px)");

const columns = [
    { title: "Date", dataIndex: "expense_date", key: "date", width: 120 },
    { title: "Description", dataIndex: "description", key: "description" },
    { title: "Category", dataIndex: "category_name", key: "category" },
    { title: "Store", dataIndex: "location_name", key: "store" },
    { title: "Paid from", dataIndex: "payment_method", key: "payment" },
    { title: "Amount", dataIndex: "amount", key: "amount", align: "right" },
    { title: "Action", key: "action", align: "center", width: "1%" },
];

const receiptUrl = (record) => getRoute("expenses.receipt", { expense: record.id });
const openReceipt = (record) => window.open(receiptUrl(record), "_blank", "noopener");

const handleDelete = (record) =>
    confirmDelete(
        "expenses.destroy",
        { expense: record.id },
        record.in_wallet_ledger
            ? "Delete this expense? Its cash-out entry in the Wallet ledger will be removed too."
            : "Delete this expense?",
    ).catch(() => {});

const onMobilePaginationChange = (pageNum) =>
    emit("handleTableChange", { current: pageNum, pageSize: props.pagination?.pageSize ?? 20 });
</script>

<template>
    <a-table
        v-if="isMdUp"
        class="ant-table-striped"
        :columns="columns"
        :data-source="expenses"
        :row-key="(r) => r.id"
        :row-class-name="(_, index) => (index % 2 === 1 ? 'bg-gray-50 group' : 'group')"
        :pagination="pagination"
        :loading="spinning"
        data-testid="expenses-table"
        @change="(p) => emit('handleTableChange', p)"
    >
        <template #bodyCell="{ column, record }">
            <template v-if="column.key === 'date'">
                {{ dayjs(record.expense_date).format("MMM D, YYYY") }}
            </template>
            <template v-else-if="column.key === 'description'">
                <div class="font-medium">
                    {{ record.description }}
                    <a-tag v-if="record.is_recurring" color="purple" class="ml-1">Recurring</a-tag>
                </div>
                <div v-if="record.payee || record.reference_no" class="text-xs text-gray-500">
                    {{ [record.payee, record.reference_no].filter(Boolean).join(" · ") }}
                </div>
            </template>
            <template v-else-if="column.key === 'store'">
                <span v-if="record.location_name">{{ record.location_name }}</span>
                <a-tag v-else>Business-wide</a-tag>
            </template>
            <template v-else-if="column.key === 'payment'">
                {{ paymentMethodLabel(record.payment_method) }}
                <a-tooltip v-if="record.in_wallet_ledger" title="Also recorded as cash out in the Wallet ledger">
                    <a-tag color="blue" class="ml-1">Wallet</a-tag>
                </a-tooltip>
            </template>
            <template v-else-if="column.key === 'amount'">
                <span class="font-medium">{{ formattedTotal(record.amount) }}</span>
            </template>
            <template v-else-if="column.key === 'action'">
                <div class="flex items-center gap-2">
                    <icon-tooltip-button
                        v-if="record.has_receipt"
                        hover="group-hover:bg-green-600"
                        name="View receipt"
                        @click="openReceipt(record)"
                    >
                        <IconPaperclip size="20" class="mx-auto" />
                    </icon-tooltip-button>
                    <icon-tooltip-button
                        v-if="record.can_edit && hasPermission('expenses.update')"
                        hover="group-hover:bg-blue-500"
                        name="Edit Expense"
                        @click="emit('edit', record)"
                    >
                        <IconEdit size="20" class="mx-auto" />
                    </icon-tooltip-button>
                    <icon-tooltip-button
                        v-if="record.can_edit && hasPermission('expenses.destroy')"
                        hover="group-hover:bg-red-500"
                        name="Delete Expense"
                        @click="handleDelete(record)"
                    >
                        <IconTrash size="20" class="mx-auto" />
                    </icon-tooltip-button>
                </div>
            </template>
        </template>
    </a-table>

    <div v-else class="px-2 py-2 md:px-0">
        <a-spin :spinning="spinning">
            <div v-if="!expenses.length" class="py-12 text-center text-sm text-gray-500">No expenses found.</div>
            <div v-else class="flex flex-col gap-3">
                <div
                    v-for="record in expenses"
                    :key="record.id"
                    class="overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm"
                >
                    <div class="flex items-start justify-between gap-3 px-4 py-3">
                        <div class="min-w-0">
                            <div class="truncate text-base font-semibold text-gray-900">{{ record.description }}</div>
                            <div class="mt-0.5 text-xs text-gray-500">
                                {{ dayjs(record.expense_date).format("MMM D, YYYY") }} · {{ record.category_name }}
                            </div>
                        </div>
                        <div class="shrink-0 text-base font-semibold text-gray-900">{{ formattedTotal(record.amount) }}</div>
                    </div>
                    <div class="mx-4 mb-3 grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 rounded-lg bg-gray-50 p-3 text-sm">
                        <span class="text-gray-500">Store</span>
                        <span class="truncate text-right font-medium">{{ record.location_name || "Business-wide" }}</span>
                        <span class="text-gray-500">Paid from</span>
                        <span class="text-right font-medium">
                            {{ paymentMethodLabel(record.payment_method) }}
                            <a-tag v-if="record.in_wallet_ledger" color="blue" class="ml-1 mr-0">Wallet</a-tag>
                        </span>
                        <template v-if="record.payee">
                            <span class="text-gray-500">Payee</span>
                            <span class="truncate text-right font-medium">{{ record.payee }}</span>
                        </template>
                    </div>
                    <div
                        v-if="record.has_receipt || (record.can_edit && (hasPermission('expenses.update') || hasPermission('expenses.destroy')))"
                        class="border-t border-gray-100 px-4 py-3"
                    >
                        <div class="grid grid-cols-2 gap-2">
                            <a-button v-if="record.has_receipt" class="flex items-center justify-center gap-2" @click="openReceipt(record)">
                                <template #icon><IconPaperclip size="18" /></template>
                                Receipt
                            </a-button>
                            <a-button
                                v-if="record.can_edit && hasPermission('expenses.update')"
                                class="flex items-center justify-center gap-2"
                                @click="emit('edit', record)"
                            >
                                <template #icon><IconEdit size="18" /></template>
                                Edit
                            </a-button>
                            <a-button
                                v-if="record.can_edit && hasPermission('expenses.destroy')"
                                class="flex items-center justify-center gap-2"
                                danger
                                @click="handleDelete(record)"
                            >
                                <template #icon><IconTrash size="18" /></template>
                                Delete
                            </a-button>
                        </div>
                    </div>
                </div>
            </div>
            <a-pagination
                v-if="pagination?.total && pagination.total > (pagination.pageSize ?? 20)"
                class="mt-4 justify-center pt-2"
                show-less-items
                :current="pagination.current"
                :page-size="pagination.pageSize"
                :total="pagination.total"
                @change="onMobilePaginationChange"
            />
        </a-spin>
    </div>
</template>
