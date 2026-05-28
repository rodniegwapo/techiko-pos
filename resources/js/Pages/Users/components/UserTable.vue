<script setup>
import { computed, ref } from "vue";
import { useMediaQuery } from "@vueuse/core";
import {
    IconEye,
    IconEdit,
    IconTrash,
    IconCrown,
    IconWorld,
    IconUserCheck,
    IconKey,
} from "@tabler/icons-vue";
import IconTooltipButton from "@/Components/buttons/IconTooltip.vue";
import { Modal, notification } from "ant-design-vue";
import { usePage, router } from "@inertiajs/vue3";
import axios from "axios";
import { usePermissionsV2 } from "@/Composables/usePermissionV2";
import LocationInfo from "@/Components/LocationInfo.vue";

const page = usePage();
const { hasPermission } = usePermissionsV2();
const isMdUp = useMediaQuery("(min-width: 768px)");

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
const emit = defineEmits(["change", "edit", "view", "set-pin"]);

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

// Current user (auth may not be hydrated on first paint)
const currentUser = computed(() => page.props.auth?.user?.data ?? null);

const showSuperUserDomain = computed(
    () =>
        page.props.auth?.user?.data?.is_super_user && props.isGlobalView,
);

const unwrapUser = (record) => record.data || record;

// Methods
const handleChange = (pagination, filters, sorter) => {
    emit("change", pagination, filters, sorter);
};

const handleEdit = (record) => {
    // Handle data wrapping from resources
    const userData = record.data || record;

    emit("edit", userData);
};

const canSetPin = (user) => {
    const userData = user.data || user;
    if (!hasPermission("users.pin.update")) {
        return false;
    }
    if (userData.is_super_user) {
        return false;
    }
    if (isSuperUser.value) {
        return true;
    }
    const actor = currentUser.value;
    if (!actor) {
        return false;
    }
    const actorRoles =
        actor.roles?.map((r) => r.name.toLowerCase()) || [];
    const targetRoles =
        userData.roles?.map((r) => r.name.toLowerCase()) || [];
    const targetHasSuperAdminRole = targetRoles.includes("super admin");
    if (actorRoles.includes("admin")) {
        return !targetHasSuperAdminRole;
    }
    if (actorRoles.includes("super admin")) {
        return !targetHasSuperAdminRole;
    }
    return false;
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
        currentUser.value?.roles?.some(
            (role) => role.name.toLowerCase() === "admin",
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
        const actor = currentUser.value;
        if (!actor) {
            return false;
        }
        return userData.id !== actor.id;
    }

    // Only users with manage permissions can delete
    if (!hasPermission("users.update")) {
        return false;
    }

    // Cannot delete yourself
    if (userData.id === currentUser.value?.id) {
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
    const actor = currentUser.value;

    // Cannot toggle your own status
    if (actor && userData.id === actor.id) {
        return false;
    }

    // Super user can toggle anyone
    if (isSuperUser.value) {
        return true;
    }

    if (!actor) {
        return false;
    }

    // Users with manage permissions can toggle
    if (!hasPermission("users.update")) {
        return false;
    }

    // Cannot toggle super users unless you're a super user
    if (userData.is_super_user && !actor.is_super_user) {
        return false;
    }

    return true;
};

const handleStatusToggle = async (user) => {
    const userData = user.data || user;
    statusLoading.value[userData.id] = true;

    try {
        const response = await axios.patch(
            `/api/users/${userData.id}/toggle-status`
        );

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
            description:
                error.response?.data?.message || "Failed to update user status",
        });
    } finally {
        statusLoading.value[userData.id] = false;
    }
};

// Impersonation functionality
const canImpersonate = (user) => {
    const userData = user.data || user;

    // Only super users can impersonate
    if (!isSuperUser.value) {
        return false;
    }

    if (!currentUser.value) {
        return false;
    }

    // Cannot impersonate yourself
    if (userData.id === currentUser.value.id) {
        return false;
    }

    // Cannot impersonate other super users
    if (userData.is_super_user) {
        return false;
    }

    return true;
};

const handleImpersonate = (user) => {
    const userData = user.data || user;

    Modal.confirm({
        title: "Impersonate User",
        content: `Are you sure you want to impersonate ${userData.name}? You will be logged in as this user and can perform actions on their behalf.`,
        okText: "Yes, Impersonate",
        okType: "primary",
        cancelText: "Cancel",
        onOk: () => {
            router.post(
                `/impersonate/${userData.id}`,
                {},
                {
                    preserveState: false,
                    preserveScroll: false,
                    onSuccess: () => {
                        notification.success({
                            message: "Impersonation Started",
                            description: `You are now logged in as ${userData.name}`,
                        });
                    },
                    onError: (errors) => {
                        notification.error({
                            message: "Impersonation Failed",
                            description:
                                errors.message || "Failed to impersonate user",
                        });
                    },
                }
            );
        },
    });
};

function onMobilePaginationChange(pageNum) {
    emit("change", {
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
                        :status="
                            (record.data || record).status === 'active'
                                ? 'success'
                                : 'error'
                        "
                        :text="
                            (record.data || record).status === 'active'
                                ? 'Active'
                                : 'Inactive'
                        "
                    />
                </div>
            </template>

            <template v-if="column.key === 'location'">
                <div class="flex items-center">
                    <LocationInfo
                        v-if="(record.data || record).location"
                        :location="(record.data || record).location"
                    />
                    <span v-else class="text-sm text-gray-400"
                        >No location assigned</span
                    >
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
                        v-if="canSetPin(record)"
                        hover="group-hover:bg-amber-500"
                        name="Set PIN"
                        @click="$emit('set-pin', record)"
                    >
                        <IconKey size="20" class="mx-auto" />
                    </IconTooltipButton>

                    <IconTooltipButton
                        v-if="canImpersonate(record)"
                        hover="group-hover:bg-purple-500"
                        name="Impersonate User"
                        @click="handleImpersonate(record)"
                    >
                        <IconUserCheck size="20" class="mx-auto" />
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

    <div v-else class="px-2 py-2 md:px-0">
        <a-spin :spinning="loading">
            <div
                v-if="!users.length"
                class="py-12 text-center text-sm text-gray-500"
            >
                No users found
            </div>
            <div v-else class="flex flex-col gap-3">
                <div
                    v-for="record in users"
                    :key="unwrapUser(record).id"
                    class="overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm"
                >
                    <div class="flex gap-3 px-4 py-3">
                        <a-avatar
                            class="shrink-0"
                            :style="{
                                backgroundColor: getAvatarColor(
                                    unwrapUser(record).name,
                                ),
                            }"
                        >
                            {{ getInitials(unwrapUser(record).name) }}
                        </a-avatar>
                        <div class="min-w-0 flex-1">
                            <div
                                class="truncate text-base font-semibold text-gray-900"
                            >
                                {{ unwrapUser(record).name }}
                            </div>
                            <div class="mt-1 truncate text-sm text-gray-600">
                                {{ unwrapUser(record).email }}
                            </div>
                            <div class="mt-2 flex flex-wrap gap-1">
                                <a-tag
                                    v-for="role in unwrapUser(record).roles"
                                    :key="role.id"
                                    :color="getRoleColor(role.name)"
                                    class="m-0 font-medium"
                                >
                                    {{ role.name }}
                                </a-tag>
                            </div>
                        </div>
                    </div>

                    <div class="mx-4 mb-3 rounded-lg bg-gray-50 p-3">
                        <div
                            class="grid grid-cols-[auto_1fr] gap-x-3 gap-y-2 text-sm"
                        >
                            <span class="text-gray-500">Status</span>
                            <div
                                class="flex items-center justify-end gap-2"
                            >
                                <a-switch
                                    :checked="
                                        unwrapUser(record).status === 'active'
                                    "
                                    :disabled="!canToggleStatus(record)"
                                    size="small"
                                    @change="handleStatusToggle(record)"
                                    :loading="
                                        statusLoading[unwrapUser(record).id]
                                    "
                                />
                                <a-badge
                                    :status="
                                        unwrapUser(record).status === 'active'
                                            ? 'success'
                                            : 'error'
                                    "
                                    :text="
                                        unwrapUser(record).status === 'active'
                                            ? 'Active'
                                            : 'Inactive'
                                    "
                                />
                            </div>
                            <template v-if="showSuperUserDomain">
                                <span class="text-gray-500">Domain</span>
                                <span
                                    class="flex min-w-0 items-center justify-end gap-1 truncate font-medium text-gray-900"
                                >
                                    <IconWorld size="16" class="shrink-0" />
                                    {{ unwrapUser(record).domain || "N/A" }}
                                </span>
                            </template>
                        </div>
                    </div>

                    <div class="border-t border-gray-100 px-4 py-3">
                        <div class="flex flex-col gap-2">
                            <a-button
                                class="flex items-center justify-center gap-2"
                                @click="$emit('view', record)"
                            >
                                <template #icon>
                                    <IconEye size="18" />
                                </template>
                                View details
                            </a-button>
                            <a-button
                                v-if="canEdit(record)"
                                class="flex items-center justify-center gap-2"
                                @click="handleEdit(record)"
                            >
                                <template #icon>
                                    <IconEdit size="18" />
                                </template>
                                Edit user
                            </a-button>
                            <a-button
                                v-if="canSetPin(record)"
                                class="flex items-center justify-center gap-2"
                                @click="$emit('set-pin', record)"
                            >
                                <template #icon>
                                    <IconKey size="18" />
                                </template>
                                Set PIN
                            </a-button>
                            <a-button
                                v-if="canImpersonate(record)"
                                class="flex items-center justify-center gap-2"
                                @click="handleImpersonate(record)"
                            >
                                <template #icon>
                                    <IconUserCheck size="18" />
                                </template>
                                Impersonate
                            </a-button>
                            <a-button
                                v-if="canDelete(record)"
                                danger
                                class="flex items-center justify-center gap-2"
                                @click="handleDelete(record)"
                            >
                                <template #icon>
                                    <IconTrash size="18" />
                                </template>
                                Delete user
                            </a-button>
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
