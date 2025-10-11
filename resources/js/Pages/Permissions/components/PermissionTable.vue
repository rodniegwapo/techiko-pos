<script setup>
import { computed } from "vue";
import { IconEye, IconEdit, IconTrash, IconShield, IconPower, IconX } from "@tabler/icons-vue";
import IconTooltipButton from "@/Components/buttons/IconTooltip.vue";
import { usePage } from "@inertiajs/vue3";
import { usePermissionsV2 } from "@/Composables/usePermissionV2";

const page = usePage();

// Use permission composable
const isSuperUser = computed(() => usePage().props.auth?.user?.data?.is_super_user || false);

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
const emit = defineEmits(["change", "edit", "view", "deactivate", "activate"]);

// Table columns
const columns = [
    {
        title: "Permission",
        dataIndex: "name",
        key: "name",
        width: "35%",
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
        title: "Status",
        key: "status",
        align: "center",
        width: "10%",
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
    return usePermissionsV2('permissions.update') || isSuperUser.value;
};

const canEditPermission = (permission) => {
    return usePermissionsV2('permissions.update') || isSuperUser.value;
};

const canDeactivatePermission = (permission) => {
    return (usePermissionsV2('permissions.deactivate') || isSuperUser.value) && permission.is_active;
};

const canActivatePermission = (permission) => {
    return (usePermissionsV2('permissions.activate') || isSuperUser.value) && !permission.is_active;
};

const handleEdit = (permission) => {
    emit("edit", permission);
};

const handleView = (permission) => {
    emit("view", permission);
};

const handleDeactivate = (permission) => {
    emit("deactivate", permission);
};

const handleActivate = (permission) => {
    emit("activate", permission);
};

// Format date
const formatDate = (dateString) => {
    if (!dateString) return "N/A";
    const date = new Date(dateString);
    return date.toLocaleDateString("en-US", {
        year: "numeric",
        month: "short",
        day: "numeric",
    });
};
</script>

<template>
    <a-table
        class="ant-table-striped"
        :columns="columns"
        :data-source="permissions"
        :row-class-name="
            (_, index) => (index % 2 === 1 ? 'bg-gray-50 group' : 'group')
        "
        :loading="loading"
        :pagination="pagination"
        @change="handleChange"
        row-key="id"
    >
        <!-- Permission Name -->
        <template #bodyCell="{ column, record }">
            <template v-if="column.key === 'name'">
                <div class="flex items-center space-x-3">
                    <a-avatar
                        :size="32"
                        :style="{ backgroundColor: '#4299e1' }"
                    >
                        <IconShield size="16" />
                    </a-avatar>
                    <div>
                        <div class="font-medium text-gray-900">
                            {{ record.name }}
                        </div>
                        <div class="text-sm text-gray-500">
                            {{ record.description || 'No description' }}
                        </div>
                    </div>
                </div>
            </template>

            <!-- Module -->
            <template v-else-if="column.key === 'module'">
                <a-tag color="blue" class="capitalize w-fit">
                    {{ record.module }}
                </a-tag>
            </template>

            <!-- Action -->
            <template v-else-if="column.key === 'action'">
                <a-tag color="green" class="capitalize w-fit">
                    {{ record.action }}
                </a-tag>
            </template>

            <!-- Status -->
            <template v-else-if="column.key === 'status'">
                <a-tag :color="record.is_active ? 'green' : 'red'" class="w-fit">
                    {{ record.is_active ? 'Active' : 'Inactive' }}
                </a-tag>
            </template>

            <!-- Roles Count -->
            <template v-else-if="column.key === 'roles_count'">
                <a-badge
                    :count="record.roles_count || 0"
                    :number-style="{ backgroundColor: '#52c41a' }"
                    class="w-fit"
                />
            </template>

            <!-- Created Date -->
            <template v-else-if="column.key === 'created_at'">
                <div class="text-sm text-gray-600">
                    {{ formatDate(record.created_at) }}
                </div>
            </template>

            <!-- Actions -->
            <template v-else-if="column.key === 'actions'">
                <div class="flex items-center gap-2">
                    <IconTooltipButton
                        v-if="canViewPermission(record)"
                        hover="group-hover:bg-blue-500"
                        name="View Permission Details"
                        @click="handleView(record)"
                    >
                        <IconEye size="20" class="mx-auto" />
                    </IconTooltipButton>

                    <IconTooltipButton
                        v-if="canEditPermission(record)"
                        hover="group-hover:bg-green-500"
                        name="Edit Permission"
                        @click="handleEdit(record)"
                    >
                        <IconEdit size="20" class="mx-auto" />
                    </IconTooltipButton>

                    <IconTooltipButton
                        v-if="canDeactivatePermission(record)"
                        hover="group-hover:bg-orange-500"
                        name="Deactivate Permission"
                        @click="handleDeactivate(record)"
                    >
                        <IconX size="20" class="mx-auto" />
                    </IconTooltipButton>

                    <IconTooltipButton
                        v-if="canActivatePermission(record)"
                        hover="group-hover:bg-green-500"
                        name="Activate Permission"
                        @click="handleActivate(record)"
                    >
                        <IconPower size="20" class="mx-auto" />
                    </IconTooltipButton>
                </div>
            </template>
        </template>
    </a-table>
</template>
