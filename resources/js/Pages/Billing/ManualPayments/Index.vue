<script setup>
import { computed, ref, watch } from "vue";
import { Head, router, usePage } from "@inertiajs/vue3";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import ContentHeader from "@/Components/ContentHeader.vue";
import ContentLayout from "@/Components/ContentLayout.vue";
import IconTooltipButton from "@/Components/buttons/IconTooltip.vue";
import { IconCheck, IconBan } from "@tabler/icons-vue";
import { message } from "ant-design-vue";

const props = defineProps({
    requests: { type: Object, required: true },
    filters: {
        type: Object,
        default: () => ({ status: "pending", domain: "" }),
    },
});

const page = usePage();
const domainFilter = ref(props.filters.domain || "");

watch(
    () => page.props.flash?.success,
    (msg) => {
        if (msg) {
            message.success(msg);
        }
    },
    { immediate: true },
);

const pagination = computed(() => ({
    current: props.requests.current_page ?? 1,
    pageSize: props.requests.per_page ?? 25,
    total: props.requests.total ?? 0,
    showSizeChanger: false,
}));

function reload(extra = {}) {
    router.get(window.route("billing.manual-payments.index"), extra, {
        preserveState: true,
        preserveScroll: true,
    });
}

function onStatusChange(status) {
    reload({
        status,
        domain: domainFilter.value || undefined,
        page: 1,
    });
}

function applyDomainFilter() {
    reload({
        status: props.filters.status,
        domain: domainFilter.value || undefined,
        page: 1,
    });
}

function handleTableChange(pag) {
    reload({
        status: props.filters.status,
        domain: domainFilter.value || undefined,
        page: pag?.current || 1,
    });
}

const columns = [
    { title: "Domain", key: "domain", dataIndex: "domain" },
    { title: "Plan", key: "tier", dataIndex: "tier" },
    { title: "Amount", key: "amount" },
    { title: "Reference", key: "gcash_reference" },
    { title: "Submitted by", key: "author" },
    { title: "Submitted", key: "created_at" },
    { title: "Status", key: "status" },
    { title: "", key: "actions", width: 180 },
];

const actionModalOpen = ref(false);
const actionSubmitting = ref(false);
const pendingAction = ref("approve");
const selectedRecord = ref(null);
const reviewerNote = ref("");

function openActionModal(record, action) {
    selectedRecord.value = record;
    pendingAction.value = action;
    reviewerNote.value = "";
    actionModalOpen.value = true;
}

function closeActionModal() {
    actionModalOpen.value = false;
    selectedRecord.value = null;
    actionSubmitting.value = false;
}

function submitAction() {
    if (!selectedRecord.value) {
        return;
    }
    const routeKey =
        pendingAction.value === "approve"
            ? "billing.manual-payments.approve"
            : "billing.manual-payments.reject";
    actionSubmitting.value = true;
    router.post(
        window.route(routeKey, {
            manual_payment_request: selectedRecord.value.id,
        }),
        { reviewer_note: reviewerNote.value || null },
        {
            preserveScroll: true,
            onSuccess: () => {
                closeActionModal();
            },
            onFinish: () => {
                actionSubmitting.value = false;
            },
        },
    );
}
</script>

<template>
    <AuthenticatedLayout>
        <Head title="Manual GCash payments" />
        <ContentHeader class="mb-8" title="Manual servicing payments" />
        <ContentLayout title="Verify GCash reference numbers">
            <template #filters>
                <span class="text-sm text-gray-600 mr-2">Status</span>
                <a-select
                    :value="filters.status"
                    style="width: 160px"
                    @change="onStatusChange"
                >
                    <a-select-option value="pending">Pending</a-select-option>
                    <a-select-option value="approved">Approved</a-select-option>
                    <a-select-option value="rejected">Rejected</a-select-option>
                    <a-select-option value="all">All</a-select-option>
                </a-select>
                <a-input-search
                    v-model:value="domainFilter"
                    placeholder="Domain slug…"
                    class="max-w-[200px] ml-3"
                    @search="applyDomainFilter"
                />
                <a-button class="ml-2" type="default" @click="applyDomainFilter">
                    Filter
                </a-button>
            </template>

            <template #table>
                <a-table
                    :columns="columns"
                    :data-source="requests.data"
                    :pagination="pagination"
                    row-key="id"
                    @change="handleTableChange"
                >
                    <template #bodyCell="{ column, record }">
                        <template v-if="column.key === 'domain'">
                            {{ record.domain || "—" }}
                        </template>
                        <template v-else-if="column.key === 'tier'">
                            {{ record.service_tier?.name ?? "—" }}
                        </template>
                        <template v-else-if="column.key === 'amount'">
                            ₱{{ Number(record.amount).toFixed(2) }}
                        </template>
                        <template v-else-if="column.key === 'gcash_reference'">
                            <span class="font-mono text-sm">{{
                                record.gcash_reference ??
                                record.gcashReference ??
                                "—"
                            }}</span>
                        </template>
                        <template v-else-if="column.key === 'author'">
                            {{ record.submitted_by_user?.name ?? "—" }}
                        </template>
                        <template v-else-if="column.key === 'created_at'">
                            {{
                                record.created_at
                                    ? new Date(record.created_at).toLocaleString()
                                    : "—"
                            }}
                        </template>
                        <template v-else-if="column.key === 'status'">
                            <a-tag
                                :color="
                                    record.status === 'approved'
                                        ? 'green'
                                        : record.status === 'rejected'
                                          ? 'red'
                                          : 'blue'
                                "
                            >
                                {{ record.status }}
                            </a-tag>
                        </template>
                        <template v-else-if="column.key === 'actions'">
                            <div
                                v-if="record.status === 'pending'"
                                class="flex items-center gap-2"
                            >
                                <IconTooltipButton
                                    hover="group-hover:bg-green-500"
                                    name="Approve"
                                    @click="openActionModal(record, 'approve')"
                                >
                                    <IconCheck size="20" class="mx-auto" />
                                </IconTooltipButton>
                                <IconTooltipButton
                                    hover="group-hover:bg-red-500"
                                    name="Reject"
                                    @click="openActionModal(record, 'reject')"
                                >
                                    <IconBan size="20" class="mx-auto" />
                                </IconTooltipButton>
                            </div>
                            <div v-else class="text-xs text-gray-500 max-w-[180px]">
                                <template v-if="record.reviewed_by_user?.name">
                                    By {{ record.reviewed_by_user.name }}
                                    <template v-if="record.reviewed_at">
                                        ·
                                        {{
                                            new Date(
                                                record.reviewed_at,
                                            ).toLocaleString()
                                        }}
                                    </template>
                                </template>
                                <template v-if="record.reviewer_note">
                                    <div class="mt-1">{{ record.reviewer_note }}</div>
                                </template>
                            </div>
                        </template>
                    </template>
                </a-table>
            </template>
        </ContentLayout>

        <a-modal
            v-model:visible="actionModalOpen"
            :title="
                pendingAction === 'approve' ? 'Approve payment' : 'Reject payment'
            "
            :ok-text="pendingAction === 'approve' ? 'Approve' : 'Reject'"
            :ok-button-props="{
                danger: pendingAction === 'reject',
                type: pendingAction === 'approve' ? 'primary' : undefined,
            }"
            :confirm-loading="actionSubmitting"
            @ok="submitAction"
        >
            <p v-if="selectedRecord" class="text-sm text-gray-700 mb-2">
                ₱{{ Number(selectedRecord.amount).toFixed(2) }} —
                Ref {{ selectedRecord.gcash_reference }} —
                {{ selectedRecord.domain }}
            </p>
            <a-textarea
                v-model:value="reviewerNote"
                :rows="3"
                placeholder="Optional note (visible in history)"
            />
        </a-modal>
    </AuthenticatedLayout>
</template>
