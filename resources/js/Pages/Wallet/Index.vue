<script setup>
import { ref, computed } from "vue";
import { Head, router } from "@inertiajs/vue3";
import { IconPlus, IconReportMoney, IconEdit, IconTrash } from "@tabler/icons-vue";
import axios from "axios";
import { notification } from "ant-design-vue";

import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import ContentHeader from "@/Components/ContentHeader.vue";
import ContentLayout from "@/Components/ContentLayout.vue";
import IconTooltipButton from "@/Components/buttons/IconTooltip.vue";
import { useDomainRoutes } from "@/Composables/useDomainRoutes";
import { usePermissionsV2 } from "@/Composables/usePermissionV2";
import { useHelpers } from "@/Composables/useHelpers";

const { getRoute } = useDomainRoutes();
const { hasPermission } = usePermissionsV2();
const { formattedTotal } = useHelpers();

function firstValidationMessage(err) {
    const errors = err?.response?.data?.errors;
    if (errors && typeof errors === "object") {
        const first = Object.values(errors)[0];
        if (Array.isArray(first) && first.length) return first[0];
    }
    return err?.response?.data?.message || err?.message || null;
}

const props = defineProps({
    cardTypes: {
        type: Array,
        default: () => [],
    },
});

const rows = computed(() => props.cardTypes ?? []);

const modalOpen = ref(false);
const editing = ref(null);
const formName = ref("");
const formActive = ref(true);
const saving = ref(false);

function openCreate() {
    editing.value = null;
    formName.value = "";
    formActive.value = true;
    modalOpen.value = true;
}

function openEdit(row) {
    editing.value = row;
    formName.value = row.name;
    formActive.value = !!row.is_active;
    modalOpen.value = true;
}

function closeModal() {
    modalOpen.value = false;
    editing.value = null;
}

async function save() {
    const name = String(formName.value || "").trim();
    if (!name) {
        notification.warning({ message: "Name is required." });
        return;
    }
    saving.value = true;
    try {
        if (editing.value?.id) {
            await axios.put(
                getRoute("payment-card-types.update", {
                    paymentCardType: editing.value.id,
                }),
                {
                    name,
                    is_active: formActive.value,
                },
            );
            notification.success({ message: "Card type updated." });
        } else {
            await axios.post(getRoute("payment-card-types.store"), {
                name,
                sort_order: 0,
            });
            notification.success({ message: "Card type created." });
        }
        closeModal();
        router.reload({ only: ["cardTypes"] });
    } catch (e) {
        const msg =
            firstValidationMessage(e) ||
            "Could not save card type.";
        notification.error({ message: msg });
    } finally {
        saving.value = false;
    }
}

const deletingId = ref(null);

async function remove(row) {
    deletingId.value = row.id;
    try {
        await axios.delete(
            getRoute("payment-card-types.destroy", {
                paymentCardType: row.id,
            }),
        );
        notification.success({ message: "Done." });
        router.reload({ only: ["cardTypes"] });
    } catch (e) {
        const msg =
            firstValidationMessage(e) ||
            "Could not remove card type.";
        notification.error({ message: msg });
    } finally {
        deletingId.value = null;
    }
}

const columns = [
    { title: "Name", dataIndex: "name", key: "name" },
    {
        title: "Status",
        key: "is_active",
        width: 100,
    },
    { title: "", key: "actions", width: 220 },
];

const moneyModalVisible = ref(false);
const moneyDetailsType = ref(null);
const moneyLoading = ref(false);
const todayTotal = ref(0);
const yesterdayTotal = ref(0);
const historyRows = ref([]);
const historyPagination = ref({
    current: 1,
    pageSize: 20,
    total: 0,
});

const moneyHistoryColumns = [
    { title: "Invoice", dataIndex: "invoice_number", key: "invoice_number", width: 130 },
    { title: "Date", key: "date", width: 200 },
    { title: "Time", key: "time", width: 120 },
    {
        title: "Amount",
        dataIndex: "grand_total",
        key: "grand_total",
        align: "right",
    },
];

function formatHistoryDate(iso) {
    if (!iso) return "—";
    try {
        return new Date(iso).toLocaleDateString(undefined, {
            weekday: "short",
            year: "numeric",
            month: "short",
            day: "numeric",
        });
    } catch {
        return "—";
    }
}

function formatHistoryTime(iso) {
    if (!iso) return "—";
    try {
        return new Date(iso).toLocaleTimeString(undefined, {
            hour: "2-digit",
            minute: "2-digit",
        });
    } catch {
        return "—";
    }
}

async function loadMoneyDetails(page = 1) {
    if (!moneyDetailsType.value?.id) return;
    moneyLoading.value = true;
    try {
        const { data } = await axios.get(
            getRoute("payment-card-types.money", {
                paymentCardType: moneyDetailsType.value.id,
            }),
            { params: { page, per_page: historyPagination.value.pageSize } },
        );
        todayTotal.value = Number(data.today_total) || 0;
        yesterdayTotal.value = Number(data.yesterday_total) || 0;
        const h = data.history;
        historyRows.value = h?.data ?? [];
        historyPagination.value = {
            current: h?.current_page ?? 1,
            pageSize: h?.per_page ?? 20,
            total: h?.total ?? 0,
        };
    } catch (e) {
        notification.error({
            message:
                firstValidationMessage(e) ||
                "Could not load money details.",
        });
    } finally {
        moneyLoading.value = false;
    }
}

function openMoneyDetails(record) {
    moneyDetailsType.value = record;
    moneyModalVisible.value = true;
    loadMoneyDetails(1);
}

function closeMoneyModal() {
    moneyModalVisible.value = false;
    moneyDetailsType.value = null;
    historyRows.value = [];
    todayTotal.value = 0;
    yesterdayTotal.value = 0;
}

function onMoneyTableChange(pag) {
    if (pag?.current) {
        loadMoneyDetails(pag.current);
    }
}
</script>

<template>
    <AuthenticatedLayout>
        <Head title="Payment wallet" />
        <ContentHeader class="mb-8" title="Payment wallet" />
        <ContentLayout title="Card payment types">
            <template #filters>
                <a-button
                    v-if="hasPermission('payment-card-types.store')"
                    type="primary"
                    class="bg-white border flex items-center border-green-500 text-green-500"
                    @click="openCreate"
                >
                    <template #icon>
                        <IconPlus class="w-4 h-4" />
                    </template>
                    Add card type
                </a-button>
            </template>

            <template #table>
                <a-table
                    :columns="columns"
                    :data-source="rows"
                    :pagination="false"
                    row-key="id"
                    :locale="{ emptyText: 'No card types yet. Add one to use Pay in Card on Sales.' }"
                >
                    <template #bodyCell="{ column, record }">
                        <template v-if="column.key === 'is_active'">
                            <a-tag :color="record.is_active ? 'green' : 'default'">
                                {{ record.is_active ? "Active" : "Inactive" }}
                            </a-tag>
                        </template>
                        <template v-else-if="column.key === 'actions'">
                            <a-space>
                                <IconTooltipButton
                                    v-if="hasPermission('payment-card-types.money')"
                                    name="View money details"
                                    hover="hover:bg-emerald-600"
                                    @click="openMoneyDetails(record)"
                                >
                                    <IconReportMoney size="20" class="mx-auto" />
                                </IconTooltipButton>
                                <IconTooltipButton
                                    v-if="hasPermission('payment-card-types.update')"
                                    name="Edit card type"
                                    hover="hover:bg-blue-500"
                                    @click="openEdit(record)"
                                >
                                    <IconEdit size="20" class="mx-auto" />
                                </IconTooltipButton>
                                <IconTooltipButton
                                    v-if="hasPermission('payment-card-types.destroy')"
                                    name="Remove card type"
                                    hover="hover:bg-red-600"
                                    :loading="deletingId === record.id"
                                    @click="remove(record)"
                                >
                                    <IconTrash size="20" class="mx-auto" />
                                </IconTooltipButton>
                            </a-space>
                        </template>
                    </template>
                </a-table>
            </template>
        </ContentLayout>

        <a-modal
            v-model:visible="modalOpen"
            :title="editing ? 'Edit card type' : 'Add card type'"
            :confirm-loading="saving"
            ok-text="Save"
            destroy-on-close
            @ok="save"
            @cancel="closeModal"
        >
            <div class="flex flex-col gap-4 pt-2">
                <div>
                    <div class="text-sm text-gray-600 mb-1">Display name</div>
                    <a-input
                        v-model:value="formName"
                        placeholder="e.g. BDO POS, Visa terminal"
                        maxlength="255"
                    />
                </div>
                <div
                    v-if="editing"
                    class="flex items-center justify-between gap-4"
                >
                    <span class="text-sm text-gray-600">Active</span>
                    <a-switch v-model:checked="formActive" />
                </div>
            </div>
        </a-modal>

        <a-modal
            v-model:visible="moneyModalVisible"
            :title="
                moneyDetailsType
                    ? `Money — ${moneyDetailsType.name}`
                    : 'Money details'
            "
            width="800px"
            :footer="null"
            destroy-on-close
            @cancel="closeMoneyModal"
        >
            <a-spin :spinning="moneyLoading">
                <div v-if="moneyDetailsType" class="space-y-4">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div
                            class="rounded-lg border border-gray-200 bg-gray-50 px-4 py-3"
                        >
                            <div class="text-xs text-gray-500 uppercase">
                                Today
                            </div>
                            <div class="text-xl font-semibold text-green-700">
                                {{ formattedTotal(todayTotal) }}
                            </div>
                        </div>
                        <div
                            class="rounded-lg border border-gray-200 bg-gray-50 px-4 py-3"
                        >
                            <div class="text-xs text-gray-500 uppercase">
                                Yesterday
                            </div>
                            <div class="text-xl font-semibold text-gray-800">
                                {{ formattedTotal(yesterdayTotal) }}
                            </div>
                        </div>
                    </div>
                    <div>
                        <div class="text-sm font-medium text-gray-700 mb-2">
                            Transaction history (paid card sales)
                        </div>
                        <a-table
                            size="small"
                            :columns="moneyHistoryColumns"
                            :data-source="historyRows"
                            :pagination="{
                                current: historyPagination.current,
                                pageSize: historyPagination.pageSize,
                                total: historyPagination.total,
                                showSizeChanger: false,
                                hideOnSinglePage: false,
                            }"
                            row-key="id"
                            :locale="{
                                emptyText: 'No transactions for this card type yet.',
                            }"
                            @change="onMoneyTableChange"
                        >
                            <template #bodyCell="{ column, record }">
                                <template v-if="column.key === 'date'">
                                    {{
                                        formatHistoryDate(
                                            record.transaction_date,
                                        )
                                    }}
                                </template>
                                <template v-else-if="column.key === 'time'">
                                    {{
                                        formatHistoryTime(
                                            record.transaction_date,
                                        )
                                    }}
                                </template>
                                <template
                                    v-else-if="column.key === 'grand_total'"
                                >
                                    {{ formattedTotal(record.grand_total) }}
                                </template>
                            </template>
                        </a-table>
                    </div>
                </div>
            </a-spin>
        </a-modal>
    </AuthenticatedLayout>
</template>
