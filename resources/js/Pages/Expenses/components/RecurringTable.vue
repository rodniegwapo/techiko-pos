<script setup>
import { router } from "@inertiajs/vue3";
import { notification } from "ant-design-vue";
import { useMediaQuery } from "@vueuse/core";
import dayjs from "dayjs";
import { PlusSquareOutlined } from "@ant-design/icons-vue";
import { IconEdit, IconTrash, IconPlayerPause, IconPlayerPlay } from "@tabler/icons-vue";
import IconTooltipButton from "@/Components/buttons/IconTooltip.vue";
import { useDomainRoutes } from "@/Composables/useDomainRoutes";
import { useHelpers } from "@/Composables/useHelpers";
import { paymentMethodLabel } from "../paymentMethods";

defineProps({
    items: { type: Array, default: () => [] },
    canCreate: { type: Boolean, default: false },
    canUpdate: { type: Boolean, default: false },
    canDelete: { type: Boolean, default: false },
});
const emit = defineEmits(["create", "edit"]);

const { getRoute } = useDomainRoutes();
const { formattedTotal, confirmDelete } = useHelpers();
const isMdUp = useMediaQuery("(min-width: 768px)");

const columns = [
    { title: "Description", dataIndex: "description", key: "description" },
    { title: "Category", dataIndex: "category_name", key: "category" },
    { title: "Store", dataIndex: "location_name", key: "store" },
    { title: "Schedule", dataIndex: "schedule_label", key: "schedule" },
    { title: "Next", dataIndex: "next_run_date", key: "next" },
    { title: "Amount", dataIndex: "amount", key: "amount", align: "right" },
    { title: "Action", key: "action", align: "center", width: "1%" },
];

const toggle = (record) =>
    router.put(
        getRoute("expenses.recurring.update", { recurring: record.id }),
        { is_active: !record.is_active },
        {
            preserveScroll: true,
            onSuccess: (page) => {
                if (page.props.flash?.error) {
                    notification.error({ message: "Error", description: page.props.flash.error });
                } else {
                    notification.success({ message: "Success", description: page.props.flash?.success });
                }
            },
        },
    );

const remove = (record) =>
    confirmDelete(
        "expenses.recurring.destroy",
        { recurring: record.id },
        "Stop and delete this recurring expense? Expenses already added are kept.",
    ).catch(() => {});

const nextLabel = (record) => (record.is_active ? dayjs(record.next_run_date).format("MMM D, YYYY") : null);
</script>

<template>
    <div class="space-y-3">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <p class="mb-0 text-sm text-gray-500">
                Regular costs like rent or wages. Each one is added to Expenses automatically on its date.
            </p>
            <a-button
                v-if="canCreate"
                type="primary"
                class="flex w-full items-center justify-center border border-green-500 bg-white text-green-500 md:inline-flex md:w-auto"
                data-testid="recurring-create"
                @click="emit('create')"
            >
                <template #icon>
                    <PlusSquareOutlined />
                </template>
                Add Recurring Expense
            </a-button>
        </div>

        <a-table
            v-if="isMdUp"
            class="ant-table-striped"
            :columns="columns"
            :data-source="items"
            :pagination="false"
            :row-key="(r) => r.id"
            :row-class-name="(_, index) => (index % 2 === 1 ? 'bg-gray-50 group' : 'group')"
            data-testid="recurring-table"
        >
            <template #bodyCell="{ column, record }">
                <template v-if="column.key === 'description'">
                    <div class="font-medium" :class="{ 'text-gray-400': !record.is_active }">{{ record.description }}</div>
                    <div class="text-xs text-gray-500">{{ paymentMethodLabel(record.payment_method) }}</div>
                </template>
                <template v-else-if="column.key === 'store'">
                    <span v-if="record.location_name">{{ record.location_name }}</span>
                    <a-tag v-else>Business-wide</a-tag>
                </template>
                <template v-else-if="column.key === 'next'">
                    <a-tag v-if="!record.is_active">Paused</a-tag>
                    <span v-else>{{ nextLabel(record) }}</span>
                    <div v-if="record.end_date" class="text-xs text-gray-500">
                        until {{ dayjs(record.end_date).format("MMM D, YYYY") }}
                    </div>
                </template>
                <template v-else-if="column.key === 'amount'">
                    <span class="font-medium">{{ formattedTotal(record.amount) }}</span>
                </template>
                <template v-else-if="column.key === 'action'">
                    <div v-if="record.can_edit" class="flex items-center gap-2">
                        <icon-tooltip-button
                            v-if="canUpdate"
                            hover="group-hover:bg-blue-500"
                            name="Edit Recurring Expense"
                            @click="emit('edit', record)"
                        >
                            <IconEdit size="20" class="mx-auto" />
                        </icon-tooltip-button>
                        <icon-tooltip-button
                            v-if="canUpdate"
                            hover="group-hover:bg-amber-500"
                            :name="record.is_active ? 'Pause' : 'Resume'"
                            @click="toggle(record)"
                        >
                            <IconPlayerPause v-if="record.is_active" size="20" class="mx-auto" />
                            <IconPlayerPlay v-else size="20" class="mx-auto" />
                        </icon-tooltip-button>
                        <icon-tooltip-button
                            v-if="canDelete"
                            hover="group-hover:bg-red-500"
                            name="Delete Recurring Expense"
                            @click="remove(record)"
                        >
                            <IconTrash size="20" class="mx-auto" />
                        </icon-tooltip-button>
                    </div>
                </template>
            </template>
        </a-table>

        <div v-else class="flex flex-col gap-3">
            <div v-if="!items.length" class="py-12 text-center text-sm text-gray-500">No recurring expenses yet.</div>
            <div
                v-for="record in items"
                :key="record.id"
                class="overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm"
            >
                <div class="flex items-start justify-between gap-3 px-4 py-3">
                    <div class="min-w-0">
                        <div class="truncate text-base font-semibold" :class="record.is_active ? 'text-gray-900' : 'text-gray-400'">
                            {{ record.description }}
                        </div>
                        <div class="mt-0.5 text-xs text-gray-500">{{ record.schedule_label }}</div>
                    </div>
                    <div class="shrink-0 text-base font-semibold text-gray-900">{{ formattedTotal(record.amount) }}</div>
                </div>
                <div class="mx-4 mb-3 grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 rounded-lg bg-gray-50 p-3 text-sm">
                    <span class="text-gray-500">Next</span>
                    <span class="text-right font-medium">
                        <a-tag v-if="!record.is_active" class="mr-0">Paused</a-tag>
                        <template v-else>{{ nextLabel(record) }}</template>
                    </span>
                    <span class="text-gray-500">Store</span>
                    <span class="truncate text-right font-medium">{{ record.location_name || "Business-wide" }}</span>
                    <span class="text-gray-500">Category</span>
                    <span class="truncate text-right font-medium">{{ record.category_name }}</span>
                </div>
                <div v-if="record.can_edit && (canUpdate || canDelete)" class="border-t border-gray-100 px-4 py-3">
                    <div class="grid grid-cols-3 gap-2">
                        <a-button v-if="canUpdate" class="flex items-center justify-center gap-1" @click="emit('edit', record)">
                            <template #icon><IconEdit size="18" /></template>
                            Edit
                        </a-button>
                        <a-button v-if="canUpdate" class="flex items-center justify-center gap-1" @click="toggle(record)">
                            <template #icon>
                                <IconPlayerPause v-if="record.is_active" size="18" />
                                <IconPlayerPlay v-else size="18" />
                            </template>
                            {{ record.is_active ? "Pause" : "Resume" }}
                        </a-button>
                        <a-button v-if="canDelete" class="flex items-center justify-center gap-1" danger @click="remove(record)">
                            <template #icon><IconTrash size="18" /></template>
                            Delete
                        </a-button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
