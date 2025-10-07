<script setup>
import { computed } from "vue";
import { IconEye, IconEdit, IconTrash, IconShield } from "@tabler/icons-vue";
import IconTooltipButton from "@/Components/buttons/IconTooltip.vue";
import { Modal, notification } from "ant-design-vue";
import { usePage } from "@inertiajs/vue3";
import axios from "axios";
import { usePermissions } from "@/Composables/usePermissions";

const page = usePage();

// Use permission composable
const { canManageRoles, isSuperUser } = usePermissions();

// Props
const props = defineProps({
    permissions: {
        type: Array,
        required: true,
    },
    loading: {
        type: Boolean,
        default: false,
    },
    pagination: {
        type: Object,
        default: () => ({}),
    },
    canEdit: {
        type: Boolean,
        default: false,
    },
    canDelete: {
        type: Boolean,
        default: false,
    },
});

// Emits
const emit = defineEmits(["change", "edit", "view"]);

// Table columns
const columns = [
    {
        title: "Permission",
        dataIndex: "name",
        key: "name",
        width: "40%",
    },
    {
        title: "Module",
        dataIndex: "module",
        key: "module",
        width: "20%",
    },
    {
        title: "Action",
        dataIndex: "action",
        key: "action",
        width: "15%",
    },
    {
        title: "Roles",
        key: "roles_count",
        align: "center",
        width: "10%",
    },
    {
        title: "Created",
        dataIndex: "created_at",
        key: "created_at",
        align: "center",
        width: "10%",
    },
    {
        title: "Actions",
        key: "actions",
        align: "center",
        width: "5%",
    },
];

// Current user
const currentUser = computed(() => page.props.auth.user?.data);

// Methods
const handleChange = (pagination, filters, sorter) => {
    emit("change", pagination, filters, sorter);
};

const canViewPermission = (permission) => {
    return canManageRoles.value || isSuperUser.value;
};

const canEditPermission = (permission) => {
    return canManageRoles.value || isSuperUser.value;
};

const canDeletePermission = (permission) => {
    return canManageRoles.value || isSuperUser.value;
};

const handleEdit = (permission) => {
    emit("edit", permission);
};

const handleView = (permission) => {
    emit("view", permission);
};

const handleDelete = (permission) => {
    Modal.confirm({
        title: "Delete Permission",
        content: `Are you sure you want to delete the permission "${permission.name}"? This action cannot be undone.`,
        okText: "Delete",
        okType: "danger",
        cancelText: "Cancel",
        onOk() {
            axios
                .delete(route("permissions.destroy", permission.id))
                .then(() => {
                    notification.success({
                        message: "Success",
                        description: "Permission deleted successfully.",
                    });
                    // Reload the page to refresh the data
                    window.location.reload();
                })
                .catch((error) => {
                    console.error("Delete error:", error);
                    notification.error({
                        message: "Error",
                        description: error.response?.data?.message || "Failed to delete permission.",
                    });
                });
        },
    });
};

// Format date
const formatDate = (date) => {
    if (!date) return "-";
    return new Date(date).toLocaleDateString();
};
</script>

<template>
    <a-table
        :columns="columns"
        :data-source="permissions"
        :loading="loading"
        :pagination="pagination"
        :scroll="{ x: 800 }"
        @change="handleChange"
        row-key="id"
    >
        <!-- Permission Name -->
        <template #bodyCell="{ column, record }">
            <template v-if="column.key === 'name'">
                <div class="flex items-center gap-2">
                    <IconShield class="text-blue-500" size="16" />
                    <span class="font-medium text-gray-900">{{ record.name }}</span>
                </div>
            </template>

            <!-- Module -->
            <template v-else-if="column.key === 'module'">
                <a-tag color="blue" class="capitalize">
                    {{ record.module }}
                </a-tag>
            </template>

            <!-- Action -->
            <template v-else-if="column.key === 'action'">
                <a-tag color="green" class="capitalize">
                    {{ record.action }}
                </a-tag>
            </template>

            <!-- Roles Count -->
            <template v-else-if="column.key === 'roles_count'">
                <a-badge
                    :count="record.roles_count || 0"
                    :number-style="{ backgroundColor: '#52c41a' }"
                />
            </template>

            <!-- Created Date -->
            <template v-else-if="column.key === 'created_at'">
                <span class="text-gray-600">{{ formatDate(record.created_at) }}</span>
            </template>

            <!-- Actions -->
            <template v-else-if="column.key === 'actions'">
                <div class="flex items-center justify-center gap-1">
                    <IconTooltipButton
                        v-if="canViewPermission(record)"
                        @click="handleView(record)"
                        icon="eye"
                        tooltip="View Permission"
                        type="primary"
                        size="small"
                    />
                    <IconTooltipButton
                        v-if="canEditPermission(record)"
                        @click="handleEdit(record)"
                        icon="edit"
                        tooltip="Edit Permission"
                        type="default"
                        size="small"
                    />
                    <IconTooltipButton
                        v-if="canDeletePermission(record)"
                        @click="handleDelete(record)"
                        icon="delete"
                        tooltip="Delete Permission"
                        type="danger"
                        size="small"
                    />
                </div>
            </template>
        </template>
    </a-table>
</template>
