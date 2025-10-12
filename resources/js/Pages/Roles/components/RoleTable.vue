<script setup>
import { computed } from "vue";
import { IconEye, IconEdit, IconTrash, IconShield } from "@tabler/icons-vue";
import IconTooltipButton from "@/Components/buttons/IconTooltip.vue";
import { Modal, notification } from "ant-design-vue";
import { usePage } from "@inertiajs/vue3";
import axios from "axios";
import { usePermissionsV2 } from "@/Composables/usePermissionV2";

const page = usePage();

// Use permission composable
const isSuperUser = computed(
    () => usePage().props.auth?.user?.data?.is_super_user || false
);

// Props
const props = defineProps({
    roles: {
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
        title: "Role",
        dataIndex: "name",
        key: "name",
        width: "30%",
    },
    {
        title: "Permissions",
        key: "permissions",
        width: "35%",
    },
    {
        title: "Users",
        key: "users_count",
        align: "center",
        width: "15%",
    },
    {
        title: "Created",
        dataIndex: "created_at",
        key: "created_at",
        align: "center",
        width: "15%",
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

const canEditRole = (role) => {
    return usePermissionsV2("roles.edit") || isSuperUser.value;
};

const canDeleteRole = (role) => {
    // Only super user can delete roles
    if (!usePermissionsV2("roles.destroy") && !isSuperUser.value) {
        return false;
    }

    // Prevent deletion of system roles
    const systemRoles = [
        "super admin",
        "admin",
        "manager",
        "supervisor",
        "cashier",
    ];
    if (systemRoles.includes(role.name.toLowerCase())) {
        return false;
    }

    // Prevent deletion if role has users
    if (role.users_count > 0) {
        return false;
    }

    return true;
};

const handleDelete = (role) => {
    Modal.confirm({
        title: "Delete Role",
        content: `Are you sure you want to delete the role "${role.name}"? This action cannot be undone.`,
        okText: "Yes, Delete",
        okType: "danger",
        cancelText: "Cancel",
        onOk: async () => {
            try {
                await axios.delete(route("roles.destroy", role.id));
                notification.success({
                    message: "Role Deleted",
                    description: `Role "${role.name}" has been deleted successfully`,
                });
                // Refresh the page data
                window.location.reload();
            } catch (error) {
                console.error("Delete role error:", error);
                notification.error({
                    message: "Delete Failed",
                    description:
                        error.response?.data?.message ||
                        "Failed to delete role",
                });
            }
        },
    });
};

const getRoleColor = (roleName) => {
    const roleColors = {
        "Super Admin": "#f56565",
        Admin: "#ed8936",
        Manager: "#4299e1",
        Supervisor: "#9f7aea",
        Cashier: "#48bb78",
    };
    return roleColors[roleName] || "#a0aec0";
};

const formatDate = (dateString) => {
    if (!dateString) return "-";
    const date = new Date(dateString);
    return date.toLocaleDateString("en-US", {
        year: "numeric",
        month: "short",
        day: "numeric",
    });
};

// Group permissions by module
const getGroupedPermissions = (permissions) => {
    if (!permissions) return {};
    
    const grouped = {};
    permissions.forEach(permission => {
        const moduleName = permission.module?.display_name || permission.module?.name || 'Other';
        
        if (!grouped[moduleName]) {
            grouped[moduleName] = [];
        }
        grouped[moduleName].push(permission);
    });
    
    return grouped;
};

// Extract action name from permission name (e.g., "Users - View" -> "View")
const getActionName = (permissionName) => {
    if (!permissionName) return '';
    
    // If it contains " - ", split and take the second part
    if (permissionName.includes(' - ')) {
        return permissionName.split(' - ')[1];
    }
    
    // If it contains " (", take everything before the parenthesis
    if (permissionName.includes(' (')) {
        return permissionName.split(' (')[0];
    }
    
    return permissionName;
};
</script>

<template>
    <a-table
        :columns="columns"
        :data-source="roles"
        :loading="loading"
        :pagination="pagination"
        @change="handleChange"
        row-key="id"
    >
        <template #bodyCell="{ column, record }">
            <template v-if="column.key === 'name'">
                <div class="flex items-center space-x-3">
                    <a-avatar
                        :size="32"
                        :style="{ backgroundColor: getRoleColor(record.name) }"
                    >
                        <IconShield size="16" />
                    </a-avatar>
                    <div>
                        <div class="font-medium text-gray-900">
                            {{ record.name }}
                        </div>
                        <div class="text-sm text-gray-500">
                            {{ record.permissions_count || 0 }} permissions
                        </div>
                    </div>
                </div>
            </template>

            <template v-if="column.key === 'permissions'">
                <div class="space-y-2">
                    <div
                        v-for="(modulePermissions, moduleName) in getGroupedPermissions(record.permissions)"
                        :key="moduleName"
                        class="text-xs"
                    >
                        <div class="font-semibold text-gray-800 mb-1">{{ moduleName }}</div>
                        <div class="flex flex-wrap gap-1 ml-2">
                            <a-tag
                                v-for="permission in modulePermissions.slice(0, 4)"
                                :key="permission.id"
                                size="small"
                                color="green"
                            >
                                {{ getActionName(permission.name) }}
                            </a-tag>
                            <a-tag
                                v-if="modulePermissions.length > 4"
                                size="small"
                                color="default"
                            >
                                +{{ modulePermissions.length - 4 }} more
                            </a-tag>
                        </div>
                    </div>
                </div>
            </template>

            <template v-if="column.key === 'users_count'">
                <a-badge
                    :count="record.users_count || 0"
                    :number-style="{ backgroundColor: '#52c41a' }"
                />
            </template>

            <template v-if="column.key === 'created_at'">
                <div class="text-sm text-gray-600">
                    {{ formatDate(record.created_at) }}
                </div>
            </template>

            <template v-if="column.key === 'actions'">
                <div class="flex items-center space-x-1">
                    <IconTooltipButton
                        hover="group-hover:bg-blue-500"
                        name="View Role Details"
                        @click="$emit('view', record)"
                    >
                        <IconEye size="20" class="mx-auto" />
                    </IconTooltipButton>

                    <IconTooltipButton
                        v-if="canEditRole(record)"
                        hover="group-hover:bg-green-500"
                        name="Edit Role"
                        @click="$emit('edit', record)"
                    >
                        <IconEdit size="20" class="mx-auto" />
                    </IconTooltipButton>

                    <IconTooltipButton
                        v-if="canDeleteRole(record)"
                        hover="group-hover:bg-red-500"
                        name="Delete Role"
                        @click="handleDelete(record)"
                    >
                        <IconTrash size="20" class="mx-auto" />
                    </IconTooltipButton>
                </div>
            </template>
        </template>
    </a-table>
</template>
