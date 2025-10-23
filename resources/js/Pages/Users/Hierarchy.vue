<script setup>
import { ref, computed, onMounted, onUnmounted, watch, nextTick } from "vue";
import { Head, router, usePage } from "@inertiajs/vue3";
import {
    IconUser,
    IconUsers,
    IconUserCheck,
    IconShield,
    IconSettings,
    IconHierarchy,
    IconArrowUp,
} from "@tabler/icons-vue";

import { ArrowLeftOutlined } from "@ant-design/icons-vue";
import { useGlobalVariables } from "@/Composables/useGlobalVariable";
import { usePermissionsV2 } from "@/Composables/usePermissionV2";
import { useDomainRoutes } from "@/Composables/useDomainRoutes";
import * as d3 from "d3";
import { OrgChart } from "d3-org-chart";

import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import ContentHeader from "@/Components/ContentHeader.vue";

const { spinning } = useGlobalVariables();
const { getRoute } = useDomainRoutes();
const isSuperUser = computed(
    () => usePage().props.auth?.user?.data?.is_super_user || false
);

const props = defineProps({
    users: Array,
    hierarchy: Object,
});

const autoAssignLoading = ref(false);
const chartContainer = ref(null);
const orgChartInstance = ref(null);

const getUserRole = (user) => user.roles?.[0]?.name || "No Role";

const getUserHierarchyLevel = (user) => {
    const role = getUserRole(user);
    const hierarchy = {
        "super admin": 1,
        admin: 2,
        manager: 3,
        supervisor: 4,
        cashier: 5,
    };
    return hierarchy[role.toLowerCase()] || 6;
};

const getRoleColor = (role) => {
    const colors = {
        "super admin": "#FFFFFF", // White background
        admin: "#F8FAFC", // Very light gray
        manager: "#F1F5F9", // Light gray
        supervisor: "#E2E8F0", // Medium light gray
        cashier: "#CBD5E1", // Medium gray
    };
    return colors[role.toLowerCase()] || "#FFFFFF";
};

const getRoleIcon = (role) => {
    const icons = {
        "super admin": "👑",
        admin: "🛡️",
        manager: "👔",
        supervisor: "👥",
        cashier: "💰",
    };
    return icons[role.toLowerCase()] || "👤";
};

const getSubordinates = (userId) => {
    if (!props.users || !Array.isArray(props.users)) return [];
    return props.users.filter((user) => user.supervisor_id == userId);
};

const topLevelUsers = computed(() => {
    if (!props.users || !Array.isArray(props.users)) return [];

    const superUsers = props.users.filter((u) => u.is_super_user);
    if (superUsers.length) return superUsers;

    const admins = props.users.filter(
        (u) => getUserRole(u) === "admin" && !u.supervisor_id
    );
    if (admins.length) return admins;

    return props.users.filter((u) => !u.supervisor_id);
});

// Transform your data to D3 org chart format with role-based hierarchy
const chartData = computed(() => {
    if (!props.users || !Array.isArray(props.users) || props.users.length === 0)
        return null;

    const flatData = [];
    const currentUser = usePage().props.auth.user?.data;

    // If current user is super admin, show full hierarchy
    if (currentUser?.is_super_user) {
        // Find the top-level user (Super Admin)
        let topLevelUser = props.users.find((user) => user.is_super_user);

        if (!topLevelUser) {
            // Find the user with the highest role level
            topLevelUser = props.users.reduce((highest, user) => {
                const userLevel = getUserHierarchyLevel(user);
                const highestLevel = getUserHierarchyLevel(highest);
                return userLevel < highestLevel ? user : highest;
            });
        }

        // Build hierarchy starting from top level
        buildHierarchyData(topLevelUser, null, flatData);
    } else {
        // For non-super users, make current user the root of their hierarchy
        const currentUserInData = props.users.find(
            (user) => user.id === currentUser?.id
        );

        if (currentUserInData) {
            // Build hierarchy starting from current user
            buildHierarchyData(currentUserInData, null, flatData);
        }
    }

    return flatData;
});

const buildHierarchyData = (user, parentId, flatData) => {
    const role = getUserRole(user);
    const currentUser = usePage().props.auth.user?.data;

    flatData.push({
        id: String(user.id),
        name: user.name,
        title: role,
        email: user.email,
        status: user.status,
        isSuperUser: user.is_super_user,
        isAdmin: role === "admin",
        parentId: parentId,
        // Add visual indicators
        nodeColor: getRoleColor(role),
        nodeIcon: getRoleIcon(role),
        // Highlight current user
        isCurrentUser: user.id === currentUser?.id,
    });

    // Add all subordinates recursively
    const subordinates = getSubordinates(user.id);
    subordinates.forEach((subordinate) => {
        buildHierarchyData(subordinate, String(user.id), flatData);
    });
};

// Chart utility functions
const exportChart = () => {
    if (orgChartInstance.value) {
        try {
            orgChartInstance.value.exportImg();
        } catch (err) {}
    }
};

const resetZoom = () => {
    if (orgChartInstance.value) {
        try {
            // Try different zoom reset methods
            if (typeof orgChartInstance.value.zoomToFit === "function") {
                orgChartInstance.value.zoomToFit();
            } else if (typeof orgChartInstance.value.resetZoom === "function") {
                orgChartInstance.value.resetZoom();
            } else if (typeof orgChartInstance.value.zoom === "function") {
                orgChartInstance.value.zoom(1);
            } else {
            }
        } catch (err) {}
    }
};

const initializeChart = () => {
    if (!chartContainer.value || !chartData.value) {
        return;
    }

    // Clear previous chart
    chartContainer.value.innerHTML = "";

    try {
        orgChartInstance.value = new OrgChart()
            .container(chartContainer.value)
            .data(chartData.value)
            .nodeHeight((d) => 85)
            .nodeWidth((d) => 220)
            .childrenMargin((d) => 50)
            .compactMarginBetween((d) => 25)
            .compactMarginPair((d) => 50)
            .neighbourMargin((a, b) => 25)
            .siblingsMargin((d) => 25)
            .layout("top")
            .compact(false)
            .buttonContent(({ node, state }) => {
                return `<div style="px;color:#716E7B;border-radius:5px;padding:4px;font-size:10px;margin:auto auto;background-color:white;border: 1px solid #E4E2E9"> <span style="font-size:9px">${
                    node.children
                        ? `<i class="fas fa-angle-up"></i>`
                        : `<i class="fas fa-angle-down"></i>`
                }</span> ${node.data._directSubordinates}  </div>`;
            })
            .linkUpdate(function (d, i, arr) {
                d3.select(this)
                    .attr("stroke", (d) =>
                        d.data._upToTheRootHighlighted ? "#152785" : "#E4E2E9"
                    )
                    .attr("stroke-width", (d) =>
                        d.data._upToTheRootHighlighted ? 5 : 1
                    );

                if (d.data._upToTheRootHighlighted) {
                    d3.select(this).raise();
                }
            })
            .nodeContent(function (d, i, arr, state) {
                const node = d.data;
                const backgroundColor = node.nodeColor || "#FFFFFF";
                const statusColor =
                    node.status === "active" ? "#10B981" : "#EF4444";
                const roleIcon = node.nodeIcon || "👤";
                const isCurrentUser = node.isCurrentUser;

                // Special styling for current user
                const borderColor = isCurrentUser ? "#3B82F6" : "#E5E7EB";
                const borderWidth = isCurrentUser ? "3px" : "1px";
                const boxShadow = isCurrentUser
                    ? "0 4px 8px rgba(59, 130, 246, 0.2)"
                    : "0 2px 4px rgba(0,0,0,0.05)";
                const currentUserBadge = isCurrentUser
                    ? '<div style="position:absolute;top:-8px;right:-8px;background:#3B82F6;color:white;border-radius:50%;width:20px;height:20px;display:flex;align-items:center;justify-content:center;font-size:10px;font-weight:bold;">YOU</div>'
                    : "";

                return `
                <div style="font-family: 'Inter', sans-serif;background-color:${backgroundColor}; position:absolute;margin-top:-1px; margin-left:-1px;width:${d.width}px;height:${d.height}px;border-radius:8px;border: ${borderWidth} solid ${borderColor};box-shadow: ${boxShadow};">
                   ${currentUserBadge}
                   <div style="background-color:${backgroundColor};position:absolute;margin-top:-25px;margin-left:${15}px;border-radius:100px;width:50px;height:50px;" ></div>
                   <div style="position:absolute;margin-top:-20px;margin-left:${20}px;border-radius:100px;width:40px;height:40px;background-color:#6B7280;display:flex;align-items:center;justify-content:center;color:white;font-size:18px;font-weight:bold;border: 2px solid white;">
                       ${roleIcon}
                   </div>

                  <div style="color:#6B7280;position:absolute;right:20px;top:17px;font-size:10px;"><i class="fas fa-ellipsis-h"></i></div>
                  <div style="position:absolute;right:20px;top:35px;width:8px;height:8px;border-radius:50%;background-color:${statusColor};"></div>

                  <div style="font-size:14px;color:#1F2937;margin-left:20px;margin-top:32px;font-weight:600;"> ${
                      node.name
                  } </div>
                  <div style="color:#6B7280;margin-left:20px;margin-top:3px;font-size:11px;font-weight:500;"> ${
                      node.title
                  } </div>
                  <div style="color:#9CA3AF;margin-left:20px;margin-top:2px;font-size:9px;"> ${
                      node.email
                  } </div>

               </div>
        `;
            })
            .render();
    } catch (err) {}
};

// Watch for data changes and re-render
watch(
    chartData,
    (newData, oldData) => {
        if (newData && newData.length > 0) {
            nextTick(initializeChart);
        }
    },
    { deep: true }
);

// Also watch for changes in props.users
watch(
    () => props.users,
    (newUsers, oldUsers) => {
        if (newUsers && newUsers.length > 0) {
            nextTick(initializeChart);
        }
    },
    { deep: true }
);

onMounted(() => nextTick(initializeChart));

onUnmounted(() => {
    if (orgChartInstance.value) {
        orgChartInstance.value = null;
    }
    if (chartContainer.value) {
        chartContainer.value.innerHTML = "";
    }
});

const usersWithoutSupervisors = computed(() => {
    if (!props.users || !Array.isArray(props.users)) return [];

    return props.users.filter((user) => {
        const role = getUserRole(user);
        const isSuper = user.is_super_user;

        // Only show users who should have supervisors but don't
        // Exclude Super Admin and users who already have supervisors
        return !isSuper && !user.supervisor_id;
    });
});

const handleBackToUsers = () => router.visit(getRoute("users.index"));

const autoAssignSupervisors = async () => {
    autoAssignLoading.value = true;
    try {
        await router.post(
            getRoute("supervisors.auto-assign"),
            {},
            {
                onSuccess: (page) => {
                    // Show success message if available

                    router.reload();
                },
                onError: (errors) => {},
            }
        );
    } catch (error) {
    } finally {
        autoAssignLoading.value = false;
    }
};
</script>

<template>
    <Head title="User Hierarchy">
        <link
            href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css"
            rel="stylesheet"
        />
    </Head>
    <AuthenticatedLayout>
        <ContentHeader title="User Hierarchy" />
        <div class="max-w-7xl mx-auto p-6 space-y-6">
            <div
                class="bg-white rounded-lg shadow-sm border p-6 flex justify-between items-center"
            >
                <div class="flex items-center space-x-4">
                    <div class="p-3 bg-blue-100 rounded-lg">
                        <IconHierarchy class="w-8 h-8 text-blue-600" />
                    </div>
                    <div>
                        <h2 class="text-2xl font-bold text-gray-900">
                            User Hierarchy
                        </h2>
                        <p class="text-gray-600">
                            Supervisor-subordinate organizational structure
                        </p>
                    </div>
                </div>
                <div class="flex items-center space-x-3">
                    <a-button @click="handleBackToUsers">
                        <template #icon>
                            <ArrowLeftOutlined />
                        </template>
                        Back
                    </a-button>
                </div>
            </div>

            <!-- Stats -->
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                <div
                    class="stat-card bg-blue-50 border border-blue-300 text-blue-700"
                >
                    <div class="text-2xl font-bold">
                        {{ props.users?.length || 0 }}
                    </div>
                    <div class="text-sm font-medium">Total Users</div>
                </div>
                <div
                    class="stat-card bg-green-50 border border-green-300 text-green-700"
                >
                    <div class="text-2xl font-bold">
                        {{
                            props.users?.filter((u) => u.status === "active")
                                .length || 0
                        }}
                    </div>
                    <div class="text-sm font-medium">Active Users</div>
                </div>
                <div
                    class="stat-card bg-purple-50 border border-purple-300 text-purple-700"
                >
                    <div class="text-2xl font-bold">
                        {{ topLevelUsers?.length || 0 }}
                    </div>
                    <div class="text-sm font-medium">Top Level</div>
                </div>
                <div
                    class="stat-card bg-orange-50 border border-orange-300 text-orange-700"
                >
                    <div class="text-2xl font-bold">
                        {{ usersWithoutSupervisors?.length || 0 }}
                    </div>
                    <div class="text-sm font-medium">Without Supervisor</div>
                </div>
            </div>

            <!-- Chart -->
            <div class="bg-white rounded-lg shadow-sm border p-6">
                <div class="flex items-center justify-between mb-6">
                    <h3 class="text-lg font-semibold text-gray-900">
                        Organizational Structure
                    </h3>
                    <div class="flex gap-2" v-if="chartData">
                        <button
                            @click="exportChart"
                            class="px-3 py-1 text-sm bg-blue-100 text-blue-700 rounded hover:bg-blue-200 transition-colors"
                        >
                            Export
                        </button>
                        <button
                            @click="resetZoom"
                            class="px-3 py-1 text-sm bg-gray-100 text-gray-700 rounded hover:bg-gray-200 transition-colors"
                        >
                            Reset Zoom
                        </button>
                    </div>
                </div>

                <div
                    v-if="chartData"
                    ref="chartContainer"
                    id="d3-org-chart"
                    class="w-full min-h-[600px] border border-gray-200 rounded"
                ></div>
                <div v-else class="text-center py-12 text-gray-600">
                    <IconUsers class="w-16 h-16 text-gray-400 mx-auto mb-4" />
                    <p>No users found for hierarchy.</p>
                </div>
            </div>

            <!-- Users Without Supervisors Section -->
            <div
                v-if="usersWithoutSupervisors?.length > 0"
                class="bg-white rounded-lg shadow-sm border p-6"
            >
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-semibold text-gray-900">
                        Users Without Supervisors
                    </h3>
                    <span class="text-sm text-gray-500">
                        {{ usersWithoutSupervisors?.length || 0 }} users need
                        supervisor assignment
                    </span>
                </div>

                <div
                    class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4"
                >
                    <div
                        v-for="user in usersWithoutSupervisors"
                        :key="user.id"
                        class="bg-gray-50 rounded-lg p-4 border border-gray-200"
                    >
                        <div class="flex items-center space-x-3">
                            <div
                                class="w-10 h-10 bg-gray-300 rounded-full flex items-center justify-center"
                            >
                                <span
                                    class="text-gray-600 font-semibold text-sm"
                                >
                                    {{ user.name.charAt(0).toUpperCase() }}
                                </span>
                            </div>
                            <div class="flex-1">
                                <h4 class="font-medium text-gray-900">
                                    {{ user.name }}
                                </h4>
                                <p class="text-sm text-gray-500">
                                    {{ getUserRole(user) }}
                                </p>
                                <p class="text-xs text-gray-400">
                                    {{ user.email }}
                                </p>
                            </div>
                            <div class="flex items-center">
                                <span
                                    class="w-2 h-2 rounded-full"
                                    :class="
                                        user.status === 'active'
                                            ? 'bg-green-400'
                                            : 'bg-red-400'
                                    "
                                ></span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="mt-4 text-center">
                    <button
                        @click="autoAssignSupervisors"
                        :disabled="autoAssignLoading"
                        class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 disabled:opacity-50"
                    >
                        <IconUserCheck class="w-4 h-4 inline mr-2" />
                        {{
                            autoAssignLoading
                                ? "Assigning..."
                                : "Auto-Assign Supervisors"
                        }}
                    </button>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>

<style scoped>
.stat-card {
    @apply flex flex-col justify-center items-center p-4 rounded-lg shadow-sm border bg-white font-bold text-xl;
}

#d3-org-chart {
    width: 100%;
    height: auto;
    min-height: 600px;
    background-color: #fffeff;
    border-radius: 8px;
}

/* D3 Org Chart custom styles */
:deep(.org-chart-node) {
    cursor: pointer;
    transition: transform 0.2s ease;
}

:deep(.org-chart-node:hover) {
    transform: scale(1.05);
}

:deep(.org-chart-link) {
    stroke: lightgray;
    stroke-width: 1.5;
    stroke-dasharray: 4, 4;
    fill: none;
}
</style>
