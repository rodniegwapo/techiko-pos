<script setup>
import { computed, ref } from "vue";
import {
    IconEye,
    IconEdit,
    IconTrash,
    IconCrown,
    IconWorld,
} from "@tabler/icons-vue";
import IconTooltipButton from "@/Components/buttons/IconTooltip.vue";
import { Modal, notification } from "ant-design-vue";
import { usePage } from "@inertiajs/vue3";
import axios from "axios";
import { usePermissionsV2 } from "@/Composables/usePermissionV2";
import LocationInfo from "@/Components/LocationInfo.vue";

const page = usePage();
const { hasPermission } = usePermissionsV2();

// Use permission composable
const isSuperUser = computed(
    () => usePage().props.auth?.user?.data?.is_super_user || false
);

// Props
const props = defineProps({
    users: {
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
    hierarchy: {
        type: Object,
        default: () => ({}),
    },
    isGlobalView: {
        type: Boolean,
        default: false,
    },
});

// Emits
const emit = defineEmits(["change", "edit", "view"]);

// Table columns
const columns = computed(() => {
    const baseColumns = [
        {
            title: "User",
            dataIndex: "name",
            key: "name",
        },
        {
            title: "Role(s)",
            key: "roles",
        },
        {
            title: "Status",
            key: "status",
            align: "left",
        },
        {
            title: "Location",
            key: "location",
            align: "left",
        },
        {
            title: "Created",
            dataIndex: "created_at",
            key: "created_at",
            align: "left",
        },
        {
            title: "Hierarchy",
            key: "hierarchy",
            align: "left",
        },
    ];

    // Add domain column for super users only in global view
    if (page.props.auth?.user?.data?.is_super_user && props.isGlobalView) {
        baseColumns.splice(2, 0, {
            title: "Domain",
            dataIndex: "domain",
            key: "domain",
            align: "left",
        });
    }

    baseColumns.push({
        title: "Actions",
        key: "actions",
        align: "center",
        width: "1%",
    });

    return baseColumns;
});

// Current user
const currentUser = computed(() => page.props.auth.user?.data);

// Methods
const handleChange = (pagination, filters, sorter) => {
    emit("change", pagination, filters, sorter);
};

const handleEdit = (record) => {
    // Handle data wrapping from resources
    const userData = record.data || record;

    emit("edit", userData);
};

const canEdit = (user) => {
    // Handle data wrapping from resources
    const userData = user.data || user;

    // Super user can edit anyone
    if (isSuperUser.value) {
        return true;
    }

    // Users with manage permissions can edit
    if (!hasPermission("users.update")) {
        return false;
    }

    // admin can edit users except super users
    if (
        currentUser.value.roles?.some(
            (role) => role.name.toLowerCase() === "admin"
        )
    ) {
        return !userData.is_super_user;
    }

    return false;
};

const canDelete = (user) => {
    // Handle data wrapping from resources
    const userData = user.data || user;

    // Super user can delete anyone (except themselves)
    if (isSuperUser.value) {
        return userData.id !== currentUser.value.id;
    }

    // Only users with manage permissions can delete
    if (!hasPermission("users.update")) {
        return false;
    }

    // Cannot delete yourself
    if (userData.id === currentUser.value.id) {
        return false;
    }

    // Cannot delete super users
    if (userData.is_super_user) {
        return false;
    }

    return true;
};

const handleDelete = (user) => {
    // Handle data wrapping from resources
    const userData = user.data || user;

    Modal.confirm({
        title: "Delete User",
        content: `Are you sure you want to delete ${userData.name}? This action cannot be undone.`,
        okText: "Yes, Delete",
        okType: "danger",
        cancelText: "Cancel",
        onOk: async () => {
            try {
                await axios.delete(`/api/users/${userData.id}`);
                notification.success({
                    message: "User Deleted",
                    description: `${userData.name} has been deleted successfully`,
                });
                // Refresh the page data
                window.location.reload();
            } catch (error) {
                console.error("Delete user error:", error);
                notification.error({
                    message: "Delete Failed",
                    description:
                        error.response?.data?.message ||
                        "Failed to delete user",
                });
            }
        },
    });
};

const getInitials = (name) => {
    if (!name) return "?";
    return name
        .split(" ")
        .map((word) => word.charAt(0))
        .join("")
        .toUpperCase()
        .slice(0, 2);
};

const getAvatarColor = (name) => {
    const colors = [
        "#f56565",
        "#ed8936",
        "#ecc94b",
        "#48bb78",
        "#38b2ac",
        "#4299e1",
        "#667eea",
        "#9f7aea",
        "#ed64a6",
        "#a0aec0",
    ];
    if (!name) return colors[0];
    const index = name.charCodeAt(0) % colors.length;
    return colors[index];
};

const getRoleColor = (roleName) => {
    const roleColors = {
        "Super Admin": "red",
        Admin: "orange",
        Manager: "blue",
        Cashier: "green",
    };
    return roleColors[roleName] || "default";
};

const formatDate = (date) => {
    if (!date) return "N/A";
    return new Date(date).toLocaleDateString("en-US", {
        year: "numeric",
        month: "short",
        day: "numeric",
    });
};

// Hierarchy helper methods
const getUserHierarchyLevel = (user) => {
    const userData = user.data || user;

    if (userData.is_super_user) return -1;

    if (!userData.roles || userData.roles.length === 0) return null;

    const userRole = userData.roles[0]?.name?.toLowerCase();
    if (!userRole || !props.hierarchy[userRole]) return null;

    return props.hierarchy[userRole].level;
};

const getHierarchyLevelColor = (level) => {
    if (level === -1) return "purple"; // Super user
    if (level === 1) return "red"; // Grand Manager
    if (level === 2) return "orange"; // Admin
    if (level === 3) return "blue"; // Manager
    if (level === 4) return "cyan"; // Supervisor
    if (level === 5) return "green"; // Cashier
    return "default";
};

const getSubordinatesCount = (user) => {
    const userData = user.data || user;
    // This would need to be calculated on the backend
    // For now, return 0 as we don't have this data
    return 0;
};

// Status toggle functionality
const statusLoading = ref({});

const canToggleStatus = (user) => {
    const userData = user.data || user;
    
    // Cannot toggle your own status
    if (userData.id === currentUser.value.id) {
        return false;
    }
    
    // Super user can toggle anyone
    if (isSuperUser.value) {
        return true;
    }
    
    // Users with manage permissions can toggle
    if (!hasPermission("users.update")) {
        return false;
    }
    
    // Cannot toggle super users unless you're a super user
    if (userData.is_super_user && !currentUser.value.is_super_user) {
        return false;
    }
    
    return true;
};

const handleStatusToggle = async (user) => {
    const userData = user.data || user;
    statusLoading.value[userData.id] = true;
    
    try {
        const response = await axios.patch(`/api/users/${userData.id}/toggle-status`);
        
        notification.success({
            message: "Status Updated",
            description: response.data.message,
        });
        
        // Refresh the page data
        window.location.reload();
    } catch (error) {
        console.error("Toggle status error:", error);
        notification.error({
            message: "Status Update Failed",
            description: error.response?.data?.message || "Failed to update user status",
        });
    } finally {
        statusLoading.value[userData.id] = false;
    }
};
</script>

<template>
    <a-table
        class="ant-table-striped"
        :columns="columns"
        :data-source="users"
        :row-class-name="
            (_, index) => (index % 2 === 1 ? 'bg-gray-50 group' : 'group')
        "
        :loading="loading"
        :pagination="pagination"
        row-key="id"
        @change="handleChange"
    >
        <template #bodyCell="{ column, record }">
            <template v-if="column.key === 'name'">
                <div class="flex items-center">
                    <a-avatar
                        class="mr-3"
                        :style="{
                            backgroundColor: getAvatarColor(
                                (record.data || record).name
                            ),
                        }"
                    >
                        {{ getInitials((record.data || record).name) }}
                    </a-avatar>
                    <div>
                        <div class="font-medium text-gray-900">
                            {{ (record.data || record).name }}
                        </div>
                        <div class="text-sm text-gray-500">
                            {{ (record.data || record).email }}
                        </div>
                    </div>
                </div>
            </template>

            <template v-if="column.key === 'domain'">
                <div class="flex items-center">
                    <IconWorld class="mr-1" size="16" />
                    <span class="text-sm font-medium">{{
                        (record.data || record).domain || "N/A"
                    }}</span>
                </div>
            </template>

            <template v-if="column.key === 'roles'">
                <div class="flex flex-wrap gap-1">
                    <a-tag
                        v-for="role in (record.data || record).roles"
                        :key="role.id"
                        :color="getRoleColor(role.name)"
                        class="font-medium"
                    >
                        {{ role.name }}
                    </a-tag>
                </div>
            </template>

            <template v-if="column.key === 'status'">
                <div class="flex items-center gap-2">
                    <a-switch
                        :checked="(record.data || record).status === 'active'"
                        :disabled="!canToggleStatus(record)"
                        @change="handleStatusToggle(record)"
                        :loading="statusLoading[(record.data || record).id]"
                    />
                    <a-badge
                        :status="(record.data || record).status === 'active' ? 'success' : 'error'"
                        :text="(record.data || record).status === 'active' ? 'Active' : 'Inactive'"
                    />
                </div>
            </template>

            <template v-if="column.key === 'location'">
                <div class="flex items-center">
                    <LocationInfo 
                        v-if="(record.data || record).location"
                        :location="(record.data || record).location"
                    />
                    <span v-else class="text-sm text-gray-400">No location assigned</span>
                </div>
            </template>

            <template v-if="column.key === 'created_at'">
                <div class="text-sm">
                    {{ formatDate((record.data || record).created_at) }}
                </div>
            </template>

            <template v-if="column.key === 'hierarchy'">
                <div class="text-sm">
                    <div
                        v-if="(record.data || record).is_super_user"
                        class="flex items-center gap-1"
                    >
                        <a-tag color="purple" class="font-medium">
                            <template #icon>
                                <IconCrown size="12" />
                            </template>
                            Super User
                        </a-tag>
                    </div>
                    <div v-else class="space-y-1">
                        <div
                            v-if="getUserHierarchyLevel(record) !== null"
                            class="flex items-center gap-1"
                        >
                            <a-tag
                                :color="
                                    getHierarchyLevelColor(
                                        getUserHierarchyLevel(record)
                                    )
                                "
                                class="font-medium"
                            >
                                Level {{ getUserHierarchyLevel(record) }}
                            </a-tag>
                        </div>
                        <div
                            v-if="(record.data || record).supervisor"
                            class="text-xs text-gray-500"
                        >
                            Reports to:
                            {{ (record.data || record).supervisor.name }}
                        </div>
                        <div
                            v-if="getSubordinatesCount(record) > 0"
                            class="text-xs text-gray-500"
                        >
                            Manages: {{ getSubordinatesCount(record) }} user(s)
                        </div>
                    </div>
                </div>
            </template>

            <template v-if="column.key === 'actions'">
                <div class="flex items-center gap-2">
                    <IconTooltipButton
                        hover="group-hover:bg-blue-500"
                        name="View Details"
                        @click="$emit('view', record)"
                    >
                        <IconEye size="20" class="mx-auto" />
                    </IconTooltipButton>

                    <IconTooltipButton
                        v-if="canEdit(record)"
                        hover="group-hover:bg-green-500"
                        name="Edit User"
                        @click="handleEdit(record)"
                    >
                        <IconEdit size="20" class="mx-auto" />
                    </IconTooltipButton>

                    <IconTooltipButton
                        v-if="canDelete(record)"
                        hover="group-hover:bg-red-500"
                        name="Delete User"
                        @click="handleDelete(record)"
                    >
                        <IconTrash size="20" class="mx-auto" />
                    </IconTooltipButton>
                </div>
            </template>
        </template>
    </a-table>
</template>
