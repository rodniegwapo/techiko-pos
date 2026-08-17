<script setup>
import { ref, computed } from "vue";
import { Head, router } from "@inertiajs/vue3";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import ContentHeader from "@/Components/ContentHeader.vue";
import ContentLayout from "@/Components/ContentLayout.vue";
import IconTooltipButton from "@/Components/buttons/IconTooltip.vue";
import { IconCheck, IconBan } from "@tabler/icons-vue";

const props = defineProps({
    suggestions: { type: Object, required: true },
    statusFilter: { type: String, default: "pending" },
});

const rejectModalVisible = ref(false);
const rejectingId = ref(null);
const rejectReason = ref("");
const rejectSubmitting = ref(false);

const statusRadio = computed({
    get: () => props.statusFilter,
    set: (v) => {
        router.get(
            window.route("catalog.shared-product-suggestions.index"),
            { status: v },
            {
                preserveState: true,
                preserveScroll: true,
                replace: true,
            },
        );
    },
});

function acceptRow(id) {
    router.post(
        window.route("catalog.shared-product-suggestions.accept", {
            shared_product_suggestion: id,
        }),
        {},
        {
            preserveScroll: true,
        },
    );
}

function openReject(id) {
    rejectingId.value = id;
    rejectReason.value = "";
    rejectModalVisible.value = true;
}

function confirmReject() {
    return new Promise((resolve, reject) => {
        rejectSubmitting.value = true;
        router.post(
            window.route("catalog.shared-product-suggestions.reject", {
                shared_product_suggestion: rejectingId.value,
            }),
            {
                rejection_reason: rejectReason.value || null,
            },
            {
                preserveScroll: true,
                onSuccess: () => {
                    rejectModalVisible.value = false;
                    resolve();
                },
                onError: () => reject(new Error("reject_failed")),
                onFinish: () => {
                    rejectSubmitting.value = false;
                },
            },
        );
    });
}

const columns = [
    { title: "Domain", key: "domain", dataIndex: "domain" },
    { title: "Barcode", key: "barcode", dataIndex: "barcode" },
    {
        title: "Suggested title",
        key: "name",
        dataIndex: "name",
    },
    { title: "Submitted by", key: "author", width: 160 },
    { title: "Status", key: "status" },
    { title: "", key: "actions", width: 120 },
];

const rows = computed(() =>
    props.suggestions.data.map((row) => ({
        ...row,
        name:
            row.snapshot?.name ??
            JSON.stringify(row.snapshot ?? {}).slice(0, 40),
    })),
);

const pagination = computed(() => ({
    current: props.suggestions.current_page ?? 1,
    pageSize: props.suggestions.per_page ?? 25,
    total: props.suggestions.total ?? 0,
    showSizeChanger: false,
}));

function handleTableChange(pag) {
    router.get(
        window.route("catalog.shared-product-suggestions.index"),
        {
            status: props.statusFilter,
            page: pag?.current || 1,
        },
        { preserveScroll: true, preserveState: true },
    );
}

const statusOptions = [
    { label: "Pending", value: "pending" },
    { label: "Accepted", value: "accepted" },
    { label: "Rejected", value: "rejected" },
    { label: "All", value: "all" },
];
</script>

<template>
    <AuthenticatedLayout>
        <Head title="Catalog suggestions" />
        <ContentHeader class="mb-8" title="Shared catalog suggestions" />
        <ContentLayout title="Review tenant submissions">
            <template #filters>
                <div class="flex flex-wrap gap-4 items-center">
                    <span class="text-sm text-gray-600">Status:</span>
                    <a-radio-group v-model:value="statusRadio" option-type="button" button-style="solid">
                        <a-radio-button v-for="o in statusOptions" :key="o.value" :value="o.value">
                            {{ o.label }}
                        </a-radio-button>
                    </a-radio-group>
                </div>
            </template>

            <template #table>
                <a-table
                    :columns="columns"
                    :data-source="rows"
                    :pagination="pagination"
                    row-key="id"
                    @change="handleTableChange"
                >
                    <template #bodyCell="{ column, record }">
                        <template v-if="column.key === 'author'">
                            {{
                                record.submitted_by_user?.name ||
                                record.submittedByUser?.name ||
                                "—"
                            }}
                        </template>
                        <template v-if="column.key === 'status'">
                            <a-tag
                                :color="
                                    record.status === 'accepted'
                                        ? 'green'
                                        : record.status === 'rejected'
                                          ? 'red'
                                          : 'blue'
                                "
                            >
                                {{ record.status }}
                            </a-tag>
                        </template>
                        <template v-if="column.key === 'actions'">
                            <template v-if="record.status === 'pending'">
                                <div class="flex items-center gap-2">
                                    <IconTooltipButton
                                        hover="group-hover:bg-green-500"
                                        name="Accept"
                                        @click="acceptRow(record.id)"
                                    >
                                        <IconCheck size="20" class="mx-auto" />
                                    </IconTooltipButton>
                                    <IconTooltipButton
                                        hover="group-hover:bg-red-500"
                                        name="Reject"
                                        @click="openReject(record.id)"
                                    >
                                        <IconBan size="20" class="mx-auto" />
                                    </IconTooltipButton>
                                </div>
                            </template>
                            <template v-else>
                                —
                            </template>
                        </template>
                    </template>
                </a-table>
            </template>
        </ContentLayout>

        <a-modal
            v-model:visible="rejectModalVisible"
            title="Reject suggestion"
            ok-text="Reject"
            :confirm-loading="rejectSubmitting"
            @ok="confirmReject"
        >
            <p class="text-gray-600 text-sm mb-2">
                Optionally add a reason (visible to admins when we add messaging later).
            </p>
            <a-textarea v-model:value="rejectReason" :rows="3" placeholder="Reason (optional)" />
        </a-modal>
    </AuthenticatedLayout>
</template>
